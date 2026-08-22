<?php
// ============================================================
//  Dievon – Admin: Staff Accounts
//
//  This page used to route to a placeholder in module_router.php that printed
//  the ADMIN_USERNAME constant and the sentence "This module is active and
//  synchronized live with database settings." Nothing was synchronized and
//  there was nothing to manage: the shop had one shared credential written in
//  plain text in config/config.php.
//
//  Each person now gets their own sign-in, their own role, and can be switched
//  off individually when they leave — none of which a shared password allows.
//
//  Owner-only. An order clerk who could create an owner account would make
//  every other permission decorative.
// ============================================================
// Checked BEFORE the header renders, so a refusal is a real 403 rather than a
// 200 page with a polite message in it. Sessions created before roles existed
// carry no admin_role and are treated as owner, so upgrading cannot lock the
// only administrator out of account management.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
requireAdminCapability('staff.manage');
// Matches admin_users_handler.php, which has always required the literal owner
// role. The page used to load for anyone holding staff.manage while every save
// came back 403, so the screen looked usable and was not.
if (($_SESSION['admin_role'] ?? 'owner') !== 'owner') {
    http_response_code(403);
    exit('Only the shop owner can manage staff accounts.');
}

$activeTab = 'admin_users';
$hideHeaderTitle = true;
require_once 'includes/header.php';

$admins = [];
try {
    $admins = $pdo->query("SELECT * FROM admin_users ORDER BY role = 'owner' DESC, username ASC")->fetchAll();
} catch (PDOException $e) {}

