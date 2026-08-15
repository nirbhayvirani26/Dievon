<?php
// ============================================================
//  Dievon – Admin: Roles & Permissions
//
//  This page used to be two lines that handed off to module_router.php, which
//  printed the title "Roles & Permissions Matrix" above an empty panel. There
//  was no matrix and nothing to configure: access came from three presets
//  hardcoded in config/config.php, so the only way to change what a staff
//  member could reach was to edit PHP on the server.
//
//  A role here is a named set of capabilities. Assign it to a staff member on
//  the Staff Accounts page; tick extra capabilities on that person there too if
//  they need something their role does not carry.
//
//  Owner-only, for the same reason the staff page is: anyone who can rewrite a
//  role can grant themselves the capability they were refused.
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
requireAdminCapability('staff.manage');
// The page is gated to match roles_handler.php. Without this it loaded for anyone
// holding staff.manage while every action returned 403 — a screen that appears to
// work and does nothing. Editing a role means editing what any role may do, so it
// belongs to the owner alone.
if (($_SESSION['admin_role'] ?? 'owner') !== 'owner') {
    http_response_code(403);
    exit('Only the shop owner can manage roles and permissions.');
}

$activeTab       = 'roles';
$hideHeaderTitle = true;
require_once 'includes/header.php';

// The tables arrive with update_new_database.php. Until that has been run this
// page has to say so rather than fatal — the rest of the panel keeps working,
// because adminCapabilitySet() falls back to the built-in presets.
$rolesReady = true;
$roles      = [];
try {
    $roles = $pdo->query("SELECT * FROM admin_roles ORDER BY is_system DESC, name ASC")->fetchAll();
} catch (PDOException $e) {
    $rolesReady = false;
}

// How many staff sit on each role, so deleting one can say what it will affect.
$roleUsage = [];
if ($rolesReady) {
    try {
        foreach ($pdo->query("SELECT role_id, COUNT(*) c FROM admin_users WHERE role_id IS NOT NULL GROUP BY role_id") as $r) {
            $roleUsage[(int)$r['role_id']] = (int)$r['c'];
        }
    } catch (PDOException $e) {}
}
?>

