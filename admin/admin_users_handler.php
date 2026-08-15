<?php
// ============================================================
//  Dievon – Admin: Staff Account Handler
//
//  Every check here is repeated server-side even where the page already
//  disables the control. A disabled <select> is a courtesy to the person using
//  the screen, not a security boundary — the request can be replayed by hand.
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

function auFail(string $message, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if (empty($_SESSION['admin_logged_in'])) { auFail('Please sign in again.', 401); }
if (($_SESSION['admin_role'] ?? 'owner') !== 'owner') {
    auFail('Only the shop owner can manage staff accounts.', 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { auFail('Invalid request method.', 405); }
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    auFail('Your session has expired. Please refresh the page and try again.', 419);
}

$action = $_POST['action'] ?? '';
$myId   = (int)($_SESSION['admin_id'] ?? 0);
$VALID_ROLES = ['owner', 'catalogue', 'orders'];

/**
 * Would this change leave the shop with no active owner?
 *
 * Called before every demotion and deactivation. Without it, an owner could
 * demote the last owner — including themselves via a replayed request — and
 * nobody would be able to appoint a replacement, because appointing requires
 * being an owner. The only fix would be editing the database by hand.
 */
function wouldLeaveNoOwner(PDO $pdo, int $excludeId, string $newRole, int $newActive): bool {
    $others = (int)$pdo->query(
        "SELECT COUNT(*) FROM admin_users WHERE role = 'owner' AND is_active = 1 AND id <> " . (int)$excludeId
    )->fetchColumn();
    $thisOneStillOwner = ($newRole === 'owner' && $newActive === 1) ? 1 : 0;
    return ($others + $thisOneStillOwner) === 0;
}

try {
    // ── Save roles and active flags ─────────────────────────
    if ($action === 'save_roles') {
        $rows = json_decode((string)($_POST['rows'] ?? '[]'), true);
        if (!is_array($rows)) { auFail('Nothing to save.'); }

        $pdo->beginTransaction();
        $changed = 0;

        foreach ($rows as $r) {
            $id     = (int)($r['id'] ?? 0);
            $role   = in_array($r['role'] ?? '', $VALID_ROLES, true) ? $r['role'] : 'orders';
            $active = !empty($r['is_active']) ? 1 : 0;

            if ($id <= 0) { continue; }

            // You may not change your own role or switch yourself off, however
            // the request was constructed.
            if ($id === $myId) { continue; }

            if (wouldLeaveNoOwner($pdo, $id, $role, $active)) {
                $pdo->rollBack();
                auFail('That would leave the shop with no active owner. Appoint another owner first.');
            }

            // role_id moves with role. admin_users carries BOTH a legacy `role`
            // slug and a `role_id` into admin_roles, and this handler used to set
            // only the first while the Permissions panel set only the second. The
            // two then disagreed, so the row dropdown could read "Order staff"
            // while the panel below it read a different role for the same person,
            // and each screen showed whichever field its own code path used.
            // The subselect leaves role_id untouched if no row matches the slug.
            $pdo->prepare("UPDATE admin_users
                              SET role = :role,
                                  is_active = :active,
                                  role_id = COALESCE(
                                      (SELECT id FROM admin_roles WHERE slug = :role_slug LIMIT 1),
                                      role_id
                                  )
                            WHERE id = :id")
                ->execute([':role' => $role, ':role_slug' => $role, ':active' => $active, ':id' => $id]);
            $changed++;
        }

        $pdo->commit();
        logAdminAction($myId ?: 1, 'admin_users_save_roles', "Updated {$changed} staff account(s)");
        echo json_encode(['success' => true, 'message' => "Saved {$changed} account(s)."]);
        exit;
    }

    // ── Add a staff account ─────────────────────────────────
    if ($action === 'add_user') {
        $username = trim((string)($_POST['username'] ?? ''));
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        // Optional, but validated when given: an address that is not an address
        // cannot receive a sign-in code, and finding that out at sign-in time is
        // the worst possible moment.
        $emailAddr = trim((string)($_POST['email'] ?? ''));
        if ($emailAddr !== '' && !filter_var($emailAddr, FILTER_VALIDATE_EMAIL)) {
            auFail('That email address does not look right. Leave it blank if you do not have one yet.');
        }

        // The dropdown offers built-in roles by slug and custom roles as
        // "role:<id>", so one control covers both. A custom pick still writes a
        // built-in value into the legacy role column, because that column is NOT
        // NULL; 'orders' is used as the least-privileged of the three.
        //
        // That fallback is never silently reached: roles_handler.php refuses to
        // delete a role while anyone holds it, precisely because the orders
        // preset can be WIDER than the custom role it would replace.
        $rawRole = (string)($_POST['role'] ?? '');
        $role    = in_array($rawRole, $VALID_ROLES, true) ? $rawRole : 'orders';
        $roleId  = null;

        if (str_starts_with($rawRole, 'role:')) {
            $wantId = (int)substr($rawRole, 5);
            try {
                $rc = $pdo->prepare("SELECT id, slug, is_system FROM admin_roles WHERE id = :id");
                $rc->execute([':id' => $wantId]);
                $rRow = $rc->fetch();
                // Refuse the owner role from this control. Promotion to owner is a
                // deliberate act on the roles column, not a side effect of picking
                // an entry in a create-account dropdown.
                if ($rRow && $rRow['slug'] !== 'owner') {
                    $roleId = (int)$rRow['id'];
                    $role   = 'orders';
                }
            } catch (PDOException $eRole) {
                // admin_roles not migrated yet — fall through on the built-in role.
                $roleId = null;
            }
        }

        if ($username === '')                       { auFail('Please enter a username.'); }
        if (!preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username)) {
            auFail('Usernames may use letters, numbers, dots, dashes and underscores only (3–80 characters).');
        }

        // Staff hold more power than customers, so they get the same password
        // rule — not a weaker one.
        $pwError = validatePasswordStrength($password, '', $fullName);
        if ($pwError !== null) { auFail($pwError); }

        $dupe = $pdo->prepare("SELECT id FROM admin_users WHERE username = :u");
        $dupe->execute([':u' => $username]);
        if ($dupe->fetchColumn()) { auFail('That username is already taken.'); }

        // role_id is written in a second statement rather than in the INSERT, so
        // this still works on an install where update_new_database.php has not
        // been run and the column does not exist yet — the account is created
        // either way and simply keeps its built-in role.
        $pdo->prepare(
            "INSERT INTO admin_users (username, password_hash, full_name, email, role, is_active, must_change_password)
             VALUES (:u, :h, :n, :e, :r, 1, 0)"
        )->execute([
            ':u' => $username,
            ':h' => password_hash($password, PASSWORD_DEFAULT),
            ':n' => $fullName !== '' ? $fullName : null,
            ':e' => $emailAddr !== '' ? $emailAddr : null,
            ':r' => $role,
        ]);

        $newId    = (int)$pdo->lastInsertId();
        $roleNote = $role;
        if ($roleId !== null && $newId > 0) {
            try {
                $pdo->prepare("UPDATE admin_users SET role_id = :rid WHERE id = :id")
                    ->execute([':rid' => $roleId, ':id' => $newId]);
                $rn = $pdo->prepare("SELECT name FROM admin_roles WHERE id = :id");
                $rn->execute([':id' => $roleId]);
                $roleNote = (string)$rn->fetchColumn();
            } catch (PDOException $eAssign) {
                error_log('add_user role_id assign: ' . $eAssign->getMessage());
            }
        }

        logAdminAction($myId ?: 1, 'admin_user_created', "Created staff account {$username} ({$roleNote})");
        echo json_encode(['success' => true, 'message' => "Account “{$username}” created as {$roleNote}."]);
        exit;
    }

    // ── Set a password ──────────────────────────────────────
    // ── Delete a staff account permanently ──────────────────
    //
    // Requested explicitly. It is the one destructive action on this screen, so
    // it carries the same three guards as a deactivation plus a record of itself:
    //
    //   • not your own row      — you would lock yourself out
    //   • not the last owner    — appointing a replacement requires being an
    //                             owner, so the shop would be unrecoverable
    //   • logged BEFORE the row goes, and the username is written into the log
    //     text. admin_audit_logs stores admin_id as a plain int with no foreign
    //     key, so every past action by this person keeps its id and loses its
    //     name. Naming them in this one entry is what lets you still work out
    //     who the orphaned ids belonged to.
    if ($action === 'delete_user') {
        $targetId = (int)($_POST['id'] ?? 0);
        if ($targetId <= 0)        { auFail('No account selected.'); }
        if ($targetId === $myId)   { auFail('You cannot delete your own account.'); }

        $row = $pdo->prepare("SELECT username, role, is_active FROM admin_users WHERE id = :id");
        $row->execute([':id' => $targetId]);
        $target = $row->fetch(PDO::FETCH_ASSOC);
        if (!$target) { auFail('That account no longer exists.'); }

        // Removing the row is a demotion to "not an owner at all".
        if (wouldLeaveNoOwner($pdo, $targetId, 'deleted', 0)) {
            auFail('That is the last active owner. Appoint another owner first, '
                 . 'or the shop would be left with no way to manage itself.');
        }

        logAdminAction($myId ?: 1, 'admin_users_delete',
            "Deleted staff account #{$targetId} ({$target['username']}, role {$target['role']}). "
          . "Past audit entries for admin_id {$targetId} refer to this person.");

        // Per-person overrides MUST go with the account. MySQL reissues deleted
        // ids, so leaving them behind means the next staff member created could
        // land on this id and silently inherit this person's extra permissions.
        // The same id reuse produced a wrong SKU and a wrong price elsewhere in
        // this shop; here it would hand out access instead.
        try {
            $pdo->prepare("DELETE FROM admin_user_capabilities WHERE admin_id = :id")
                ->execute([':id' => $targetId]);
        } catch (PDOException $e) { /* table arrives with update_new_database.php */ }

        $pdo->prepare("DELETE FROM admin_users WHERE id = :id")->execute([':id' => $targetId]);

        echo json_encode(['success' => true, 'message' => 'Staff account deleted.']);
        exit;
    }

    if ($action === 'set_password') {
        $id       = (int)($_POST['id'] ?? 0);
        $password = (string)($_POST['password'] ?? '');
        if ($id <= 0) { auFail('Invalid account.'); }

        $u = $pdo->prepare("SELECT username, full_name FROM admin_users WHERE id = :id");
        $u->execute([':id' => $id]);
        $row = $u->fetch(PDO::FETCH_ASSOC);
        if (!$row) { auFail('That account no longer exists.'); }

        $pwError = validatePasswordStrength($password, '', (string)($row['full_name'] ?? ''));
        if ($pwError !== null) { auFail($pwError); }

        // must_change_password is cleared here — this IS the change it was
        // asking for.
        // Also clears the per-session flag, so the gate in requireAdminCapability()
        // releases straight away instead of on the next sign-in.
        if ((int)$id === (int)($_SESSION['admin_id'] ?? 0)) { $_SESSION['admin_pw_change_done'] = 1; }
        $pdo->prepare("UPDATE admin_users SET password_hash = :h, must_change_password = 0 WHERE id = :id")
            ->execute([':h' => password_hash($password, PASSWORD_DEFAULT), ':id' => $id]);

        logAdminAction($myId ?: 1, 'admin_password_changed', "Password changed for {$row['username']}");
        echo json_encode(['success' => true, 'message' => "Password updated for “{$row['username']}”."]);
        exit;
    }

    // ── Save your own email address ─────────────────────────
    // Own account only. The owner account predates the email field and has
    // none — without a saved address, forgot_password.php has nowhere to send
    // the reset code. This is the one place that gap is closed: the account's
    // own row in the table has no email input, by design, so this lives on the
    // Two-step sign-in panel where the address is actually used.
    if ($action === 'save_my_email') {
        $email = trim((string)($_POST['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            auFail('That email address does not look right.');
        }

        // One address per person, so a reset request can never be ambiguous
        // about whose code landed where.
        if ($email !== '') {
            $dup = $pdo->prepare("SELECT id FROM admin_users WHERE LOWER(email) = LOWER(:e) AND id <> :me");
            $dup->execute([':e' => $email, ':me' => $myId]);
            if ($dup->fetchColumn()) {
                auFail('Another account already uses that email address.');
            }
        }

        // email_verified_at is cleared on every change. The codebase's rule is
        // that an address is only trusted once a code sent to it has been typed
        // back in — so switching the address also un-verifies it, and emailed
        // sign-in codes will only start flowing again after the re-verify step
        // in the Two-step panel proves the new inbox is actually read.
        $pdo->prepare("UPDATE admin_users SET email = :e, email_verified_at = NULL WHERE id = :id")
            ->execute([':e' => $email !== '' ? $email : null, ':id' => $myId]);

        logAdminAction($myId ?: 1, 'admin_email_updated', 'Updated own account email address');
        echo json_encode(['success' => true, 'message' => $email !== ''
            ? 'Email saved. Password-reset and sign-in codes will go to ' . maskEmailAddress($email) . '.'
            : 'Email removed.']);
        exit;
    }

    // ── Two-step sign-in by email ───────────────────────────
    // Same rule as the app actions below: own account only.
    //
    // Two steps on purpose. "email_2fa_begin" sends a code and turns nothing on;
    // "email_2fa_confirm" only enables the method once that code comes back. So
    // an address with a typo in it fails here, on a screen you are already
    // looking at, rather than the next time you try to sign in.
    if ($action === 'email_2fa_begin' || $action === 'email_2fa_confirm') {
        if ($myId <= 0) { auFail('This action needs a real staff account.'); }

        $me = $pdo->prepare("SELECT id, username, full_name, email FROM admin_users WHERE id = :id");
        $me->execute([':id' => $myId]);
        $meRow = $me->fetch(PDO::FETCH_ASSOC);
        if (!$meRow) { auFail('Your account no longer exists.'); }

        $addr = trim((string)($meRow['email'] ?? ''));
        if ($addr === '' || !filter_var($addr, FILTER_VALIDATE_EMAIL)) {
            auFail('Your account has no valid email address saved, so codes cannot be sent to it.');
        }

        if ($action === 'email_2fa_begin') {
            $code = issueAdminLoginCode($pdo, $myId, 'setup');
            if ($code === null) {
                auFail('Too many codes requested. Please wait 15 minutes and try again.');
            }
            $sent = false;
            try {
                require_once __DIR__ . '/../services/EmailService.php';
                $sent = (new EmailService($pdo))->sendAdminLoginCodeEmail(
                    $addr, (string)($meRow['full_name'] ?: $meRow['username']), $code, ADMIN_LOGIN_CODE_TTL_MINUTES
                );
            } catch (\Throwable $e) { error_log('email 2fa begin: ' . $e->getMessage()); }

            // A failed send is reported rather than swallowed. Being told "check
            // your email" for a message that was never sent is how somebody ends
            // up locking themselves out of a shop.
            if (!$sent) {
                auFail('The code could not be sent. Check the mail settings before switching this on.');
            }
            echo json_encode([
                'success' => true,
                'stage'   => 'sent',
                'message' => 'Code sent to ' . maskEmailAddress($addr) . '. Enter it below to switch this on.',
            ]);
            exit;
        }

        // confirm
        $typed = (string)($_POST['code'] ?? '');
        if (!verifyAdminLoginCode($pdo, $myId, $typed, 'setup')) {
            auFail('That code was not correct, or it has expired. Send yourself a new one.');
        }

        // Recovery codes matter more here than for the app: losing access to an
        // inbox is far more common than losing a phone, and these are the only
        // way back in when it happens.
        $recovery = [];
        for ($i = 0; $i < 8; $i++) {
            $recovery[] = strtoupper(bin2hex(random_bytes(3)) . '-' . bin2hex(random_bytes(3)));
        }

        $pdo->prepare(
            "UPDATE admin_users
                SET totp_enabled = 1, totp_method = 'email', email_verified_at = NOW(), recovery_codes = :r
              WHERE id = :id"
        )->execute([':r' => json_encode($recovery), ':id' => $myId]);

        logAdminAction($myId, 'email_2fa_enabled', "Enabled emailed sign-in codes for {$meRow['username']}");
        echo json_encode([
            'success'  => true,
            'stage'    => 'enabled',
            'recovery' => $recovery,
            'message'  => 'Emailed codes are on. Save the recovery codes below — they are your way in if you lose access to that inbox.',
        ]);
        exit;
    }

    // ── Two-step sign-in ────────────────────────────────────
    // All four actions operate on the SIGNED-IN admin's own account only. There
    // is deliberately no way to enable or disable 2FA for someone else: doing so
    // would let one owner lock another out, and turning it off for an account
    // you do not control is exactly what an attacker would want.
    if (str_starts_with($action, 'totp_')) {
        require_once __DIR__ . '/../config/Totp.php';

        if ($myId <= 0) { auFail('This action needs a real staff account. Sign in with one rather than the config.php fallback.'); }

        $meStmt = $pdo->prepare("SELECT * FROM admin_users WHERE id = :id");
        $meStmt->execute([':id' => $myId]);
        $me = $meStmt->fetch(PDO::FETCH_ASSOC);
        if (!$me) { auFail('Your account could not be loaded. Please sign in again.', 401); }

        // Step 1: mint a secret and hold it UNCONFIRMED.
        // totp_enabled stays 0 until a code proves the app is really set up —
        // otherwise a mistyped key would lock the owner out on the next sign-in.
        if ($action === 'totp_begin') {
            $secret = Totp::generateSecret();
            $pdo->prepare("UPDATE admin_users SET totp_secret = :s, totp_enabled = 0 WHERE id = :id")
                ->execute([':s' => $secret, ':id' => $myId]);

            $account = $me['username'] . '@' . (parse_url(SITE_URL, PHP_URL_HOST) ?: 'dievon');
            echo json_encode([
                'success' => true,
                'message' => 'Add the key below to your authenticator app, then enter the code it shows.',
                'secret'  => $secret,
                'account' => $account,
                'uri'     => Totp::provisioningUri($secret, $account, SHOP_NAME),
            ]);
            exit;
        }

        // Step 2: the code proves the app and the server agree. Only now does it
        // become required at sign-in.
        if ($action === 'totp_confirm') {
            $code = (string)($_POST['code'] ?? '');
            if (empty($me['totp_secret'])) { auFail('Start the set-up again — no key is pending.'); }
            if (!Totp::verify((string)$me['totp_secret'], $code)) {
                auFail('That code did not match. Check the key was entered correctly, and that your phone clock is accurate.');
            }

            $codes = Totp::generateRecoveryCodes();
            $pdo->prepare("UPDATE admin_users SET totp_enabled = 1, recovery_codes = :c WHERE id = :id")
                ->execute([':c' => json_encode($codes), ':id' => $myId]);

            logAdminAction($myId, 'admin_2fa_enabled', "Two-step sign-in enabled for {$me['username']}");
            echo json_encode([
                'success' => true,
                'message' => 'Two-step sign-in is on. Save the recovery codes below.',
                'recovery_codes' => $codes,   // shown once, never again
            ]);
            exit;
        }

        // Turning it OFF requires a current code, not just a live session. A
        // borrowed unlocked laptop should not be able to strip the second factor.
        if ($action === 'totp_disable') {
            if ((int)$me['totp_enabled'] !== 1) { auFail('Two-step sign-in is not on.'); }

            // Which proof to demand follows the method in use. This only ever ran
            // Totp::verify, which an emailed-code account can never satisfy — it has
            // no totp_secret — so anyone on that method could switch it on and then
            // never switch it off.
            $typedOff  = (string)($_POST['code'] ?? '');
            $usesEmail = ($me['totp_method'] ?? 'app') === 'email';
            $offOk = $usesEmail
                ? verifyAdminLoginCode($pdo, $myId, $typedOff, 'setup')
                : Totp::verify((string)$me['totp_secret'], $typedOff);

            if (!$offOk) {
                auFail($usesEmail
                    ? 'That code was not correct or has expired. Send yourself a new one, then try again.'
                    : 'That code was not correct, so nothing was changed.');
            }
            // totp_method returns to the default so the account starts clean if
            // two-step is ever switched back on.
            $pdo->prepare("UPDATE admin_users SET totp_enabled = 0, totp_method = 'app', totp_secret = NULL, recovery_codes = NULL WHERE id = :id")
                ->execute([':id' => $myId]);
            logAdminAction($myId, 'admin_2fa_disabled', "Two-step sign-in disabled for {$me['username']}");
            echo json_encode(['success' => true, 'message' => 'Two-step sign-in is off.']);
            exit;
        }

        // Fresh recovery codes, invalidating the old set.
        if ($action === 'totp_recovery') {
            if ((int)$me['totp_enabled'] !== 1) { auFail('Two-step sign-in is not on.'); }
            if (!Totp::verify((string)$me['totp_secret'], (string)($_POST['code'] ?? ''))) {
                auFail('That code was not correct, so your old codes still work.');
            }
            $codes = Totp::generateRecoveryCodes();
            $pdo->prepare("UPDATE admin_users SET recovery_codes = :c WHERE id = :id")
                ->execute([':c' => json_encode($codes), ':id' => $myId]);
            logAdminAction($myId, 'admin_2fa_recovery_regenerated', "New recovery codes for {$me['username']}");
            echo json_encode([
                'success' => true,
                'message' => 'New recovery codes generated. The old ones no longer work.',
                'recovery_codes' => $codes,
            ]);
            exit;
        }
    }

    auFail('Unknown action.');

} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('admin_users_handler: ' . $e->getMessage());
    auFail('Something went wrong. Nothing was changed.');
}
