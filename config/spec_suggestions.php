<?php
// ============================================================
//  Dievon – Specification Suggestions (UI hints only)
//
//  These are pure convenience suggestions surfaced in the admin
//  Specifications editor via <datalist> autocomplete. Nothing here
//  is enforced or validated against — the admin can always type any
//  custom label, component name, or unit instead. Safe to edit this
//  file at any time; it does not affect already-saved specifications.
// ============================================================

return [
    // Suggested labels for general (product-level) specifications
    'general_labels' => [
        'Fabric', 'Fit', 'Pattern', 'Occasion', 'Wash Care', 'Country of Origin',
        'Style Code', 'Season', 'Closure Type', 'Transparency', 'Lining', 'Stretchable',
    ],

    // Suggested component names (Kurti, Palazzo, Dupatta, etc.)
    'component_names' => [
        'Kurti', 'Top', 'Bottom', 'Palazzo', 'Pants', 'Skirt', 'Dupatta',
        'Saree', 'Blouse', 'Jacket', 'Shrug', 'Petticoat',
    ],

    // Suggested labels for specifications inside a component
    'component_labels' => [
        'Fabric', 'Length', 'Sleeve', 'Neck', 'Closure', 'Fit', 'Pattern', 'Work', 'Border',
    ],

    // Suggested units
    'units' => ['cm', 'inch', 'm', 'kg', 'g', 'ml', 'L', 'pieces'],
];
