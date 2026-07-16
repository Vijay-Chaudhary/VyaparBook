<?php
// database/catalog_templates/spices.php

return [
    'label' => 'Spices / Masala',

    'products' => [
        'haldi' => ['name_hi' => 'हल्दी', 'name_en' => 'Haldi', 'base_cost_per_kg' => '180.00'],
        'mirch' => ['name_hi' => 'मिर्च', 'name_en' => 'Mirch', 'base_cost_per_kg' => '240.00'],
        'dhaniya' => ['name_hi' => 'धनिया', 'name_en' => 'Dhaniya', 'base_cost_per_kg' => '160.00'],
    ],

    'pack_sizes' => [
        '50g' => ['label' => '50g', 'weight_kg' => '0.050', 'in_dropdown' => true],
        '100g' => ['label' => '100g', 'weight_kg' => '0.100', 'in_dropdown' => true],
        '200g' => ['label' => '200g', 'weight_kg' => '0.200', 'in_dropdown' => true],
        '500g' => ['label' => '500g', 'weight_kg' => '0.500', 'in_dropdown' => true],
    ],

    'product_packs' => [
        ['product' => 'haldi', 'pack' => '50g', 'default_sell_price' => '12.00'],
        ['product' => 'haldi', 'pack' => '100g', 'default_sell_price' => '22.00'],
        ['product' => 'haldi', 'pack' => '200g', 'default_sell_price' => '42.00'],
        ['product' => 'haldi', 'pack' => '500g', 'default_sell_price' => '100.00'],
        ['product' => 'mirch', 'pack' => '50g', 'default_sell_price' => '15.00'],
        ['product' => 'mirch', 'pack' => '100g', 'default_sell_price' => '28.00'],
        ['product' => 'mirch', 'pack' => '200g', 'default_sell_price' => '54.00'],
        ['product' => 'dhaniya', 'pack' => '100g', 'default_sell_price' => '20.00'],
        ['product' => 'dhaniya', 'pack' => '200g', 'default_sell_price' => '38.00'],
    ],
];