<div class="glass-panel" style="padding:28px;">
    <div class="admin-page-header" style="margin-bottom:22px;">
        <div>
            <h2 class="admin-page-title" style="margin:0;"><i class="fa-solid fa-user-shield"></i> Roles &amp; Permissions</h2>
            <p class="admin-page-subtitle" style="margin-top:4px;">
                Build a role once, then hand it to as many people as you like. Tick only what that job actually needs.
            </p>
            <?php // Said plainly, because the split is not obvious from either page on
                  // its own: a role has no username or password because a role is not a
                  // person — it is a job description that any number of people can hold. ?>
            <p class="rl-flow">
                <i class="fa-solid fa-circle-info"></i>
                A role is a <strong>job</strong>, not a person, so there is no username or password here.
                Set those on <a href="admin_users.php">Staff Accounts</a> — that is where you create the
                sign-in and choose which of these roles it gets.
            </p>
        </div>
    </div>

    <div id="rlMsg" class="au-msg" hidden></div>

    <?php if (!$rolesReady): ?>
        <div class="au-warn">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <strong>The roles tables do not exist yet.</strong>
            Open <code>update_new_database.php</code> and press the update button to create them.
            Until then every staff account keeps the built-in access it has today.
        </div>
    <?php else: ?>

    <?php foreach ($roles as $role):
        $caps     = json_decode((string)$role['capabilities'], true) ?: [];
        $isOwner  = $role['slug'] === 'owner';
        $isSystem = (int)$role['is_system'] === 1;
        $inUse    = $roleUsage[(int)$role['id']] ?? 0;
    ?>
    <div class="rl-role" data-role-id="<?= (int)$role['id'] ?>">
        <div class="rl-role-head">
            <div>
                <h3 class="rl-role-name">
                    <?= htmlspecialchars($role['name']) ?>
                    <?php if ($isSystem): ?><span class="au-pill">built-in</span><?php endif; ?>
                    <?php if ($inUse): ?><span class="au-pill"><?= $inUse ?> staff</span><?php endif; ?>
                </h3>
                <p class="rl-role-desc"><?= htmlspecialchars($role['description'] ?: '') ?></p>
            </div>
            <?php if (!$isSystem): ?>
            <button type="button" class="btn-secondary rl-del"
                    onclick="deleteRole(<?= (int)$role['id'] ?>, '<?= htmlspecialchars($role['name'], ENT_QUOTES) ?>', <?= $inUse ?>)">
                Delete
            </button>
            <?php endif; ?>
        </div>

        <?php if ($isOwner): ?>
            <?php // The owner role is deliberately not editable. Every safeguard on this
                  // page assumes at least one account can still reach it; a wildcard you
                  // can untick is a lockout waiting to happen. ?>
            <p class="rl-owner-note">
                <i class="fa-solid fa-lock"></i>
                The Owner role always holds every permission and cannot be edited. To limit
                somebody, give them a different role rather than trimming this one.
            </p>
        <?php else: ?>
            <div class="rl-cap-groups">
                <?php foreach (ADMIN_CAPABILITY_CATALOGUE as $groupName => $groupCaps): ?>
                <fieldset class="rl-cap-group">
                    <legend><?= htmlspecialchars($groupName) ?></legend>
                    <?php foreach ($groupCaps as $capKey => $capLabel): ?>
                    <label class="rl-cap">
                        <input type="checkbox" class="rl-cap-box" data-cap="<?= htmlspecialchars($capKey) ?>"
                               <?= in_array($capKey, $caps, true) ? 'checked' : '' ?>>
                        <span><?= htmlspecialchars($capLabel) ?></span>
                    </label>
                    <?php endforeach; ?>
                </fieldset>
                <?php endforeach; ?>
            </div>
            <div class="rl-role-actions">
                <button type="button" class="btn-primary" onclick="saveRole(<?= (int)$role['id'] ?>)">
                    <i class="fa-solid fa-floppy-disk"></i> Save <?= htmlspecialchars($role['name']) ?>
                </button>
                <?php // Where the role actually gets used. Saving capabilities and then
                      // wondering why nothing changed — because it is not on anybody yet —
                      // is the obvious next confusion, so answer it right here. ?>
                <a href="admin_users.php" class="rl-assign-link">
                    <?= $inUse
                        ? 'Held by ' . $inUse . ' staff member' . ($inUse === 1 ? '' : 's') . ' — manage on Staff Accounts →'
                        : 'Not given to anyone yet — assign it on Staff Accounts →' ?>
                </a>
            </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <h3 class="au-subhead">Create a role</h3>
    <div class="rl-newgrid">
        <input type="text" id="rlNewName" class="form-control" placeholder="Role name — e.g. Product Manager" maxlength="100">
        <input type="text" id="rlNewDesc" class="form-control" placeholder="What this role is for (optional)" maxlength="255">
        <button type="button" class="btn-primary" onclick="addRole()">Create</button>
    </div>
    <p class="rl-hint">A new role starts with nothing ticked. Create it, then choose its permissions above.</p>

    <?php endif; ?>
</div>

