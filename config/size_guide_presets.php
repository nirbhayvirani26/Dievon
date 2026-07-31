<?php
// ============================================================
//  Dievon – Size Guide Starter Templates
//
//  Filling a new category's size chart by hand means 9 sizes x 5 measurements
//  x 2 tabs (90 inputs) plus writing five "how to measure" explanations from
//  scratch. These presets give a correct, industry-standard starting point per
//  garment type so a new category can be set up in one click and then adjusted.
//
//  Notes for whoever edits this next:
//   - Body measurements are the WEARER's body; garment measurements are the
//     finished garment (body + ease). Ease below is deliberately conservative.
//   - Leaving `shoulder` and `bust` instructions EMPTY is meaningful: the
//     storefront treats a chart with no shoulder/bust guidance as a lower-body
//     garment and swaps in the legs/waist/hip diagram instead of the full
//     figure. The Bottoms preset relies on that.
//   - Numbers are in INCHES and follow the ladder in config/size_ladder.php,
//     where the numeric size is the bust measurement (30/XS ... 46/5XL).
// ============================================================

// Standard how-to-measure copy, written once and shared by the presets.
$SG_INSTRUCTIONS = [
    'shoulder' => 'Stand relaxed with arms down. Measure across the back from the bony tip of one shoulder to the bony tip of the other, keeping the tape flat along the shoulder line.',
    'bust'     => 'Wear a non-padded bra. Measure around the fullest part of the bust, keeping the tape level and parallel to the floor. Keep it snug but not tight — you should be able to slide a finger underneath.',
    'waist'    => 'Measure around the narrowest part of your natural waistline, usually just above the navel. Do not hold your breath in; keep the tape comfortably loose.',
    'hips'     => 'Stand with your feet together. Measure around the fullest part of your hips and seat, roughly 8 inches below the natural waist, keeping the tape parallel to the floor.',
    'length'   => 'Measure from the highest point of the shoulder, next to the neck, straight down the front to the hem.',
];
$SG_LENGTH_BOTTOMS = 'Measure from the top of the waistband straight down the outside of the leg to the hem.';
$SG_LENGTH_TOP     = 'Measure from the highest point of the shoulder straight down to the hem of the top.';

/**
 * Build the 9-row size ladder for a preset.
 * Bust follows the numeric size; waist sits 4" under it and hips 4" over it,
 * which is the proportion most Indian ready-to-wear ladders are cut to.
 * `$ease` is added to every garment-tab measurement.
 */
$sgRows = function (array $fields, float $ease, $lengthStart, $lengthStep) {
    $ladder = require __DIR__ . '/size_ladder.php';
    $body = [];
    $garment = [];
    foreach ($ladder as $i => $sz) {
        $bust = (float)$sz['numeric'];
        $base = [
            'bust'     => $bust,
            'waist'    => $bust - 4,
            'hips'     => $bust + 4,
            'shoulder' => 13.5 + ($i * 0.5),
        ];
        $bodyRow = ['size_label' => $sz['code'], 'numeric_size' => $sz['numeric']];
        $garRow  = ['size_label' => $sz['code'], 'numeric_size' => $sz['numeric']];
        foreach (['bust', 'waist', 'hips', 'shoulder'] as $f) {
            if (in_array($f, $fields, true)) {
                $bodyRow[$f] = round($base[$f], 1);
                // Shoulder is a fixed construction point, so it takes no ease.
                $garRow[$f]  = round($base[$f] + ($f === 'shoulder' ? 0 : $ease), 1);
            }
        }
        if ($lengthStart !== null) {
            $garRow['length'] = round($lengthStart + ($i * $lengthStep), 1);
        }
        $body[] = $bodyRow;
        $garment[] = $garRow;
    }
    return ['body' => $body, 'garment' => $garment];
};

