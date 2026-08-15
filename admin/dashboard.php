<?php
$activeTab = 'revenue';

// Role check runs BEFORE includes/header.php: that file starts the session
// and immediately prints the page chrome, so a check placed after it can
// still refuse the content but can no longer set a 403 — the headers are
// already sent and the browser is told 200 OK.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
requireAdminCapability('revenue.view');
require_once 'includes/header.php';

?>
        <?php
        // ── Email failure alert ──────────────────────────────────────────────
        // Deliveries are logged in email_logs with status 'failed'; until this banner
        // existed the only trace was a line in the server error log, so dozens of
        // failed order/review emails could pass unnoticed.
        $emailFailedCount = 0;
        $emailFailedRecent = [];
        try {
            $emailFailedCount = (int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE status = 'failed'")->fetchColumn();
            $emailFailedRecent = $pdo->query(
                "SELECT email_type, recipient, subject, error_message, created_at
                   FROM email_logs WHERE status = 'failed'
                   ORDER BY created_at DESC LIMIT 5"
            )->fetchAll();
        } catch (PDOException $e) {}
        if ($emailFailedCount > 0): ?>
        <div class="glass-panel" style="padding:16px 20px; margin-bottom:24px; border-left:4px solid #ef4444; background:#fef2f2;">
            <div style="font-weight:700; color:#b91c1c; margin-bottom:6px;">⚠️ <?= $emailFailedCount ?> email<?= $emailFailedCount === 1 ? '' : 's' ?> failed to send</div>
            <div style="font-size:13px; color:#7f1d1d; margin-bottom:8px;">
                Order confirmations, status updates and review requests are failing — check the SMTP settings in <code>.env</code> (MAIL_*) and the server error log.
            </div>
            <?php if ($emailFailedRecent): ?>
            <table style="width:100%; font-size:12px; border-collapse:collapse;">
                <thead>
                    <tr style="text-align:left; color:#b91c1c;">
                        <th style="padding:4px 8px;">When</th>
                        <th style="padding:4px 8px;">Type</th>
                        <th style="padding:4px 8px;">Recipient</th>
                        <th style="padding:4px 8px;">Error</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($emailFailedRecent as $ef): ?>
                    <tr style="border-top:1px solid #fecaca; color:#7f1d1d;">
                        <td style="padding:4px 8px; white-space:nowrap;"><?= htmlspecialchars(date('j M H:i', strtotime($ef['created_at']))) ?></td>
                        <td style="padding:4px 8px;"><?= htmlspecialchars($ef['email_type']) ?></td>
                        <td style="padding:4px 8px;"><?= htmlspecialchars($ef['recipient']) ?></td>
                        <td style="padding:4px 8px; max-width:320px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= htmlspecialchars($ef['error_message'] ?? '') ?>"><?= htmlspecialchars(mb_strimwidth((string)($ef['error_message'] ?? ''), 0, 90, '…')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php
            $rFrom = $revData['from'] ?? date('Y-m-01');
            $rTo   = $revData['to']   ?? date('Y-m-d');
        ?>
        <!-- Summary Cards -->
        <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:28px;">
            <div class="stat-card glass-panel" style="border-left:4px solid var(--color-primary);">
                <div class="stat-card-icon">💷</div>
                <div class="stat-label">Total Revenue</div>
                <div class="stat-value" style="font-size:22px; color:var(--color-primary);"><?= currencySymbol() ?><?= number_format($revData['total'] ?? 0, 2) ?></div>
            </div>
            <div class="stat-card glass-panel" style="border-left:4px solid #10b981;">
                <div class="stat-card-icon">💳</div>
                <div class="stat-label">Online (Card)</div>
                <div class="stat-value" style="font-size:22px; color:#10b981;"><?= currencySymbol() ?><?= number_format($revData['online'] ?? 0, 2) ?></div>
            </div>
            <div class="stat-card glass-panel" style="border-left:4px solid #f59e0b;">
                <div class="stat-card-icon">💵</div>
                <div class="stat-label">Cash</div>
                <div class="stat-value" style="font-size:22px; color:#f59e0b;"><?= currencySymbol() ?><?= number_format($revData['cash'] ?? 0, 2) ?></div>
            </div>
            <div class="stat-card glass-panel" style="border-left:4px solid #ef4444;">
                <div class="stat-card-icon">⏳</div>
                <div class="stat-label">Unpaid (<?= $revData['unpaid_count'] ?? 0 ?> orders)</div>
                <div class="stat-value" style="font-size:22px; color:#ef4444;"><?= currencySymbol() ?><?= number_format($revData['unpaid_total'] ?? 0, 2) ?></div>
            </div>
        </div>

        <!-- Date Filters + Download -->
        <div class="glass-panel" style="padding:20px 24px; margin-bottom:24px;">
            <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <span style="font-size:13px; font-weight:700; color:var(--text-secondary); white-space:nowrap;"><i class="fa-solid fa-calendar"></i> Filter:</span>
                <?php
                $today      = date('Y-m-d');
                $thisMonthStart = date('Y-m-01');
                $lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
                $lastMonthEnd   = date('Y-m-t', strtotime('last day of last month'));
                $weekStart  = date('Y-m-d', strtotime('monday this week'));
                $yearStart  = date('Y-01-01');
                $quickBtns  = [
                    'Today'      => [$today,          $today],
                    'This Week'  => [$weekStart,       $today],
                    'This Month' => [$thisMonthStart,  $today],
                    'Last Month' => [$lastMonthStart,  $lastMonthEnd],
                    'This Year'  => [$yearStart,       $today],
                ];
                foreach ($quickBtns as $label => [$f, $t]):
                    $active = ($rFrom === $f && $rTo === $t);
                ?>
                <a href="?tab=revenue&rev_from=<?= $f ?>&rev_to=<?= $t ?>"
                   class="btn-sm <?= $active ? 'btn-primary' : 'btn-sm-outline' ?>"><?= $label ?></a>
                <?php endforeach; ?>

                <form method="GET" action="dashboard.php" style="display:flex; align-items:center; gap:8px; margin-left:auto; flex-wrap:wrap;">
                    <input type="hidden" name="tab" value="revenue">
                    <input type="date" name="rev_from" value="<?= $rFrom ?>" class="form-control" style="height:36px; font-size:13px; width:150px;">
                    <span style="color:var(--text-muted); font-size:13px;">to</span>
                    <input type="date" name="rev_to"   value="<?= $rTo ?>"   class="form-control" style="height:36px; font-size:13px; width:150px;">
                    <button type="submit" class="btn-primary" style="height:36px; padding:0 16px; font-size:13px;"><i class="fa-solid fa-filter"></i> Apply</button>
                </form>

                <a href="revenue_report.php?from=<?= $rFrom ?>&to=<?= $rTo ?>" class="btn-sm btn-sm-outline" style="white-space:nowrap;">
                    <i class="fa-solid fa-download"></i> Download CSV
                </a>
                <a href="gst_report.php?from=<?= $rFrom ?>&to=<?= $rTo ?>" class="btn-sm btn-sm-outline" style="white-space:nowrap;">
                    <i class="fa-solid fa-receipt"></i> GST Summary
                </a>
            </div>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-bottom:24px;">
            <!-- Category Revenue Table -->
            <div class="glass-panel" style="padding:24px;">
                <h3 style="font-size:15px; font-weight:700; margin:0 0 16px; display:flex; align-items:center; gap:8px;">
                    <i class="fa-solid fa-tags" style="color:var(--color-primary);"></i> Revenue by Category
                    <span style="font-size:11px; color:var(--text-muted); font-weight:400;"><?= date('d M', strtotime($rFrom)) ?> – <?= date('d M Y', strtotime($rTo)) ?></span>
                </h3>
                <?php if (empty($revData['cat_revenue'])): ?>
                <p style="color:var(--text-muted); font-size:13px;">No paid orders in this period.</p>
                <?php else: ?>
                <div class="table-wrapper">
                    <table style="width:100%; border-collapse:collapse; font-size:13px; min-width: 600px;">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border-light);">
                            <th style="padding:6px 0; text-align:left; font-size:11px; text-transform:uppercase; color:var(--text-muted);">Category</th>
                            <th style="padding:6px 0; text-align:right; font-size:11px; text-transform:uppercase; color:var(--text-muted);">Revenue</th>
                            <th style="padding:6px 0; text-align:right; font-size:11px; text-transform:uppercase; color:var(--text-muted);">Qty Sold</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                        $totalCatRev = array_sum(array_column($revData['cat_revenue'], 'revenue'));
                        foreach ($revData['cat_revenue'] as $cat => $cdata):
                            $pct = $totalCatRev > 0 ? round($cdata['revenue'] / $totalCatRev * 100) : 0;
                    ?>
                        <tr style="border-bottom:1px solid var(--border-light);">
                            <td style="padding:9px 0;">
                                <div style="font-weight:600;"><?= htmlspecialchars($cat) ?></div>
                                <div style="height:4px; background:rgba(var(--color-primary-rgb),0.15); border-radius:2px; margin-top:4px; width:100%;">
                                    <div style="height:4px; background:var(--color-primary); border-radius:2px; width:<?= $pct ?>%;"></div>
                                </div>
                            </td>
                            <td style="padding:9px 0; text-align:right; font-weight:700; color:var(--color-primary);"><?= currencySymbol() ?><?= number_format($cdata['revenue'], 2) ?></td>
                            <td style="padding:9px 0; text-align:right; color:var(--text-secondary);"><?= $cdata['qty'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Charts column -->
            <div style="display:flex; flex-direction:column; gap:20px;">
                <!-- Chart 1: All-time -->
                <div class="glass-panel" style="padding:20px;">
                    <h3 style="font-size:14px; font-weight:700; margin:0 0 12px; display:flex; align-items:center; gap:8px;">
                        <i class="fa-solid fa-chart-bar" style="color:#8b5cf6;"></i> All-time Top Products (by qty sold)
                    </h3>
                    <?php if (empty($revData['chart_alltime'])): ?>
                    <p style="color:var(--text-muted); font-size:13px;">No data yet.</p>
                    <?php else: ?>
                    <canvas id="chartAllTime" height="180"></canvas>
                    <?php endif; ?>
                </div>
                <!-- Chart 2: This month -->
                <div class="glass-panel" style="padding:20px;">
                    <h3 style="font-size:14px; font-weight:700; margin:0 0 12px; display:flex; align-items:center; gap:8px;">
                        <i class="fa-solid fa-chart-bar" style="color:#10b981;"></i> This Month's Products (by qty sold)
                    </h3>
                    <?php if (empty($revData['chart_thismonth'])): ?>
                    <p style="color:var(--text-muted); font-size:13px;">No sales this month yet.</p>
                    <?php else: ?>
                    <canvas id="chartThisMonth" height="180"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ══ COD ORDERS & HANDLING FEE REPORT ══════════════════ -->
        <?php
        $codTotals  = $revData['cod_totals']  ?? ['orders' => 0, 'order_value' => 0.0, 'fees' => 0.0, 'remitted_value' => 0.0, 'pending_value' => 0.0, 'remitted_count' => 0];
        $codMonthly = $revData['cod_monthly'] ?? [];
        $codOrders  = $revData['cod_orders']  ?? [];
        ?>
        <div class="glass-panel" style="padding:24px; margin-top:24px;">
            <h3 style="font-size:15px; font-weight:700; margin:0 0 16px; display:flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-money-bill-wave" style="color:#f59e0b;"></i> COD Orders &amp; Handling Fees
                <span style="font-size:11px; color:var(--text-muted); font-weight:400;"><?= date('d M', strtotime($rFrom)) ?> – <?= date('d M Y', strtotime($rTo)) ?></span>
            </h3>

            <!-- Summary chips -->
            <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:20px;">
                <div style="background:var(--bg-main); border:1px solid var(--border-light); border-radius:8px; padding:14px 16px;">
                    <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); font-weight:700;">COD Orders</div>
                    <div style="font-size:24px; font-weight:800; color:var(--color-primary); margin-top:4px;"><?= (int)$codTotals['orders'] ?></div>
                </div>
                <div style="background:var(--bg-main); border:1px solid var(--border-light); border-radius:8px; padding:14px 16px;">
                    <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); font-weight:700;">COD Order Value</div>
                    <div style="font-size:24px; font-weight:800; color:var(--color-primary); margin-top:4px;"><?= currencySymbol() ?><?= number_format((float)$codTotals['order_value'], 2) ?></div>
                </div>
                <div style="background:var(--bg-main); border:1px solid var(--border-light); border-radius:8px; padding:14px 16px;">
                    <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); font-weight:700;">Handling Fees Collected</div>
                    <div style="font-size:24px; font-weight:800; color:#f59e0b; margin-top:4px;"><?= currencySymbol() ?><?= number_format((float)$codTotals['fees'], 2) ?></div>
                </div>
                <div style="background:var(--bg-main); border:1px solid var(--border-light); border-radius:8px; padding:14px 16px;">
                    <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); font-weight:700;">Awaiting Courier Remittance</div>
                    <div style="font-size:24px; font-weight:800; color:#ef4444; margin-top:4px;"><?= currencySymbol() ?><?= number_format((float)$codTotals['pending_value'], 2) ?></div>
                </div>
            </div>

            <?php if (empty($codMonthly)): ?>
            <p style="color:var(--text-muted); font-size:13px;">No COD orders in this period.</p>
            <?php else: ?>

            <!-- Monthly breakdown -->
            <div class="table-wrapper">
                <table style="width:100%; border-collapse:collapse; font-size:13px; min-width:480px;">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border-light);">
                            <th style="padding:6px 0; text-align:left; font-size:11px; text-transform:uppercase; color:var(--text-muted);">Month</th>
                            <th style="padding:6px 0; text-align:right; font-size:11px; text-transform:uppercase; color:var(--text-muted);">Orders</th>
                            <th style="padding:6px 0; text-align:right; font-size:11px; text-transform:uppercase; color:var(--text-muted);">Order Value</th>
                            <th style="padding:6px 0; text-align:right; font-size:11px; text-transform:uppercase; color:var(--text-muted);">Fees</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($codMonthly as $mk => $m): ?>
                        <tr style="border-bottom:1px solid var(--border-light);">
                            <td style="padding:9px 0; font-weight:600;"><?= date('F Y', strtotime($mk . '-01')) ?></td>
                            <td style="padding:9px 0; text-align:right; color:var(--text-secondary);"><?= (int)$m['orders'] ?></td>
                            <td style="padding:9px 0; text-align:right; color:var(--text-secondary);"><?= currencySymbol() ?><?= number_format((float)$m['order_value'], 2) ?></td>
                            <td style="padding:9px 0; text-align:right; font-weight:700; color:#f59e0b;"><?= currencySymbol() ?><?= number_format((float)$m['fees'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p style="font-size:11px; color:var(--text-muted); margin:0 0 6px;">
                <i class="fa-solid fa-circle-info"></i>
                Includes COD orders not yet marked cash-received &mdash; the handling fee is owed at delivery, so it is counted whether or not the admin has recorded the cash.
            </p>

            <!-- Per-order list (most recent first, newest 30 shown) -->
            <details style="margin-top:12px;">
                <summary style="cursor:pointer; font-size:12px; font-weight:700; color:var(--color-secondary); text-transform:uppercase; letter-spacing:0.06em; user-select:none;">
                    <i class="fa-solid fa-list"></i> View individual COD orders <?= count($codOrders) > 30 ? '(latest 30 of ' . count($codOrders) . ')' : '(' . count($codOrders) . ')' ?>
                </summary>
                <div class="table-wrapper" style="margin-top:12px;">
                    <table style="width:100%; border-collapse:collapse; font-size:13px; min-width:680px;">
                        <thead>
                            <tr style="border-bottom:2px solid var(--border-light);">
                                <th style="padding:6px 0; text-align:left; font-size:11px; text-transform:uppercase; color:var(--text-muted);">Date</th>
                                <th style="padding:6px 0; text-align:left; font-size:11px; text-transform:uppercase; color:var(--text-muted);">Order</th>
                                <th style="padding:6px 0; text-align:left; font-size:11px; text-transform:uppercase; color:var(--text-muted);">Customer</th>
                                <th style="padding:6px 0; text-align:left; font-size:11px; text-transform:uppercase; color:var(--text-muted);">Email</th>
                                <th style="padding:6px 0; text-align:right; font-size:11px; text-transform:uppercase; color:var(--text-muted);">Total</th>
                                <th style="padding:6px 0; text-align:right; font-size:11px; text-transform:uppercase; color:var(--text-muted);">Fee</th>
                                <th style="padding:6px 0; text-align:right; font-size:11px; text-transform:uppercase; color:var(--text-muted);">Remittance</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach (array_slice($codOrders, 0, 30) as $o): ?>
                            <tr style="border-bottom:1px solid var(--border-light);">
                                <td style="padding:8px 0; color:var(--text-secondary); white-space:nowrap;"><?= date('d M Y', strtotime($o['created_at'])) ?></td>
                                <td style="padding:8px 0; font-weight:700; font-family:var(--font-heading); letter-spacing:0.5px; color:var(--color-primary);"><?= htmlspecialchars($o['order_code']) ?></td>
                                <td style="padding:8px 0;"><?= htmlspecialchars($o['customer_name']) ?></td>
                                <td style="padding:8px 0; color:var(--text-secondary); word-break:break-all;"><?= htmlspecialchars((string)($o['customer_email'] ?? '')) ?></td>
                                <td style="padding:8px 0; text-align:right; font-weight:600;"><?= currencySymbol() ?><?= number_format((float)$o['total_price'], 2) ?></td>
                                <td style="padding:8px 0; text-align:right; font-weight:700; color:#f59e0b;"><?= currencySymbol() ?><?= number_format((float)($o['cod_fee'] ?? 0), 2) ?></td>
                                <td style="padding:8px 0; text-align:right; white-space:nowrap;">
                                    <?php if (!empty($o['remitted_at'])): ?>
                                        <span style="font-size:11px; font-weight:700; color:#10b981;">✓ <?= currencySymbol() ?><?= number_format((float)($o['remitted_amount'] ?? 0), 2) ?></span>
                                        <span style="font-size:10px; color:var(--text-muted);"><?= date('d M', strtotime($o['remitted_at'])) ?></span>
                                    <?php else: ?>
                                        <span style="font-size:11px; font-weight:700; color:#ef4444;">⏳ Awaiting</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
            <?php endif; ?>
        </div>

        <!-- ═══════════════════ GALLERY TAB ══════════════════ -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const isDark = document.documentElement.dataset.theme !== 'light';
    const textColor = isDark ? 'rgba(255,255,255,0.7)' : 'rgba(0,0,0,0.6)';
    const gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.07)';

    Chart.defaults.color = textColor;
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.font.size   = 12;

    function buildChart(canvasId, labels, values, bgColor) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;
        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Qty Sold',
                    data: values,
                    backgroundColor: bgColor,
                    borderRadius: 6,
                    borderSkipped: false,
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: true,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { color: gridColor }, ticks: { precision: 0 } },
                    y: { grid: { display: false } }
                }
            }
        });
    }

    // Chart 1: All-time
    const atLabels = <?= json_encode(array_keys($revData['chart_alltime'] ?? [])) ?>;
    const atValues = <?= json_encode(array_values($revData['chart_alltime'] ?? [])) ?>;
    buildChart('chartAllTime', atLabels, atValues, 'rgba(139,92,246,0.7)');

    // Chart 2: This month
    const tmLabels = <?= json_encode(array_keys($revData['chart_thismonth'] ?? [])) ?>;
    const tmValues = <?= json_encode(array_values($revData['chart_thismonth'] ?? [])) ?>;
    buildChart('chartThisMonth', tmLabels, tmValues, 'rgba(16,185,129,0.7)');
});
</script>

<?php require_once 'includes/footer.php'; ?>
