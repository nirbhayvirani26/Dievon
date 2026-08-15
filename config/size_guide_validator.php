<?php
// ============================================================
//  Size guide sanity checks
//
//  These WARN, they never block. An unusual sizing run can be legitimate,
//  so the admin always keeps the final say — the job here is to make a
//  typo impossible to miss, not to argue with the person entering it.
//
//  The highest-value rule is the ladder check: measurements must increase
//  from XS upward. Nearly every realistic slip breaks that ordering —
//  340 for 34, 3.4 for 34, a digit dropped, two cells swapped — so it
//  catches far more than a plain range test would.
// ============================================================

/**
 * @param array  $rows  [{measurement_type,size_label,bust,waist,hips,shoulder,length}, ...]
 * @param string $unit  'in' | 'cm'
 * @param array  $ladder ordered size codes, smallest first
 * @return array{warnings: string[], cells: string[]}
 */
function validateSizeGuideRows(array $rows, string $unit = 'in', array $ladder = []): array {
    if (!$ladder) {
        $ladder = array_column(require __DIR__ . '/size_ladder.php', 'code');
    }
    $order = array_flip($ladder);

    // Plausible human measurements. Deliberately wide — this is a typo net,
    // not a fashion opinion.
    $range = $unit === 'cm'
        ? ['bust' => [50, 200], 'waist' => [40, 200], 'hips' => [50, 220], 'shoulder' => [25, 70],  'length' => [20, 200]]
        : ['bust' => [20, 80],  'waist' => [16, 80],  'hips' => [20, 90],  'shoulder' => [10, 28],  'length' => [8, 80]];

    $fields   = ['bust', 'waist', 'hips', 'shoulder', 'length'];
    $warnings = [];
    $cells    = [];

    // Group by measurement tab, keeping ladder order.
    $byType = [];
    foreach ($rows as $r) {
        $type = ($r['measurement_type'] ?? 'body') === 'garment' ? 'garment' : 'body';
        $byType[$type][] = $r;
    }

    foreach ($byType as $type => $typeRows) {
        usort($typeRows, function ($a, $b) use ($order) {
            return ($order[$a['size_label'] ?? ''] ?? 99) <=> ($order[$b['size_label'] ?? ''] ?? 99);
        });
        $label = $type === 'garment' ? 'Garment' : 'Body';

        foreach ($fields as $f) {
            $prevVal = null;
            $prevSize = null;
            foreach ($typeRows as $r) {
                $size = (string)($r['size_label'] ?? '?');
                $raw  = $r[$f] ?? null;
                if ($raw === null || $raw === '' ) { continue; }
                if (!is_numeric($raw)) {
                    $warnings[] = "{$label} · {$size} · {$f}: \"{$raw}\" is not a number.";
                    $cells[] = "{$type}|{$size}|{$f}";
                    continue;
                }
                $v = (float)$raw;

                // 1. plausible range
                [$lo, $hi] = $range[$f];
                if ($v < $lo || $v > $hi) {
                    $warnings[] = "{$label} · {$size} · {$f} is {$v}{$unit} — outside the normal {$lo}–{$hi}{$unit} range. Check for a missing or extra digit.";
                    $cells[] = "{$type}|{$size}|{$f}";
                }

                // 2. ladder must ascend
                if ($prevVal !== null && $v < $prevVal) {
                    $warnings[] = "{$label} · {$f}: {$size} ({$v}) is SMALLER than {$prevSize} ({$prevVal}). Sizes should get larger going down the chart.";
                    $cells[] = "{$type}|{$size}|{$f}";
                }
                $prevVal  = $v;
                $prevSize = $size;
            }
        }
    }

    return [
        'warnings' => array_values(array_unique($warnings)),
        'cells'    => array_values(array_unique($cells)),
    ];
}