return [
    'long_kurti' => [
        'label'       => 'Long Kurti / Kurta',
        'description' => 'Full-length kurti — shoulder, bust, waist, hips and length.',
        'instructions' => [
            'shoulder' => $SG_INSTRUCTIONS['shoulder'],
            'bust'     => $SG_INSTRUCTIONS['bust'],
            'waist'    => $SG_INSTRUCTIONS['waist'],
            'hips'     => $SG_INSTRUCTIONS['hips'],
            'length'   => $SG_INSTRUCTIONS['length'],
        ],
        'rows' => $sgRows(['bust', 'waist', 'hips', 'shoulder'], 2, 44, 0.5),
    ],

    'short_kurti' => [
        'label'       => 'Short Kurti / Tunic',
        'description' => 'Hip-length kurti — same as a long kurti but a shorter hem.',
        'instructions' => [
            'shoulder' => $SG_INSTRUCTIONS['shoulder'],
            'bust'     => $SG_INSTRUCTIONS['bust'],
            'waist'    => $SG_INSTRUCTIONS['waist'],
            'hips'     => $SG_INSTRUCTIONS['hips'],
            'length'   => $SG_INSTRUCTIONS['length'],
        ],
        'rows' => $sgRows(['bust', 'waist', 'hips', 'shoulder'], 2, 34, 0.4),
    ],

    'top' => [
        'label'       => "Top / Blouse",
        'description' => 'Upper body only — no hip measurement.',
        'instructions' => [
            'shoulder' => $SG_INSTRUCTIONS['shoulder'],
            'bust'     => $SG_INSTRUCTIONS['bust'],
            'waist'    => $SG_INSTRUCTIONS['waist'],
            'hips'     => '',
            'length'   => $SG_LENGTH_TOP,
        ],
        'rows' => $sgRows(['bust', 'waist', 'shoulder'], 2, 24, 0.3),
    ],

    'dress' => [
        'label'       => 'Dress / Gown / Anarkali',
        'description' => 'Full-length one-piece — every measurement, longest hem.',
        'instructions' => [
            'shoulder' => $SG_INSTRUCTIONS['shoulder'],
            'bust'     => $SG_INSTRUCTIONS['bust'],
            'waist'    => $SG_INSTRUCTIONS['waist'],
            'hips'     => $SG_INSTRUCTIONS['hips'],
            'length'   => $SG_INSTRUCTIONS['length'],
        ],
        'rows' => $sgRows(['bust', 'waist', 'hips', 'shoulder'], 2.5, 50, 0.5),
    ],

    'bottoms' => [
        'label'       => 'Bottoms (Trousers / Palazzo / Skirt)',
        'description' => 'Lower body only. Leaves shoulder and bust blank, which switches the storefront diagram to the legs/waist/hip illustration.',
        'instructions' => [
            // Intentionally blank — this is what triggers the lower-body diagram.
            'shoulder' => '',
            'bust'     => '',
            'waist'    => 'Measure around the natural waistline where the waistband will sit, keeping the tape comfortably loose.',
            'hips'     => $SG_INSTRUCTIONS['hips'],
            'length'   => $SG_LENGTH_BOTTOMS,
        ],
        'rows' => $sgRows(['waist', 'hips'], 1.5, 38, 0.4),
    ],

    'suit_set' => [
        'label'       => '3-Piece Suit / Coord Set',
        'description' => 'Multi-piece set — all measurements, generous ease.',
        'instructions' => [
            'shoulder' => $SG_INSTRUCTIONS['shoulder'],
            'bust'     => $SG_INSTRUCTIONS['bust'],
            'waist'    => $SG_INSTRUCTIONS['waist'],
            'hips'     => $SG_INSTRUCTIONS['hips'],
            'length'   => 'Measure the kurta from the highest point of the shoulder straight down to the hem. Trouser length is measured separately from waistband to hem.',
        ],
        'rows' => $sgRows(['bust', 'waist', 'hips', 'shoulder'], 2.5, 46, 0.5),
    ],
];
