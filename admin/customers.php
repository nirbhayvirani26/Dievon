<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

// Moved ABOVE the POST handlers below.
// It used to sit after them, and after the page had already rendered — so a
// staff account without this permission ran the INSERT/DELETE first and was
// only shown the 403 afterwards. The record was already gone by then.
requireAdminCapability('customers.view');

$activeTab = 'customers';
$successMsg = '';
$errorMsg   = '';

// Handle Delete Customer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_customer'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Security token expired. Please refresh and try again.';
    } else {
        // Destroying an account is a different job from looking one up. The page
        // gate above stays on customers.view so clerks keep read access.
        requireAdminCapability('customers.manage', true);
        $delId = (int)$_POST['delete_customer'];
        try {
            $pdo->prepare("DELETE FROM customers WHERE id = :id")->execute(['id' => $delId]);
            logAdminAction($_SESSION['admin_id'] ?? 0, 'customer_deleted', "Customer #{$delId} deleted");
            $successMsg = "Customer account deleted successfully.";
        } catch (PDOException $e) {
            $errorMsg = "Error deleting customer: " . $e->getMessage();
        }
    }
}

// Handle Delete Inquiry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_inquiry'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Security token expired. Please refresh and try again.';
    } else {
        requireAdminCapability('customers.manage', true);
        try {
            if ($_POST['delete_inquiry'] === 'all') {
                // "Delete everything" is the one action here with no undo, and it
                // was reachable by any account holding customers.view. Logged so
                // there is at least a record of who emptied it and when.
                $wiped = $pdo->query("SELECT COUNT(*) FROM inquiries")->fetchColumn();
                $pdo->exec("DELETE FROM inquiries");
                logAdminAction($_SESSION['admin_id'] ?? 0, 'inquiries_purged', "Deleted ALL {$wiped} customer enquiries");
                $successMsg = "All customer inquiries deleted.";
            } else {
                $delId = (int)$_POST['delete_inquiry'];
                $pdo->prepare("DELETE FROM inquiries WHERE id = :id")->execute(['id' => $delId]);
                logAdminAction($_SESSION['admin_id'] ?? 0, 'inquiry_deleted', "Enquiry #{$delId} deleted");
                $successMsg = "Inquiry message deleted.";
            }
        } catch (PDOException $e) {
            $errorMsg = "Error deleting inquiry: " . $e->getMessage();
        }
    }
}

// Fetch Customers
$customers = [];
try {
    $customers = $pdo->query("SELECT * FROM customers ORDER BY id DESC")->fetchAll();

    /* Where each customer actually receives parcels.
       ────────────────────────────────────────────────────────────────────────
       Read from ORDERS, not from customers.address. Only 4 of 15 accounts carry
       any address at all and none of them a separate postcode, while 140 of 141
       orders have one — because checkout demands it and a profile field does not.
       A delivery postcode is also better evidence: it is somewhere a parcel was
       genuinely sent, not something typed once at signup and never revisited.

       Matched on customer_id OR email, because an order placed as a guest before
       the account existed carries the email but no id, and that is still the
       same person moving to the same address.

       One query for the whole page. Per customer it would be one round trip each,
       and this list is the entire customer base. */
    $custRegions = [];      // customer id => ['380' => true, ...]
    $custPostcodes = [];    // customer id => ['380001' => true, ...]
    $regionCounts = [];     // '380' => how many customers
    try {
        $ordRows = $pdo->query(
            "SELECT o.customer_id, LOWER(o.customer_email) AS email, o.postcode
               FROM orders o
              WHERE o.postcode IS NOT NULL AND o.postcode <> ''"
        )->fetchAll(PDO::FETCH_ASSOC);

        $byEmail = [];
        $byId    = [];
        foreach ($customers as $c) {
            $byEmail[mb_strtolower(trim((string)$c['email']))] = (int)$c['id'];
            $byId[(int)$c['id']] = true;
        }

        foreach ($ordRows as $o) {
            $cid = (int)($o['customer_id'] ?? 0);
            if ($cid <= 0) { $cid = $byEmail[$o['email']] ?? 0; }
            if ($cid <= 0) { continue; }                 // a guest who never registered
            /* The id has to belong to a customer still on this page.
               ────────────────────────────────────────────────────────────────
               Orders outlive the accounts that placed them — two here point at
               customers deleted since. Counting those made the dropdown promise
               "400xxx — 4 customers" while filtering to it showed 2, because the
               label was counting people the list cannot show. A filter whose own
               label disagrees with it is worse than no label. */
            if (!isset($byId[$cid])) { continue; }

            $pc = strtoupper(preg_replace('/\s+/', '', (string)$o['postcode']));
            if ($pc === '') { continue; }
            $custPostcodes[$cid][$pc] = true;
            // First three digits of an Indian PIN are the sorting region, which is
            // why 380001 and 380052 belong together — both Ahmedabad.
            $custRegions[$cid][substr($pc, 0, 3)] = true;
        }
        foreach ($custRegions as $cid => $regions) {
            foreach (array_keys($regions) as $r) { $regionCounts[$r] = ($regionCounts[$r] ?? 0) + 1; }
        }
        arsort($regionCounts);
    } catch (PDOException $e) { /* no orders table — the list still works */ }
} catch (PDOException $e) {}

