<?php
$activeTab = 'revenue';
require_once 'includes/header.php';
?>
        
        <?php
            $rFrom = $revData['from'] ?? date('Y-m-01');
            $rTo   = $revData['to']   ?? date('Y-m-d');
        ?>
        <!-- Summary Cards -->
        <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:28px;">
            <div class="stat-card glass-panel" style="border-left:4px solid var(--color-primary);">
                <div class="stat-card-icon">💷</div>
                <div class="stat-label">Total Revenue</div>
                <div class="stat-value" style="font-size:22px; color:var(--color-primary);">£<?= number_format($revData['total'] ?? 0, 2) ?></div>
            </div>
            <div class="stat-card glass-panel" style="border-left:4px solid #10b981;">
                <div class="stat-card-icon">💳</div>
                <div class="stat-label">Online (Card)</div>
                <div class="stat-value" style="font-size:22px; color:#10b981;">£<?= number_format($revData['online'] ?? 0, 2) ?></div>
            </div>
            <div class="stat-card glass-panel" style="border-left:4px solid #f59e0b;">
                <div class="stat-card-icon">💵</div>
                <div class="stat-label">Cash</div>
                <div class="stat-value" style="font-size:22px; color:#f59e0b;">£<?= number_format($revData['cash'] ?? 0, 2) ?></div>
            </div>
            <div class="stat-card glass-panel" style="border-left:4px solid #ef4444;">
                <div class="stat-card-icon">⏳</div>
                <div class="stat-label">Unpaid (<?= $revData['unpaid_count'] ?? 0 ?> orders)</div>
                <div class="stat-value" style="font-size:22px; color:#ef4444;">£<?= number_format($revData['unpaid_total'] ?? 0, 2) ?></div>
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
                            <td style="padding:9px 0; text-align:right; font-weight:700; color:var(--color-primary);">£<?= number_format($cdata['revenue'], 2) ?></td>
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
