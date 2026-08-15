<?php
// ============================================================
//  Dievon – Admin: Roles & Per-Staff Permissions Handler
//
//  Serves both the Roles page and the permissions editor on Staff Accounts.
//
//  As in admin_users_handler.php, every rule is enforced here and not merely in
//  the page that renders the controls. A checkbox the browser never drew can
//  still be submitted by hand, and a capability list is exactly the thing a
//  curious staff member would try to submit by hand.
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

function rlFail(string $message, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}
function rlOk(string $message, array $extra = []): void {
    echo json_encode(array_merge(['success' => true, 'message' => $message], $extra));
    exit;
}

if (empty($_SESSION['admin_logged_in'])) { rlFail('Please sign in again.', 401); }
// A literal owner check, NOT adminCan('staff.manage').
//
// The previous comment here argued that a capability check was better because it
// let a custom role be granted staff.manage deliberately. That reasoning misses
// what this handler does: it writes admin_roles.capabilities. Anyone who can edit
// a role can add capabilities to their OWN role — refunds.manage, settings.manage,
// anything — so staff.manage was not a permission to manage roles, it was a
// permission to grant oneself every other permission.
//
// It also disagreed with admin_users_handler.php, which has always required the
// literal owner role. Two halves of staff management, two different answers to
// "who is allowed". This is the stricter half, and it matches the stated rule in
// config.php: money, configuration and staff accounts belong to the owner and
// nowhere else.
if (($_SESSION['admin_role'] ?? 'owner') !== 'owner') {
    rlFail('Only the shop owner can manage roles and permissions.', 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { rlFail('Invalid request method.', 405); }
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    rlFail('Your session has expired. Please refresh the page and try again.', 419);
}

$action = $_POST['action'] ?? '';
$myId   = (int)($_SESSION['admin_id'] ?? 0);

/**
 * Keep only capabilities that actually exist in the catalogue.
 *
 * Anything invented by the caller is dropped rather than stored. Without this a
 * hand-made POST could persist a string like "*" and quietly mint an owner.
 */
function sanitizeCapabilities(array $submitted): array {
    $allowed = adminAllCapabilities();
    return array_values(array_intersect(array_unique($submitted), $allowed));
}

try {
    // ── Save a role's capability list ───────────────────────
    if ($action === 'save_role') {
        $roleId = (int)($_POST['role_id'] ?? 0);
        $role   = $pdo->prepare("SELECT * FROM admin_roles WHERE id = :id");
        $role->execute([':id' => $roleId]);
        $row = $role->fetch();

        if (!$row) { rlFail('That role no longer exists.'); }
        // The owner role is the one account-recovery path that always works.
        // Editing it is refused outright rather than merely hidden in the page.
        if ($row['slug'] === 'owner') {
            rlFail('The Owner role cannot be edited — it always holds every permission.');
        }

        $caps = json_decode((string)($_POST['capabilities'] ?? '[]'), true);
        if (!is_array($caps)) { rlFail('Could not read the permission list.'); }
        $caps = sanitizeCapabilities($caps);

        $pdo->prepare("UPDATE admin_roles SET capabilities = :c WHERE id = :id")
            ->execute([':c' => json_encode($caps), ':id' => $roleId]);

        logAdminAction($myId ?: 1, 'role_updated', "Set {$row['name']} to " . count($caps) . ' capability(ies)');
        rlOk('Saved "' . $row['name'] . '" with ' . count($caps) . ' permission(s).');
    }

    // ── Create a role ───────────────────────────────────────
    if ($action === 'add_role') {
        $name = trim((string)($_POST['name'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));
        if ($name === '') { rlFail('Give the role a name.'); }

        // Slug is derived, then de-duplicated, because slug is UNIQUE and two
        // roles named similarly ("Product manager" / "Product Manager") would
        // otherwise collide and throw a raw SQL error at the person naming them.
        $base = preg_replace('/[^a-z0-9]+/', '_', strtolower($name));
        $base = trim($base, '_') ?: 'role';
        $slug = $base;
        $n    = 2;
        $chk  = $pdo->prepare("SELECT COUNT(*) FROM admin_roles WHERE slug = :s");
        while (true) {
            $chk->execute([':s' => $slug]);
            if ((int)$chk->fetchColumn() === 0) { break; }
            $slug = $base . '_' . $n++;
        }

        $pdo->prepare(
            "INSERT INTO admin_roles (slug, name, description, capabilities, is_system)
             VALUES (:s, :n, :d, '[]', 0)"
        )->execute([':s' => $slug, ':n' => $name, ':d' => $desc ?: null]);

        logAdminAction($myId ?: 1, 'role_created', "Created role {$name}");
        rlOk('Role "' . $name . '" created. Now tick what it can do.');
    }

    // ── Delete a role ───────────────────────────────────────
    if ($action === 'delete_role') {
        $roleId = (int)($_POST['role_id'] ?? 0);
        $role   = $pdo->prepare("SELECT * FROM admin_roles WHERE id = :id");
        $role->execute([':id' => $roleId]);
        $row = $role->fetch();

        if (!$row) { rlFail('That role no longer exists.'); }
        if ((int)$row['is_system'] === 1) {
            rlFail('Built-in roles cannot be deleted.');
        }

        // Deleting a role that people still hold is refused outright.
        //
        // The obvious implementation — NULL their role_id and let them fall back
        // to the preset in their legacy role column — is a privilege ESCALATION,
        // not a step down. An account created on a custom role is stored with the
        // legacy value 'orders', so a "Product Manager" limited to catalogue and
        // media would land on the orders preset and silently gain orders.manage,
        // returns.manage and tickets.manage the moment their role was deleted.
        //
        // Making the owner move people off the role first means access only ever
        // changes because somebody chose it.
        $inUse = 0;
        try {
            $uc = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE role_id = :id");
            $uc->execute([':id' => $roleId]);
            $inUse = (int)$uc->fetchColumn();
        } catch (PDOException $eCount) { $inUse = 0; }

        if ($inUse > 0) {
            rlFail($inUse . ' staff account' . ($inUse === 1 ? ' still uses' : 's still use') . ' "' . $row['name']
                 . '". Move ' . ($inUse === 1 ? 'that person' : 'them') . ' to another role on the Staff Accounts page first — '
                 . 'deleting it now would hand them the default access instead, which is wider than this role.');
        }

        $pdo->prepare("DELETE FROM admin_roles WHERE id = :id")->execute([':id' => $roleId]);

        logAdminAction($myId ?: 1, 'role_deleted', "Deleted role {$row['name']}");
        rlOk('Role "' . $row['name'] . '" deleted.');
    }

    // ── Save one staff member's role + personal overrides ───
    if ($action === 'save_staff_permissions') {
        $staffId = (int)($_POST['admin_id'] ?? 0);
        if ($staffId <= 0) { rlFail('Which staff account?'); }

        $st = $pdo->prepare("SELECT * FROM admin_users WHERE id = :id");
        $st->execute([':id' => $staffId]);
        $staff = $st->fetch();
        if (!$staff) { rlFail('That staff account no longer exists.'); }

        // Editing your own permissions is refused for the same reason you cannot
        // change your own role on the staff page: the only mistake with no way
        // back is the one that removes your own access to this screen.
        if ($staffId === $myId) {
            rlFail('You cannot change your own permissions. Ask another owner to do it.');
        }
        // An owner's access is defined by being an owner. Letting overrides chip
        // away at the last owner is the lockout this whole file guards against.
        if ($staff['role'] === 'owner') {
            rlFail('Owner accounts hold every permission. Change their role first if you need to limit them.');
        }

        $roleId = $_POST['role_id'] ?? '';
        $roleId = ($roleId === '' || $roleId === '0') ? null : (int)$roleId;
        if ($roleId !== null) {
            $rc = $pdo->prepare("SELECT slug FROM admin_roles WHERE id = :id");
            $rc->execute([':id' => $roleId]);
            $slug = $rc->fetchColumn();
            if ($slug === false) { rlFail('That role no longer exists.'); }
            // Handing out the owner role from the permissions editor would be a
            // silent promotion; the staff page is where roles are changed.
            if ($slug === 'owner') { rlFail('Assign the Owner role from the Staff Accounts page, not here.'); }
        }

        $grants = json_decode((string)($_POST['grants'] ?? '[]'), true);
        $denies = json_decode((string)($_POST['denies'] ?? '[]'), true);
        if (!is_array($grants) || !is_array($denies)) { rlFail('Could not read the permission list.'); }
        $grants = sanitizeCapabilities($grants);
        $denies = sanitizeCapabilities($denies);
        // A capability cannot be granted and denied at once; the grant wins,
        // otherwise the stored pair would depend on row order to resolve.
        $denies = array_values(array_diff($denies, $grants));

        $pdo->beginTransaction();
        // The legacy `role` slug moves with role_id, for the same reason
        // admin_users_handler.php now moves role_id with role: the two fields
        // describe the same fact and drifted apart because each save wrote only
        // one of them. Anything reading the legacy slug — including
        // adminCapabilitySet() in config.php — then disagreed with this screen.
        //
        // Only slugs that exist as built-in roles are written back; a custom role
        // has no legacy equivalent, so the slug is left as it was rather than
        // invented. COALESCE keeps the old value in that case.
        $pdo->prepare("UPDATE admin_users
                          SET role_id = :r,
                              role = COALESCE(
                                  (SELECT slug FROM admin_roles
                                    WHERE id = :r2 AND slug IN ('owner','catalogue','orders') LIMIT 1),
                                  role
                              )
                        WHERE id = :id")
            ->execute([':r' => $roleId, ':r2' => $roleId, ':id' => $staffId]);
        $pdo->prepare("DELETE FROM admin_user_capabilities WHERE admin_id = :id")->execute([':id' => $staffId]);
        $ins = $pdo->prepare(
            "INSERT INTO admin_user_capabilities (admin_id, capability, mode) VALUES (:a, :c, :m)"
        );
        foreach ($grants as $c) { $ins->execute([':a' => $staffId, ':c' => $c, ':m' => 'grant']); }
        foreach ($denies as $c) { $ins->execute([':a' => $staffId, ':c' => $c, ':m' => 'deny']); }
        $pdo->commit();

        logAdminAction($myId ?: 1, 'staff_permissions_updated',
            "Updated {$staff['username']}: role_id=" . ($roleId ?? 'none')
            . ', ' . count($grants) . ' grant(s), ' . count($denies) . ' deny(ies)');
        rlOk('Permissions saved for ' . $staff['username'] . '.');
    }

    rlFail('Unknown action.');
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    // The tables arrive with update_new_database.php; say so rather than showing
    // a raw SQL error to somebody who just wanted to tick a box.
    error_log('roles_handler: ' . $e->getMessage());
    rlFail('Could not save. If you have just deployed, run update_new_database.php to create the roles tables.');
}
