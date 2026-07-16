<?php
// database/catalog_templates/sweets.php

return [
    'label' => 'Sweets / Mithai',

    'products' => [
        'laddu' => ['name_hi' => 'लड्डू', 'name_en' => 'Laddu', 'base_cost_per_kg' => '260.00'],
        'barfi' => ['name_hi' => 'बर्फी', 'name_en' => 'Barfi', 'base_cost_per_kg' => '300.00'],
        'peda' => ['name_hi' => 'पेड़ा', 'name_en' => 'Peda', 'base_cost_per_kg' => '280.00'],
    ],

    'pack_sizes' => [
        '250g' => ['label' => '250g', 'weight_kg' => '0.250', 'in_dropdown' => true],
        '500g' => ['label' => '500g', 'weight_kg' => '0.500', 'in_dropdown' => true],
        '1kg' => ['label' => '1kg', 'weight_kg' => '1.000', 'in_dropdown' => true],
        '2kg' => ['label' => '2kg', 'weight_kg' => '2.000', 'in_dropdown' => false],
    ],

    'product_packs' => [
        ['product' => 'laddu', 'pack' => '250g', 'default_sell_price' => '75.00'],
        ['product' => 'laddu', 'pack' => '500g', 'default_sell_price' => '145.00'],
        ['product' => 'laddu', 'pack' => '1kg', 'default_sell_price' => '280.00'],
        ['product' => 'barfi', 'pack' => '250g', 'default_sell_price' => '85.00'],
        ['product' => 'barfi', 'pack' => '500g', 'default_sell_price' => '165.00'],
        ['product' => 'barfi', 'pack' => '1kg', 'default_sell_price' => '320.00'],
        ['product' => 'peda', 'pack' => '250g', 'default_sell_price' => '80.00'],
        ['product' => 'peda', 'pack' => '500g', 'default_sell_price' => '155.00'],
        ['product' => 'peda', 'pack' => '1kg', 'default_sell_price' => '300.00'],
    ],
];
