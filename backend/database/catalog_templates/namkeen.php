<?php
// database/catalog_templates/namkeen.php
//
// Keys ('sev', '500g') are template-local identifiers resolved to UUIDs at
// insert time. Nothing depends on display text, so renaming a product here
// never breaks a product_packs reference below.

return [
    'label' => 'Namkeen / Snacks',

    'products' => [
        'senvda' => ['name_hi' => 'सेंवड़ा', 'name_en' => 'Senvda', 'base_cost_per_kg' => '120.00'],
        'sev' => ['name_hi' => 'सेव', 'name_en' => 'Sev', 'base_cost_per_kg' => '130.00'],
        'mix' => ['name_hi' => 'मिक्स', 'name_en' => 'Mix', 'base_cost_per_kg' => '140.00'],
    ],

    'pack_sizes' => [
        '50g' => ['label' => '50g', 'weight_kg' => '0.050', 'in_dropdown' => false],
        '100g' => ['label' => '100g', 'weight_kg' => '0.100', 'in_dropdown' => true],
        '200g' => ['label' => '200g', 'weight_kg' => '0.200', 'in_dropdown' => true],
        '250g' => ['label' => '250g', 'weight_kg' => '0.250', 'in_dropdown' => true],
        '500g' => ['label' => '500g', 'weight_kg' => '0.500', 'in_dropdown' => true],
        '1kg' => ['label' => '1kg', 'weight_kg' => '1.000', 'in_dropdown' => true],
        '5kg' => ['label' => '5kg', 'weight_kg' => '5.000', 'in_dropdown' => true],
        '10kg' => ['label' => '10kg', 'weight_kg' => '10.000', 'in_dropdown' => false],
    ],

    // default_cost_price is omitted throughout: CatalogTemplateService fills it
    // from base_cost_per_kg × weight_kg via CatalogService::suggestedCostPrice.
    'product_packs' => [
        ['product' => 'senvda', 'pack' => '100g', 'default_sell_price' => '20.00'],
        ['product' => 'senvda', 'pack' => '200g', 'default_sell_price' => '38.00'],
        ['product' => 'senvda', 'pack' => '500g', 'default_sell_price' => '90.00'],
        ['product' => 'senvda', 'pack' => '1kg', 'default_sell_price' => '175.00'],
        ['product' => 'sev', 'pack' => '100g', 'default_sell_price' => '22.00'],
        ['product' => 'sev', 'pack' => '250g', 'default_sell_price' => '52.00'],
        ['product' => 'sev', 'pack' => '500g', 'default_sell_price' => '98.00'],
        ['product' => 'sev', 'pack' => '1kg', 'default_sell_price' => '190.00'],
        ['product' => 'mix', 'pack' => '100g', 'default_sell_price' => '24.00'],
        ['product' => 'mix', 'pack' => '250g', 'default_sell_price' => '58.00'],
        ['product' => 'mix', 'pack' => '500g', 'default_sell_price' => '110.00'],
        ['product' => 'mix', 'pack' => '1kg', 'default_sell_price' => '210.00'],
    ],
];