$history = [];
try {
    $history = $pdo->query("SELECT username, succeeded, ip_address, created_at
                              FROM admin_login_history ORDER BY id DESC LIMIT 15")->fetchAll();
} catch (PDOException $e) {}

// ── Roles and per-person overrides ──────────────────────────────────────────
// All three lookups are wrapped, because these tables arrive with
// update_new_database.php. On an install where that has not been run yet the
// page must still render its original contents rather than fatal — permissions
// simply fall back to the built-in presets, exactly as before.
$customRoles   = [];
$roleCaps      = [];   // role_id => capability list, for the checkbox baseline
$staffOverride = [];   // admin_id => ['grant'=>[], 'deny'=>[]]
$permsReady    = true;
try {
    // is_system is selected, not just sorted on — the "Your roles" group in the
    // add-staff form filters on it, and without the column every built-in role
    // would read as custom and appear twice in that dropdown.
    foreach ($pdo->query("SELECT id, slug, name, capabilities, is_system FROM admin_roles ORDER BY is_system DESC, name ASC") as $r) {
        $customRoles[] = $r;
        $roleCaps[(int)$r['id']] = json_decode((string)$r['capabilities'], true) ?: [];
    }
    foreach ($pdo->query("SELECT admin_id, capability, mode FROM admin_user_capabilities") as $o) {
        $staffOverride[(int)$o['admin_id']][$o['mode']][] = $o['capability'];
    }
} catch (PDOException $e) {
    $permsReady = false;
}

$ROLE_LABELS = [
    'owner'     => 'Owner — everything, including refunds, settings and backups',
    'catalogue' => 'Catalogue manager — products, categories, media, SEO',
    'orders'    => 'Order staff — orders, returns and tickets only',
];

// Constants still present are a live risk: anyone who can read config.php on the
// server holds a working sign-in. Say so plainly rather than burying it.
// The config.php credential fallback has been removed from admin/login.php, and
// ADMIN_PASSWORD no longer exists. This flag used to test `ADMIN_PASSWORD !== ''`,
// which reported "safe" for exactly the empty-string case that still authenticated.
$fallbackStillActive = false;
$myId = (int)($_SESSION['admin_id'] ?? 0);
?>

<div class="glass-panel" style="padding:28px;">
    <div class="admin-page-header" style="margin-bottom:22px;">
        <div>
            <h2 class="admin-page-title" style="margin:0;"><i class="fa-solid fa-users-gear"></i> Staff Accounts</h2>
            <p class="admin-page-subtitle" style="margin-top:4px;">Give each person their own sign-in. A shared password cannot be revoked or traced.</p>
            <?php if ($permsReady): ?>
            <p class="au-flow">
                <i class="fa-solid fa-circle-info"></i>
                This is where usernames and passwords live. <em>What</em> each person may do comes from their role —
                build those on <a href="roles.php">Roles &amp; Permissions</a>, then pick one here when you add
                someone, or use <strong>Permissions</strong> on their row to tailor it for that one person.
            </p>
            <?php endif; ?>
        </div>
    </div>

    <div id="auMsg" class="au-msg" hidden></div>


    <?php if (!empty($_SESSION['admin_notice'])): ?>
        <div class="au-warn"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($_SESSION['admin_notice']) ?></div>
        <?php unset($_SESSION['admin_notice']); ?>
    <?php endif; ?>

    <?php if ($fallbackStillActive): ?>
        <div class="au-warn">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <strong>ADMIN_USERNAME and ADMIN_PASSWORD are still set in <code>config/config.php</code>.</strong>
            That file is uploaded to the server, so the password sits there in plain text and still works as a sign-in.
            Once you have signed in with an account below, set both constants to an empty string.
        </div>
    <?php endif; ?>

    <?php foreach ($admins as $a): if ((int)$a['must_change_password'] === 1): ?>
        <div class="au-warn">
            <i class="fa-solid fa-key"></i>
            <strong><?= htmlspecialchars($a['username']) ?></strong> still uses the password carried over from
            <code>config.php</code>. Set a new one below.
        </div>
    <?php break; endif; endforeach; ?>

    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr><th>Username</th><th>Name</th><th>Role</th><th>Last signed in</th><th>Active</th><th class="au-actions">Actions</th></tr>
            </thead>
            <tbody>
            <?php if (!$admins): ?>
                <tr><td colspan="6" class="au-muted">No staff accounts yet — run the database update to create the first one.</td></tr>
            <?php endif; ?>
            <?php foreach ($admins as $a): $isMe = ((int)$a['id'] === $myId); ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($a['username']) ?></strong>
                        <?php if ($isMe): ?><span class="au-pill">you</span><?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($a['full_name'] ?: '—') ?></td>
                    <td>
                        <?php // Changing your own role is blocked: demoting yourself would leave
                              // the shop with no owner and no way to appoint one. ?>
                        <select class="form-control au-role" data-id="<?= (int)$a['id'] ?>"
                                <?= $isMe ? 'disabled title="You cannot change your own role"' : '' ?>>
                            <?php foreach ($ROLE_LABELS as $k => $label): ?>
                                <option value="<?= $k ?>" <?= $a['role'] === $k ? 'selected' : '' ?>><?= htmlspecialchars(explode(' — ', $label)[0]) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><?= $a['last_login_at'] ? date('j M Y, H:i', strtotime($a['last_login_at'])) : '<span class="au-muted">never</span>' ?></td>
                    <td>
                        <input type="checkbox" class="au-active" data-id="<?= (int)$a['id'] ?>" <?= (int)$a['is_active'] ? 'checked' : '' ?>
                               <?= $isMe ? 'disabled title="You cannot disable your own account"' : '' ?>>
                    </td>
                    <td class="au-actions">
                        <div class="au-action-row">
                        <?php // Owner accounts hold everything by definition, and editing your own
                              // permissions is the one mistake with no way back — the handler refuses
                              // both, so the button is not offered for either. ?>
                        <?php /* The per-person Permissions panel was removed deliberately.
                                 It showed a checkbox grid identical to the one on Roles &
                                 Permissions while writing a different table, and reading the
                                 two as duplicates is a fair mistake — it was made repeatedly.
                                 What a person may do now comes from their role and nothing
                                 else, set with the Role dropdown on this row.

                                 The exception rows in admin_user_capabilities are cleared by
                                 update_new_database.php rather than left in place: they would
                                 have kept applying with no screen left to show them, which is
                                 worse than not having the feature. */ ?>
                        <button type="button" class="btn-secondary au-pw-btn"
                                onclick="setAdminPassword(<?= (int)$a['id'] ?>, '<?= htmlspecialchars($a['username'], ENT_QUOTES) ?>')">Set password</button>
                        <?php /* Not offered on your own row — deleting yourself locks you out, and
                                 the handler refuses it anyway. The last active owner is refused
                                 server-side rather than hidden here, because whether an account is
                                 the last owner depends on the others and can change between the
                                 page loading and the button being pressed. */ ?>
                        <?php if (!$isMe): ?>
                        <button type="button" class="btn-danger au-pw-btn"
                                onclick="deleteAdminUser(<?= (int)$a['id'] ?>, '<?= htmlspecialchars($a['username'], ENT_QUOTES) ?>')">Delete</button>
                        <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php if ($permsReady && !$isMe && $a['role'] !== 'owner'):
                    $aid       = (int)$a['id'];
                    $myRoleId  = (int)($a['role_id'] ?? 0);
                    $grants    = $staffOverride[$aid]['grant'] ?? [];
                    $denies    = $staffOverride[$aid]['deny']  ?? [];
                    // What this person effectively holds today: their role's list (or the
                    // built-in preset when they have no custom role), plus grants, minus
                    // denies. The boxes show the ANSWER, not the plumbing — an owner
                    // ticking boxes is thinking "can they do this?", not "is this an
                    // override?". save turns the ticks back into grants and denies.
                    $baseCaps  = $myRoleId && isset($roleCaps[$myRoleId])
                        ? $roleCaps[$myRoleId]
                        : (ADMIN_CAPABILITIES[$a['role']] ?? []);
                    if (in_array('*', $baseCaps, true)) { $baseCaps = adminAllCapabilities(); }
                    $effective = array_values(array_diff(array_unique(array_merge($baseCaps, $grants)), $denies));
                ?>
                <tr class="au-perms-row" id="permsRow<?= $aid ?>" hidden>
                    <td colspan="6">
                        <div class="au-perms" data-admin-id="<?= $aid ?>"
                             data-base='<?= htmlspecialchars(json_encode($baseCaps), ENT_QUOTES) ?>'>
                            <?php /* This grid looks identical to the one on Roles & Permissions
                                     but does not do the same thing, and reading it as a duplicate
                                     is a fair mistake to make. It stores EXCEPTIONS for this one
                                     person in admin_user_capabilities; roles.php edits the role
                                     itself in admin_roles.capabilities. Saying so here is cheaper
                                     than the confusion of ticking a box and finding the role
                                     unchanged. */ ?>
                            <p class="au-perms-scope">
                                <i class="fa-solid fa-user-pen" aria-hidden="true"></i>
                                Changes here affect <strong><?= htmlspecialchars($a['username']) ?> only</strong>.
                                A tick that the role already grants is left alone, so editing that role
                                later still reaches this person. To change what everyone on a role can do,
                                use <a href="roles.php">Roles &amp; Permissions</a> instead.
                            </p>
                            <div class="au-perms-head">
                                <label class="au-perms-rolelabel">
                                    Role
                                    <select class="form-control au-perms-role" onchange="roleChanged(<?= $aid ?>)">
                                        <option value="">Built-in (<?= htmlspecialchars($a['role']) ?>)</option>
                                        <?php foreach ($customRoles as $cr): if ($cr['slug'] === 'owner') continue; ?>
                                            <option value="<?= (int)$cr['id'] ?>"
                                                    data-caps='<?= htmlspecialchars((string)$cr['capabilities'], ENT_QUOTES) ?>'
                                                    <?= $myRoleId === (int)$cr['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($cr['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <p class="au-perms-hint">
                                    Ticks below are what <strong><?= htmlspecialchars($a['username']) ?></strong> can actually do.
                                    Changing the role resets them; tick or untick afterwards to tailor this one person.
                                </p>
                            </div>

                            <div class="au-perms-groups">
                                <?php foreach (ADMIN_CAPABILITY_CATALOGUE as $groupName => $groupCaps): ?>
                                <fieldset class="au-perms-group">
                                    <legend><?= htmlspecialchars($groupName) ?></legend>
                                    <?php foreach ($groupCaps as $capKey => $capLabel): ?>
                                    <label class="au-perms-cap">
                                        <input type="checkbox" class="au-perms-box" data-cap="<?= htmlspecialchars($capKey) ?>"
                                               <?= in_array($capKey, $effective, true) ? 'checked' : '' ?>>
                                        <span><?= htmlspecialchars($capLabel) ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </fieldset>
                                <?php endforeach; ?>
                            </div>

                            <div class="au-perms-actions">
                                <button type="button" class="btn-primary" onclick="saveStaffPerms(<?= $aid ?>)">
                                    <i class="fa-solid fa-floppy-disk"></i> Save permissions
                                </button>
                                <a href="roles.php" class="au-perms-link">Edit the roles themselves &rarr;</a>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="au-actions">
        <button type="button" class="btn-primary" onclick="saveAdminUsers()"><i class="fa-solid fa-floppy-disk"></i> Save roles &amp; access</button>
    </div>

    <h3 class="au-subhead">Two-step sign-in</h3>
    <?php
    $me = null;
    foreach ($admins as $a) { if ((int)$a['id'] === $myId) { $me = $a; break; } }
    $twoFaOn = $me && (int)($me['totp_enabled'] ?? 0) === 1;
    ?>
    <div class="au-2fa">
        <?php
        $myMethod = ($me['totp_method'] ?? 'app') === 'email' ? 'email' : 'app';
        $myEmail  = trim((string)($me['email'] ?? ''));
        ?>
        <?php // The email on the account. Without one, neither emailed sign-in
              // codes nor the forgot-password page have anywhere to send a code —
              // and older accounts (the owner's especially) predate the field and
              // carry none. Own account only, matching the handler. ?>
        <div class="au-2fa-emailrow">
            <label for="auMyEmail">Email address for codes &amp; password resets</label>
            <div class="au-2fa-emailinput">
                <input type="email" id="auMyEmail" class="form-control"
                       placeholder="you@example.com" maxlength="191" autocomplete="off"
                       value="<?= htmlspecialchars($myEmail) ?>">
                <button type="button" class="btn-primary" onclick="saveMyEmail()">Save email</button>
            </div>
            <?php if ($myEmail === ''): ?>
                <p class="au-2fa-note">You have no email saved yet — without one, you cannot reset a forgotten password by email.</p>
            <?php else: ?>
                <p class="au-2fa-note">Currently <strong><?= htmlspecialchars(maskEmailAddress($myEmail)) ?></strong>. Codes for sign-in and password resets are sent here.</p>
            <?php endif; ?>
        </div>
        <?php if ($twoFaOn): ?>
            <p class="au-2fa-on"><i class="fa-solid fa-shield-halved"></i>
               Two-step sign-in is <strong>on</strong> for your account, using
               <strong><?= $myMethod === 'email' ? 'emailed codes' : 'an authenticator app' ?></strong><?php
               if ($myMethod === 'email' && $myEmail !== ''): ?> to <strong><?= htmlspecialchars(maskEmailAddress($myEmail)) ?></strong><?php endif; ?>.
               <?php $left = count(json_decode((string)($me['recovery_codes'] ?? '[]'), true) ?: []); ?>
               You have <strong><?= $left ?></strong> recovery code<?= $left === 1 ? '' : 's' ?> left.</p>
            <button type="button" class="btn-secondary" onclick="regenRecovery()">New recovery codes</button>
            <button type="button" class="btn-secondary" onclick="disable2fa()">Turn off</button>
        <?php else: ?>
            <p class="au-2fa-off">
                A password alone protects every order, customer address and refund in the shop.
                Two-step sign-in adds a second 6-digit code on top of it.
            </p>
            <?php // Two ways in, because requiring an authenticator app excludes anyone
                  // who does not have one and will not install one. Email is the weaker
                  // of the two — whoever reads that inbox can complete a sign-in — so it
                  // is offered as the alternative, not the recommendation. ?>
            <div class="au-2fa-choice">
                <div class="au-2fa-option">
                    <h4><i class="fa-solid fa-mobile-screen"></i> Authenticator app <span class="au-2fa-best">strongest</span></h4>
                    <p>A code from an app on your phone, working even with no signal. Not tied to your inbox.</p>
                    <button type="button" class="btn-primary" onclick="begin2fa()">Use an app</button>
                </div>
                <div class="au-2fa-option">
                    <h4><i class="fa-solid fa-envelope"></i> Emailed code</h4>
                    <?php if ($myEmail === ''): ?>
                        <p>Your account has no email address yet, so this cannot be switched on.
                           Add one to your row above and save first.</p>
                        <button type="button" class="btn-secondary" disabled>Needs an email address</button>
                    <?php else: ?>
                        <p>A code sent to <strong><?= htmlspecialchars(maskEmailAddress($myEmail)) ?></strong> each time you sign in.
                           No app needed — but anyone who can read that inbox can sign in as you.</p>
                        <button type="button" class="btn-primary" onclick="beginEmail2fa()">Email me a test code</button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        <div id="au2faSetup" class="au-2fa-setup" hidden></div>
    </div>

    <h3 class="au-subhead">Add a staff account</h3>
    <div class="au-newgrid">
        <input type="text" id="auNewUser" class="form-control" placeholder="Username" maxlength="80" autocomplete="off">
        <input type="text" id="auNewName" class="form-control" placeholder="Full name" maxlength="120">
        <?php // Optional, but it is the only way this person can use emailed sign-in
              // codes later — without an address that option simply is not offered to
              // them. Not trusted until a code sent to it is typed back in. ?>
        <input type="email" id="auNewEmail" class="form-control" placeholder="Email (for sign-in codes)" maxlength="191" autocomplete="off">
        <?php // Custom roles are offered here too. Without them this dropdown listed
              // only the three built-ins, so a role you had just built on the Roles
              // page could not be handed to the person you were creating — you had to
              // add the account first, then reopen it and switch the role. Two steps
              // for one decision, and nothing on screen said so. ?>
        <select id="auNewRole" class="form-control">
            <optgroup label="Built-in roles">
                <?php foreach ($ROLE_LABELS as $k => $label): ?>
                    <option value="<?= $k ?>"><?= htmlspecialchars(explode(' — ', $label)[0]) ?></option>
                <?php endforeach; ?>
            </optgroup>
            <?php
            $assignableRoles = array_filter($customRoles, fn($r) => $r['slug'] !== 'owner' && (int)$r['is_system'] === 0);
            if ($permsReady && $assignableRoles):
            ?>
            <optgroup label="Your roles">
                <?php foreach ($assignableRoles as $cr): ?>
                    <option value="role:<?= (int)$cr['id'] ?>"><?= htmlspecialchars($cr['name']) ?></option>
                <?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
        </select>
        <input type="password" id="auNewPass" class="form-control" placeholder="Password (min <?= PASSWORD_MIN_LENGTH ?> characters)" autocomplete="new-password">
        <button type="button" class="btn-primary" onclick="addAdminUser()">Add</button>
    </div>

    <h3 class="au-subhead">Recent sign-ins</h3>
    <div class="table-wrapper">
        <table class="data-table">
            <thead><tr><th>Username</th><th>Result</th><th>Address</th><th>When</th></tr></thead>
            <tbody>
                <?php if (!$history): ?>
                    <tr><td colspan="4" class="au-muted">Nothing recorded yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($history as $h): ?>
                <tr>
                    <td><?= htmlspecialchars($h['username']) ?></td>
                    <td><?= (int)$h['succeeded'] ? '<span class="au-ok">signed in</span>' : '<span class="au-fail">failed</span>' ?></td>
                    <td class="au-muted"><?= htmlspecialchars($h['ip_address'] ?? '—') ?></td>
                    <td class="au-muted"><?= date('j M Y, H:i', strtotime($h['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const AU_CSRF = '<?= generateCsrfToken() ?>';

function auMsg(text, ok) {
    const el = document.getElementById('auMsg');
    el.textContent = text;
    el.className = 'au-msg ' + (ok ? 'is-ok' : 'is-err');
    el.hidden = false;
}

function auPost(fd, onOk) {
    fd.append('csrf_token', AU_CSRF);
    fetch('admin_users_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { auMsg(d.message, d.success); if (d.success && onOk) { onOk(); } })
        .catch(() => auMsg('Could not reach the server. Nothing was saved.', false));
}

function saveAdminUsers() {
    // Disabled selects are excluded by design — those are the current user's own
    // role and active flag, which the server refuses to change anyway.
    const rows = [...document.querySelectorAll('.au-role:not([disabled])')].map(sel => ({
        id: sel.dataset.id,
        role: sel.value,
        is_active: document.querySelector('.au-active[data-id="' + sel.dataset.id + '"]')?.checked ? 1 : 0
    }));
    if (!rows.length) { auMsg('Nothing to save.', false); return; }
    const fd = new FormData();
    fd.append('action', 'save_roles');
    fd.append('rows', JSON.stringify(rows));
    auPost(fd, () => setTimeout(() => location.reload(), 900));
}

/* Permanent, so the confirmation names the account and says what survives it. */
function deleteAdminUser(id, username) {
    dievonConfirm(
        'They lose access immediately and the account cannot be restored. Their past '
      + 'entries in the audit log stay, but will no longer show a name. To remove access '
      + 'without deleting, untick Active and save instead.',
        { title: 'Delete ' + username + '?', confirmText: 'Delete permanently', danger: true }
    ).then(function (ok) {
        if (!ok) { return; }
        const fd = new FormData();
        fd.append('action', 'delete_user');
        fd.append('id', id);
        auPost(fd, () => setTimeout(() => location.reload(), 900));
    });
}

function addAdminUser() {
    const fd = new FormData();
    fd.append('action', 'add_user');
    fd.append('username',  document.getElementById('auNewUser').value.trim());
    fd.append('full_name', document.getElementById('auNewName').value.trim());
    fd.append('email',     document.getElementById('auNewEmail').value.trim());
    fd.append('role',      document.getElementById('auNewRole').value);
    fd.append('password',  document.getElementById('auNewPass').value);
    auPost(fd, () => setTimeout(() => location.reload(), 900));
}

// Your own email address — used for emailed sign-in codes and, when a
// password is forgotten, where the reset code is sent. Saves straight back
// to the account, no reload needed; the note above the field updates from
// the server's masked confirmation.
function saveMyEmail() {
    const email = document.getElementById('auMyEmail').value.trim();
    const fd = new FormData();
    fd.append('action', 'save_my_email');
    fd.append('email', email);
    auPost(fd, () => { /* message shown in auMsg */ });
}

function begin2fa() {
    const fd = new FormData();
    fd.append('action', 'totp_begin');
    fd.append('csrf_token', AU_CSRF);
    fetch('admin_users_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (!d.success) { auMsg(d.message, false); return; }
            const box = document.getElementById('au2faSetup');
            box.hidden = false;
            // The secret is shown as text rather than a QR image: generating a QR
            // needs a library, and every authenticator app accepts a typed key.
            box.innerHTML =
              '<p class="au-2fa-step"><strong>1.</strong> In your authenticator app choose "add account", then "enter a setup key".</p>'
            + '<p class="au-2fa-step"><strong>2.</strong> Account name: <code>' + d.account + '</code></p>'
            + '<p class="au-2fa-step"><strong>3.</strong> Key: <code class="au-secret">' + d.secret + '</code></p>'
            + '<p class="au-2fa-step"><strong>4.</strong> Enter the 6-digit code it shows, to prove it works:</p>'
            + '<div class="au-2fa-confirm">'
            + '  <input type="text" id="au2faCode" class="form-control" placeholder="000000" inputmode="numeric" maxlength="6" autocomplete="one-time-code">'
            + '  <button type="button" class="btn-primary" onclick="confirm2fa()">Turn on</button>'
            + '</div>';
        })
        .catch(() => auMsg('Could not reach the server.', false));
}

function confirm2fa() {
    const fd = new FormData();
    fd.append('action', 'totp_confirm');
    fd.append('code', document.getElementById('au2faCode').value.trim());
    fd.append('csrf_token', AU_CSRF);
    fetch('admin_users_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            auMsg(d.message, d.success);
            if (d.success && d.recovery_codes) {
                // Shown ONCE. They are stored for matching but never displayed
                // again, so this is the only chance to write them down.
                const box = document.getElementById('au2faSetup');
                box.innerHTML = '<p class="au-2fa-step"><strong>Save these recovery codes now.</strong> '
                    + 'They are shown only once and each works a single time. Without them, a lost phone means '
                    + 'no way into your own shop.</p><pre class="au-codes">' + d.recovery_codes.join('\n') + '</pre>';
            }
        })
        .catch(() => auMsg('Could not reach the server.', false));
}

function disable2fa() {
    dievonPrompt(
        'Your account will be protected by its password alone. Anyone who learns that '
      + 'password can sign in as you and reach orders, customers and refunds.',
        '',
        { title: 'Turn off two-step sign-in?', confirmText: 'Turn it off', danger: true,
          autocomplete: 'one-time-code', placeholder: 'Code from your authenticator app', maxlength: 6 }
    ).then(function (code) {
        if (!code || !code.trim()) { return; }
        disable2faSubmit(code.trim());
    });
}

function disable2faSubmit(code) {
    const fd = new FormData();
    fd.append('action', 'totp_disable');
    fd.append('code', code);
    fd.append('csrf_token', AU_CSRF);
    fetch('admin_users_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { auMsg(d.message, d.success); if (d.success) { setTimeout(() => location.reload(), 900); } });
}

function regenRecovery() {
    dievonPrompt(
        'Your existing recovery codes stop working the moment new ones are issued. '
      + 'Have somewhere to save the new list before you continue.',
        '',
        { title: 'Generate new recovery codes', confirmText: 'Generate codes',
          autocomplete: 'one-time-code', placeholder: 'Code from your authenticator app', maxlength: 6 }
    ).then(function (code) {
        if (!code || !code.trim()) { return; }
        regenRecoverySubmit(code.trim());
    });
}

function regenRecoverySubmit(code) {
    const fd = new FormData();
    fd.append('action', 'totp_recovery');
    fd.append('code', code);
    fd.append('csrf_token', AU_CSRF);
    fetch('admin_users_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            auMsg(d.message, d.success);
            if (d.success && d.recovery_codes) {
                const box = document.getElementById('au2faSetup');
                box.hidden = false;
                box.innerHTML = '<p class="au-2fa-step"><strong>Your new recovery codes.</strong> '
                    + 'The old ones no longer work.</p><pre class="au-codes">' + d.recovery_codes.join('\n') + '</pre>';
            }
        });
}

/* These four actions use the shop's own dialog (assets/js/dievon-dialog.js),
   which admin/includes/footer.php already loads on every admin page. It gives
   dievonConfirm and dievonPrompt as promises, with .dv-dialog styling, danger
   buttons, focus handling and Escape already solved.

   An earlier version of this file grew its own parallel dialog. That was a second
   system doing the same job with different styling, on a screen the owner reaches
   from the same sidebar as every other. */
function setAdminPassword(id, username) {
    dievonPrompt(
        'This replaces the current password for "' + username + '". They will need the new one to sign in.',
        '',
        {
            title: 'Set a new password',
            confirmText: 'Set password',
            inputType: 'password',        // window.prompt could not mask this
            autocomplete: 'new-password',
            placeholder: 'At least <?= PASSWORD_MIN_LENGTH ?> characters'
        }
    ).then(function (pw) {
        if (!pw) { return; }
        const fd = new FormData();
        fd.append('action', 'set_password');
        fd.append('id', id);
        fd.append('password', pw);
        auPost(fd, () => setTimeout(() => location.reload(), 900));
    });
}

// ── Two-step sign-in by email ───────────────────────────────────────────────
// Send first, enable only once the code comes back — so a mistyped address
// fails here rather than at the next sign-in.
async function beginEmail2fa() {
    const fd = new FormData();
    fd.append('action', 'email_2fa_begin');
    fd.append('csrf_token', AU_CSRF);
    const res  = await fetch('admin_users_handler.php', {method: 'POST', body: fd});
    const data = await res.json().catch(() => ({success: false, message: 'Server returned an unreadable response.'}));

    const box = document.getElementById('auMsg');
    box.hidden = false;
    box.textContent = data.message || 'Something went wrong.';
    box.className = 'au-msg ' + (data.success ? 'au-msg-ok' : 'au-msg-bad');
    if (!data.success) { return; }

    const panel = document.getElementById('au2faSetup');
    panel.hidden = false;
    panel.innerHTML =
        '<p style="font-size:13px; margin:0 0 10px;">Enter the 6-digit code from that email to switch on emailed sign-in codes.</p>' +
        '<div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">' +
          '<input type="text" id="auEmailCode" class="form-control" inputmode="numeric" maxlength="6" ' +
                 'placeholder="000000" style="max-width:150px; letter-spacing:4px; text-align:center; font-weight:700;">' +
          '<button type="button" class="btn-primary" onclick="confirmEmail2fa()">Turn on</button>' +
          '<button type="button" class="btn-secondary" onclick="beginEmail2fa()">Send another</button>' +
        '</div>';
    document.getElementById('auEmailCode').focus();
}

async function confirmEmail2fa() {
    const code = (document.getElementById('auEmailCode') || {}).value || '';
    const fd = new FormData();
    fd.append('action', 'email_2fa_confirm');
    fd.append('code', code.trim());
    fd.append('csrf_token', AU_CSRF);
    const res  = await fetch('admin_users_handler.php', {method: 'POST', body: fd});
    const data = await res.json().catch(() => ({success: false, message: 'Server returned an unreadable response.'}));

    const box = document.getElementById('auMsg');
    box.hidden = false;
    box.textContent = data.message || 'Something went wrong.';
    box.className = 'au-msg ' + (data.success ? 'au-msg-ok' : 'au-msg-bad');
    if (!data.success) { return; }

    // Shown once and never again — they are stored hashed-in-name-only for
    // matching, and there is no screen that can reprint them later.
    const panel = document.getElementById('au2faSetup');
    panel.innerHTML =
        '<p style="font-size:13px; font-weight:700; margin:0 0 8px;">Save these recovery codes now — they will not be shown again.</p>' +
        '<pre style="background:var(--bg-surface-soft); border:1px solid var(--border-light); ' +
             'padding:14px; font-size:13px; line-height:1.9; margin:0 0 12px;">' +
             (data.recovery || []).join('\n') + '</pre>' +
        '<button type="button" class="btn-primary" onclick="location.reload()">Done</button>';
}

// ── Per-staff permissions ───────────────────────────────────────────────────
function togglePerms(id) {
    const row = document.getElementById('permsRow' + id);
    row.hidden = !row.hidden;
    if (!row.hidden) { row.scrollIntoView({behavior: 'smooth', block: 'nearest'}); }
}

// Switching role rewrites the ticks to that role's list. Leaving the old ticks
// in place would silently turn every difference into a personal override, so a
// staff member "moved to" a role would not actually match anyone else on it.
function roleChanged(id) {
    const panel = document.querySelector('.au-perms[data-admin-id="' + id + '"]');
    const sel   = panel.querySelector('.au-perms-role');
    const opt   = sel.selectedOptions[0];
    let caps;
    if (sel.value === '') {
        caps = JSON.parse(panel.dataset.base || '[]');   // back to the built-in preset
    } else {
        caps = JSON.parse(opt.dataset.caps || '[]');
    }
    panel.querySelectorAll('.au-perms-box').forEach(box => {
        box.checked = caps.includes(box.dataset.cap);
    });
}

async function saveStaffPerms(id) {
    const panel = document.querySelector('.au-perms[data-admin-id="' + id + '"]');
    const sel   = panel.querySelector('.au-perms-role');

    // The boxes describe the desired end state. Diff them against whatever the
    // chosen role already grants, so only genuine differences are stored as
    // overrides — otherwise editing a role later would never reach this person.
    const roleCaps = sel.value === ''
        ? JSON.parse(panel.dataset.base || '[]')
        : JSON.parse(sel.selectedOptions[0].dataset.caps || '[]');

    const grants = [], denies = [];
    panel.querySelectorAll('.au-perms-box').forEach(box => {
        const cap = box.dataset.cap, inRole = roleCaps.includes(cap);
        if (box.checked && !inRole)  { grants.push(cap); }
        if (!box.checked && inRole)  { denies.push(cap); }
    });

    const fd = new FormData();
    fd.append('action', 'save_staff_permissions');
    fd.append('admin_id', id);
    fd.append('role_id', sel.value);
    fd.append('grants', JSON.stringify(grants));
    fd.append('denies', JSON.stringify(denies));
    fd.append('csrf_token', AU_CSRF);

    const res  = await fetch('roles_handler.php', {method: 'POST', body: fd});
    const data = await res.json().catch(() => ({success: false, message: 'Server returned an unreadable response.'}));
    const box  = document.getElementById('auMsg');
    box.hidden = false;
    box.textContent = data.message || (data.success ? 'Saved.' : 'Something went wrong.');
    box.className = 'au-msg ' + (data.success ? 'au-msg-ok' : 'au-msg-bad');
    box.scrollIntoView({behavior: 'smooth', block: 'nearest'});
}
</script>

<style>
.au-perms-row > td { background:var(--bg-surface-soft); padding:0 !important; }
.au-perms { padding:18px 20px; border-top:2px solid var(--color-primary); }
.au-perms-head { display:flex; gap:18px; align-items:flex-start; flex-wrap:wrap; margin-bottom:14px; }
.au-perms-rolelabel { font-size:11px; text-transform:uppercase; letter-spacing:.08em; font-weight:700; color:var(--text-muted); display:flex; flex-direction:column; gap:6px; min-width:220px; }
.au-perms-hint { font-size:12.5px; color:var(--text-secondary); margin:0; flex:1; min-width:240px; line-height:1.55; }
.au-perms-groups { display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:12px; }
.au-perms-group { border:1px solid var(--border-light); padding:10px 12px; background:var(--bg-surface); }
.au-perms-group legend { font-size:10.5px; text-transform:uppercase; letter-spacing:.08em; font-weight:700; color:var(--color-primary); padding:0 6px; }
.au-perms-cap { display:flex; align-items:flex-start; gap:9px; font-size:12.5px; padding:4px 0; cursor:pointer; line-height:1.45; }
.au-perms-cap input { margin-top:2px; flex-shrink:0; }
.au-perms-actions { margin-top:14px; display:flex; align-items:center; gap:16px; flex-wrap:wrap; }
.au-perms-link { font-size:12.5px; color:var(--color-primary); }
.au-flow { font-size:13px; line-height:1.6; color:var(--text-secondary); background:var(--bg-surface-soft);
           border:1px solid var(--border-light); padding:11px 14px; margin:12px 0 0; max-width:820px; }
.au-flow a { color:var(--color-primary); font-weight:700; }
.au-2fa-choice { display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:14px; margin-top:12px; }
.au-2fa-option { border:1px solid var(--border-light); padding:16px 18px; background:var(--bg-surface); }
.au-2fa-option h4 { margin:0 0 7px; font-size:14px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.au-2fa-option p { font-size:12.5px; line-height:1.6; color:var(--text-secondary); margin:0 0 12px; }
.au-2fa-best { font-size:9.5px; font-weight:700; letter-spacing:.07em; text-transform:uppercase;
               padding:2px 8px; background:rgba(46,125,50,.12); color:var(--color-success); }
.au-2fa-option button[disabled] { opacity:.55; cursor:not-allowed; }
</style>

<?php require_once 'includes/footer.php'; ?>