<style>
.rl-role { border:1px solid var(--border-light); border-radius:10px; padding:20px; margin-bottom:18px; background:var(--bg-surface-soft); }
.rl-role-head { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:14px; flex-wrap:wrap; }
.rl-role-name { margin:0; font-size:16px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.rl-role-desc { margin:4px 0 0; font-size:12.5px; color:var(--text-muted); }
.rl-owner-note { font-size:13px; color:var(--text-secondary); background:var(--bg-surface); border:1px dashed var(--border-strong); padding:12px 14px; border-radius:8px; margin:0; }
.rl-cap-groups { display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:14px; }
.rl-cap-group { border:1px solid var(--border-light); border-radius:8px; padding:12px 14px; background:var(--bg-surface); }
.rl-cap-group legend { font-size:11px; text-transform:uppercase; letter-spacing:.08em; font-weight:700; color:var(--color-primary); padding:0 6px; }
.rl-cap { display:flex; align-items:flex-start; gap:9px; font-size:13px; padding:5px 0; cursor:pointer; line-height:1.45; }
.rl-cap input { margin-top:2px; flex-shrink:0; }
.rl-role-actions { margin-top:14px; }
.rl-flow { font-size:13px; line-height:1.6; color:var(--text-secondary); background:var(--bg-surface-soft);
           border:1px solid var(--border-light); border-radius:8px; padding:11px 14px; margin:12px 0 0; max-width:760px; }
.rl-flow a { color:var(--color-primary); font-weight:700; }
.rl-assign-link { font-size:12.5px; color:var(--text-muted); }
.rl-assign-link:hover { color:var(--color-primary); }
.rl-newgrid { display:grid; grid-template-columns:1fr 1fr auto; gap:10px; align-items:center; }
.rl-hint { font-size:12px; color:var(--text-muted); margin-top:8px; }
@media (max-width: 700px) { .rl-newgrid { grid-template-columns:1fr; } }
</style>

<script>
// Same token the staff page uses. roles_handler.php rejects any POST without it,
// so it has to be attached to every request rather than only the destructive ones.
const RL_CSRF = '<?= generateCsrfToken() ?>';

function rlMsg(text, ok) {
    const box = document.getElementById('rlMsg');
    box.hidden = false;
    box.textContent = text;
    box.className = 'au-msg ' + (ok ? 'au-msg-ok' : 'au-msg-bad');
    box.scrollIntoView({behavior: 'smooth', block: 'nearest'});
}

async function rlPost(fd) {
    fd.append('csrf_token', RL_CSRF);
    const res  = await fetch('roles_handler.php', {method: 'POST', body: fd});
    const data = await res.json().catch(() => ({success: false, message: 'Server returned an unreadable response.'}));
    rlMsg(data.message || (data.success ? 'Saved.' : 'Something went wrong.'), !!data.success);
    return data;
}

async function saveRole(id) {
    const row  = document.querySelector('.rl-role[data-role-id="' + id + '"]');
    const caps = [...row.querySelectorAll('.rl-cap-box:checked')].map(b => b.dataset.cap);
    const fd   = new FormData();
    fd.append('action', 'save_role');
    fd.append('role_id', id);
    fd.append('capabilities', JSON.stringify(caps));
    await rlPost(fd);
}

async function addRole() {
    const name = document.getElementById('rlNewName').value.trim();
    if (!name) { rlMsg('Give the role a name first.', false); return; }
    const fd = new FormData();
    fd.append('action', 'add_role');
    fd.append('name', name);
    fd.append('description', document.getElementById('rlNewDesc').value.trim());
    const data = await rlPost(fd);
    if (data.success) { setTimeout(() => location.reload(), 600); }
}

async function deleteRole(id, name, inUse) {
    // A role still held by somebody cannot be deleted — the server refuses it,
    // because falling those accounts back to their built-in preset would WIDEN
    // their access rather than remove it. Say so here instead of letting them
    // click through to a rejection.
    if (inUse > 0) {
        alert('"' + name + '" is still held by ' + inUse + ' staff account' + (inUse === 1 ? '' : 's') + '.\n\n'
            + 'Move ' + (inUse === 1 ? 'that person' : 'them') + ' to another role on Staff Accounts first. '
            + 'Deleting it now would give ' + (inUse === 1 ? 'them' : 'them') + ' the default access, which is wider than this role.');
        return;
    }
    // dievonConfirm, not the browser's confirm() — the shop has its own dialog and
    // every other delete uses it. It returns a promise, and this function is already
    // async, so awaiting it keeps the rest of the flow exactly as it was.
    const ok = await dievonConfirm(
        'Any staff account still on this role falls back to the default access, which is wider than the role you are removing.',
        { title: 'Delete the role "' + name + '"?', confirmText: 'Delete role', danger: true }
    );
    if (!ok) { return; }
    const fd = new FormData();
    fd.append('action', 'delete_role');
    fd.append('role_id', id);
    const data = await rlPost(fd);
    if (data.success) { setTimeout(() => location.reload(), 600); }
}
</script>

<?php require_once 'includes/footer.php'; ?>