// Fetch Inquiries
$inquiries = [];
try {
    $inquiries = $pdo->query("SELECT * FROM inquiries ORDER BY id DESC")->fetchAll();
} catch (PDOException $e) {}

// This page renders its own richer <div class="admin-page-header"> below
// (icon, specific title, detailed subtitle, action buttons), so suppress the
// generic one in includes/header.php — otherwise both draw and the page shows
// two titles. Same pattern as product_form.php.
$hideHeaderTitle = true;
require_once __DIR__ . '/includes/header.php';
// Role check. The nav hides links this account cannot use, but the URL is
// still typeable and a handler still accepts a POST — so permission is
// decided here, on the server, every time.
require_once __DIR__ . '/../config/config.php';

?>

<div class="admin-page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
    <div>
        <h1 class="admin-page-title">👥 Customer Accounts &amp; Inquiries</h1>
        <p class="admin-page-subtitle">Manage registered buyer accounts and website contact messages.</p>
    </div>
</div>

<?php if ($successMsg): ?>
    <?= dvNotice(htmlspecialchars($successMsg), 'success') ?>
<?php endif; ?>

<?php if ($errorMsg): ?>
    <?= dvNotice(htmlspecialchars($errorMsg), 'danger') ?>
<?php endif; ?>

<!-- ══ SECTION 1: Registered Customer Accounts ════════════════════════ -->
<div class="glass-panel" style="padding:0; overflow:hidden; margin-bottom:32px;">
    <?php /* A <button> and not a clickable <div>: it is reached by Tab, fires on
             Enter and Space without any extra JavaScript, and announces itself as
             a button with an expanded state. aria-expanded is what a screen reader
             reads to say whether the section is open. */ ?>
    <div style="padding:18px 24px; border-bottom:1px solid var(--border-light); font-weight:700; font-size:15px; color:var(--text-primary); display:flex; justify-content:space-between; align-items:center;">
        <button type="button" class="cx-toggle" data-panel="cxCustomers" aria-expanded="true" aria-controls="cxCustomers">
            <i class="fa-solid fa-chevron-down cx-toggle-caret"></i>
            <span>👤 Registered Customers (<?= count($customers) ?>)</span>
        </button>
    </div>

    <div class="cx-panel" id="cxCustomers">
        <div class="cx-toolbar">
            <div class="cx-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" id="cxCustomerSearch" class="form-control"
                       placeholder="Search name, email, phone, address or postcode&hellip;" autocomplete="off">
            </div>

            <?php if (!empty($regionCounts)): ?>
            <?php /* Built from the postcodes people have actually ordered to, so every
                     option has someone behind it — an empty option is a dead end you
                     only discover by clicking it. Commonest region first, because that
                     is the one you want most often. */ ?>
            <select id="cxRegionFilter" class="form-control cx-select">
                <option value="all">All regions</option>
                <?php foreach ($regionCounts as $rPrefix => $rCount): ?>
                <option value="<?= htmlspecialchars($rPrefix) ?>">
                    <?= htmlspecialchars($rPrefix) ?>xxx &mdash; <?= (int)$rCount ?> customer<?= $rCount === 1 ? '' : 's' ?>
                </option>
                <?php endforeach; ?>
                <option value="none">No orders yet</option>
            </select>
            <?php endif; ?>

            <span class="cx-count" id="cxCustomerCount"></span>
        </div>
        <p class="cx-empty" id="cxCustomerNone" hidden>Nothing matches that search.</p>
    <div class="table-wrapper">
        <table class="data-table" style="width:100%;">
            <thead>
                <tr>
                    <?php /* Click a heading to sort, click again to reverse. Address and
                             Actions are not sortable: one is free text nobody orders by,
                             the other holds buttons. */ ?>
                    <th style="width:50px;" class="cx-sort-th" data-sort="id">ID</th>
                    <th class="cx-sort-th" data-sort="name">Customer Name</th>
                    <th class="cx-sort-th" data-sort="email">Email Address</th>
                    <th class="cx-sort-th" data-sort="phone">Phone</th>
                    <th>Delivery Address</th>
                    <th class="cx-sort-th" data-sort="joined">Joined Date</th>
                    <th style="width:100px; text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                    <tr><td colspan="7" style="padding:30px; text-align:center; color:var(--text-muted);">No registered customer accounts found.</td></tr>
                <?php else: ?>
                    <?php foreach ($customers as $c): ?>
                        <?php
                        /* Written by PHP onto the row, not scraped from the cells:
                           the cells are formatted for a person — "#12", "24 Aug 2026" —
                           and turning those back into a number or a date is where a
                           sort starts disagreeing with what is on screen. */
                        $cxJoined = strtotime((string)($c['created_at'] ?? '')) ?: 0;
                        $cxRegions = array_keys($custRegions[(int)$c['id']] ?? []);
                        $cxPost    = array_keys($custPostcodes[(int)$c['id']] ?? []);
                        // Postcodes join the haystack so typing 380001 finds the
                        // people who order there, not just an address that spells it.
                        $cxHay = mb_strtolower(trim(implode(' ', array_merge([
                            $c['name'] ?? '', $c['email'] ?? '', $c['phone'] ?? '', $c['address'] ?? ''
                        ], $cxPost))));
                        ?>
                        <tr class="cx-row"
                            data-id="<?= (int)$c['id'] ?>"
                            data-name="<?= htmlspecialchars(mb_strtolower($c['name'] ?? '')) ?>"
                            data-email="<?= htmlspecialchars(mb_strtolower($c['email'] ?? '')) ?>"
                            data-phone="<?= htmlspecialchars($c['phone'] ?? '') ?>"
                            data-joined="<?= $cxJoined ?>"
                            data-regions="<?= htmlspecialchars(implode(' ', $cxRegions)) ?>"
                            data-haystack="<?= htmlspecialchars($cxHay) ?>">
                            <td style="color:var(--text-muted); font-size:12px;">#<?= $c['id'] ?></td>
                            <td><strong style="color:var(--text-primary); font-size:14px;"><?= htmlspecialchars($c['name']) ?></strong></td>
                            <td><a href="mailto:<?= htmlspecialchars($c['email']) ?>" style="color:var(--color-primary); text-decoration:none; font-weight:600; font-size:13px;"><?= htmlspecialchars($c['email']) ?></a></td>
                            <td style="font-size:13px; color:var(--text-secondary);"><?= htmlspecialchars($c['phone'] ?? 'N/A') ?></td>
                            <td style="font-size:12px; color:var(--text-muted); max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= htmlspecialchars($c['address'] ?? 'N/A') ?></td>
                            <td style="font-size:12px; color:var(--text-muted);"><?= date('d M Y', strtotime($c['created_at'])) ?></td>
                            <td style="text-align:right;">
                                <div class="admin-actions">
                                    <form method="POST" action="customers.php" style="display:inline;" onsubmit="return dvConfirmForm(this,'Delete this customer account?');">
                                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                        <input type="hidden" name="delete_customer" value="<?= $c['id'] ?>">
                                        <button type="submit" class="admin-action-btn is-danger" title="Delete customer" aria-label="Delete customer <?= htmlspecialchars($c['name'] ?? $c['email']) ?>">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    </div><!-- /.cx-panel -->
</div>

<!-- ══ SECTION 2: Customer Contact Inquiries ═════════════════════════ -->
<div class="glass-panel" style="padding:0; overflow:hidden;">
    <?php /* The Clear All form stays OUTSIDE the toggle button. A <form> or a
             second <button> nested inside a button is invalid, and browsers
             recover from it by moving the markup — which is how a delete control
             ends up somewhere nobody put it. */ ?>
    <div style="padding:18px 24px; border-bottom:1px solid var(--border-light); font-weight:700; font-size:15px; color:var(--text-primary); display:flex; justify-content:space-between; align-items:center;">
        <button type="button" class="cx-toggle" data-panel="cxInquiries" aria-expanded="false" aria-controls="cxInquiries">
            <i class="fa-solid fa-chevron-down cx-toggle-caret"></i>
            <span>📬 Contact Form Inquiries (<?= count($inquiries) ?>)</span>
        </button>
        <?php if (!empty($inquiries)): ?>
        <form method="POST" action="customers.php" style="display:inline;" onsubmit="return dvConfirmForm(this,'Delete ALL inquiries?');">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="delete_inquiry" value="all">
            <button type="submit" style="color:#ef4444; font-size:12px; font-weight:700; text-decoration:none; background:none; border:none; cursor:pointer;">
                <i class="fa-solid fa-trash"></i> Clear All
            </button>
        </form>
        <?php endif; ?>
    </div>

    <div class="cx-panel" id="cxInquiries">
        <?php if (!empty($inquiries)): ?>
        <div class="cx-toolbar">
            <div class="cx-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" id="cxInquirySearch" class="form-control"
                       placeholder="Search name, email, phone or message&hellip;" autocomplete="off">
            </div>
            <?php /* A dropdown rather than clickable headings, because these are
                     cards and there are no column headings to click. */ ?>
            <select id="cxInquirySort" class="form-control cx-select">
                <option value="date_desc">Newest first</option>
                <option value="date_asc">Oldest first</option>
                <option value="name_asc">Name (A&ndash;Z)</option>
                <option value="name_desc">Name (Z&ndash;A)</option>
            </select>
            <span class="cx-count" id="cxInquiryCount"></span>
        </div>
        <p class="cx-empty" id="cxInquiryNone" hidden>Nothing matches that search.</p>
        <?php endif; ?>

    <div style="padding:20px;">
        <?php if (empty($inquiries)): ?>
            <div style="text-align:center; padding:40px 20px; color:var(--text-muted);">
                <div style="font-size:48px; margin-bottom:12px; opacity:0.3;">📭</div>
                <p style="font-size:14px; margin:0;">No inquiries received yet.</p>
            </div>
        <?php else: ?>
            <div style="display:flex; flex-direction:column; gap:16px;">
                <?php foreach ($inquiries as $inq): ?>
                <?php
                /* Same idea as the customer rows: the values search and sort read
                   are written here, where the real data is, rather than parsed
                   back out of the formatted card. */
                $inqWhen = strtotime((string)($inq['created_at'] ?? '')) ?: 0;
                $inqHay  = mb_strtolower(trim(implode(' ', [
                    $inq['name'] ?? '', $inq['email'] ?? '', $inq['phone'] ?? '', $inq['message'] ?? ''
                ])));
                ?>
                <div class="cx-inq"
                     data-name="<?= htmlspecialchars(mb_strtolower($inq['name'] ?? '')) ?>"
                     data-when="<?= $inqWhen ?>"
                     data-haystack="<?= htmlspecialchars($inqHay) ?>"
                     style="background:var(--bg-surface-soft); border-radius:8px; padding:18px 20px; border:1px solid var(--border-light); position:relative;">
                    <form method="POST" action="customers.php" style="position:absolute; top:16px; right:16px;" onsubmit="return dvConfirmForm(this,'Delete this inquiry?');">
                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                        <input type="hidden" name="delete_inquiry" value="<?= $inq['id'] ?>">
                        <button type="submit" style="color:#ef4444; font-size:13px; background:none; border:none; cursor:pointer;" title="Delete">
                            <i class="fa-solid fa-trash-can"></i>
                        </button>
                    </form>
                    <div style="display:flex; align-items:center; gap:12px; margin-bottom:10px;">
                        <div style="width:36px; height:36px; border-radius:50%; background:#2b080f; color:#ffffff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:14px;">
                            <?= strtoupper(mb_substr($inq['name'], 0, 1)) ?>
                        </div>
                        <div>
                            <strong style="font-size:15px; color:var(--text-primary); display:block;"><?= htmlspecialchars($inq['name']) ?></strong>
                            <span style="font-size:12px; color:var(--text-muted);"><?= date('d M Y, H:i', strtotime($inq['created_at'])) ?></span>
                        </div>
                    </div>
                    <div style="font-size:13px; color:var(--text-secondary); margin-bottom:10px; display:flex; gap:16px;">
                        <span>📧 <a href="mailto:<?= htmlspecialchars($inq['email']) ?>" style="color:var(--color-primary); font-weight:600; text-decoration:none;"><?= htmlspecialchars($inq['email']) ?></a></span>
                        <?php if (!empty($inq['phone'])): ?>
                            <span>📞 <?= htmlspecialchars($inq['phone']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="background:#ffffff; border-radius:6px; padding:12px 14px; font-size:13px; color:#334155; border-left:3px solid #2b080f;">
                        <?= nl2br(htmlspecialchars($inq['message'])) ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    </div><!-- /.cx-panel -->
</div>

<script>
/* Collapsible panels, plus search and sort inside each.
   ────────────────────────────────────────────────────────────────────────────
   Both sections were open at all times, so reaching the enquiries meant
   scrolling past every customer. They fold now, and which ones you left open is
   remembered — otherwise you re-open the same panel on every visit, which is
   worse than not having the control at all.

   Everything reads data- attributes written by PHP. The cells are formatted for
   a person ("#12", "24 Aug 2026"); parsing those back into numbers and dates is
   how a sort starts disagreeing with the table it is sorting. */
(function () {
    'use strict';

    /* ── open / closed, remembered ───────────────────────────────────────── */
    var KEY = 'dv_customers_panels';
    var saved = {};
    try { saved = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { saved = {}; }

    document.querySelectorAll('.cx-toggle').forEach(function (btn) {
        var id    = btn.getAttribute('data-panel');
        var panel = document.getElementById(id);
        if (!panel) { return; }

        // A stored choice wins; otherwise the markup's own aria-expanded decides,
        // which is how Customers starts open and Inquiries starts closed.
        var open = Object.prototype.hasOwnProperty.call(saved, id)
                 ? !!saved[id]
                 : btn.getAttribute('aria-expanded') === 'true';

        var paint = function () {
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            panel.hidden = !open;
        };
        paint();

        btn.addEventListener('click', function () {
            open = !open;
            paint();
            saved[id] = open;
            try { localStorage.setItem(KEY, JSON.stringify(saved)); } catch (e) { /* private mode */ }
        });
    });

    /* ── Registered Customers: search + sortable columns ─────────────────── */
    (function () {
        var table = document.querySelector('#cxCustomers table');
        if (!table) { return; }
        var body   = table.querySelector('tbody');
        var rows   = Array.prototype.slice.call(body.querySelectorAll('tr.cx-row'));
        if (!rows.length) { return; }
        var search = document.getElementById('cxCustomerSearch');
        var region = document.getElementById('cxRegionFilter');
        var count  = document.getElementById('cxCustomerCount');
        var none   = document.getElementById('cxCustomerNone');

        var key = 'joined', dir = -1;          // newest first, as the page always was
        var val = function (r) {
            var v = r.getAttribute('data-' + key) || '';
            return (key === 'id' || key === 'joined') ? (parseInt(v, 10) || 0) : v;
        };

        function apply() {
            var q  = (search && search.value || '').trim().toLowerCase();
            var rg = region ? region.value : 'all';
            var shown = 0;

            rows.forEach(function (r) {
                var regions = (r.getAttribute('data-regions') || '').split(' ').filter(Boolean);

                /* A customer belongs to EVERY region they have ordered to, not one:
                   somebody who ships to home and to work is genuinely in both, and
                   picking one would quietly lose them from the other. "No orders
                   yet" is its own choice rather than being folded into a region,
                   because an account that has never bought is a different thing
                   from one that buys elsewhere. */
                var okRegion = rg === 'all'
                    || (rg === 'none' ? regions.length === 0 : regions.indexOf(rg) !== -1);

                var okText = !q || (r.getAttribute('data-haystack') || '').indexOf(q) !== -1;
                var ok = okRegion && okText;
                r.style.display = ok ? '' : 'none';
                if (ok) { shown++; }
            });

            rows.slice().sort(function (a, b) {
                var x = val(a), y = val(b);
                if (typeof x === 'number') { return (x - y) * dir; }
                return String(x).localeCompare(String(y)) * dir;
            }).forEach(function (r) { body.appendChild(r); });

            if (count) { count.textContent = shown === rows.length
                ? rows.length + ' customer' + (rows.length === 1 ? '' : 's')
                : shown + ' of ' + rows.length; }
            if (none) { none.hidden = shown !== 0; }

            table.querySelectorAll('.cx-sort-th').forEach(function (th) {
                var on = th.getAttribute('data-sort') === key;
                th.classList.toggle('is-sorted', on);
                th.setAttribute('data-dir', on && dir === -1 ? 'desc' : 'asc');
            });
        }

        table.querySelectorAll('.cx-sort-th').forEach(function (th) {
            th.addEventListener('click', function () {
                var k = th.getAttribute('data-sort');
                // same column again turns it round; a new column starts ascending,
                // except the date, where "most recent first" is what you want.
                dir = (k === key) ? -dir : (k === 'joined' ? -1 : 1);
                key = k;
                apply();
            });
        });
        if (search) { search.addEventListener('input', apply); }
        if (region) { region.addEventListener('change', apply); }
        apply();
    })();

    /* ── Contact Form Inquiries: search + sort ───────────────────────────── */
    (function () {
        var list = document.querySelector('#cxInquiries .cx-inq');
        if (!list) { return; }
        var wrap   = list.parentElement;
        var cards  = Array.prototype.slice.call(wrap.querySelectorAll('.cx-inq'));
        var search = document.getElementById('cxInquirySearch');
        var sort   = document.getElementById('cxInquirySort');
        var count  = document.getElementById('cxInquiryCount');
        var none   = document.getElementById('cxInquiryNone');

        var ORDER = {
            date_desc: function (a, b) { return (+b.dataset.when) - (+a.dataset.when); },
            date_asc:  function (a, b) { return (+a.dataset.when) - (+b.dataset.when); },
            name_asc:  function (a, b) { return a.dataset.name.localeCompare(b.dataset.name); },
            name_desc: function (a, b) { return b.dataset.name.localeCompare(a.dataset.name); }
        };

        function apply() {
            var q = (search && search.value || '').trim().toLowerCase();
            var shown = 0;
            cards.forEach(function (c) {
                var ok = !q || (c.getAttribute('data-haystack') || '').indexOf(q) !== -1;
                c.style.display = ok ? '' : 'none';
                if (ok) { shown++; }
            });
            var cmp = ORDER[sort && sort.value] || ORDER.date_desc;
            cards.slice().sort(cmp).forEach(function (c) { wrap.appendChild(c); });

            if (count) { count.textContent = shown === cards.length
                ? cards.length + ' enquir' + (cards.length === 1 ? 'y' : 'ies')
                : shown + ' of ' + cards.length; }
            if (none) { none.hidden = shown !== 0; }
        }

        if (search) { search.addEventListener('input', apply); }
        if (sort)   { sort.addEventListener('change', apply); }
        apply();
    })();
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
