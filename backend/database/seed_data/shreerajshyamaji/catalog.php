<?php
// database/seed_data/shreerajshyamaji/catalog.php
//
// Keys ('senvda', '800g') are file-local identifiers resolved to UUIDs at
// insert time, exactly as database/catalog_templates/*.php does.
//
// default_sell_price is the MODAL rate across the owner's real sale lines, not
// an average: it is the price most customers actually pay, and it is only a
// default — every seeded line carries the rate that customer was charged.

return [
    'products' => [
        'senvda'  => ['name_hi' => 'सेंवड़ा',   'name_en' => 'Senvda',  'base_cost_per_kg' => '62.08'],
        'sev'     => ['name_hi' => 'सेव',      'name_en' => 'Sev',     'base_cost_per_kg' => '73.13'],
        'mix_sev' => ['name_hi' => 'मिक्स सेव', 'name_en' => 'Mix Sev', 'base_cost_per_kg' => '87.32'],
    ],

    // The owner's 15-size master, plus 250g and 375g which appear in sales but
    // were missing from it. in_dropdown false = a size they do not currently
    // sell, kept so the list matches their master without cluttering the order
    // screen.
    'pack_sizes' => [
        '250g'  => ['label' => '250g',  'weight_kg' => '0.250', 'in_dropdown' => true],
        '300g'  => ['label' => '300g',  'weight_kg' => '0.300', 'in_dropdown' => true],
        '350g'  => ['label' => '350g',  'weight_kg' => '0.350', 'in_dropdown' => true],
        '375g'  => ['label' => '375g',  'weight_kg' => '0.375', 'in_dropdown' => true],
        '400g'  => ['label' => '400g',  'weight_kg' => '0.400', 'in_dropdown' => true],
        '450g'  => ['label' => '450g',  'weight_kg' => '0.450', 'in_dropdown' => false],
        '500g'  => ['label' => '500g',  'weight_kg' => '0.500', 'in_dropdown' => false],
        '550g'  => ['label' => '550g',  'weight_kg' => '0.550', 'in_dropdown' => false],
        '600g'  => ['label' => '600g',  'weight_kg' => '0.600', 'in_dropdown' => false],
        '650g'  => ['label' => '650g',  'weight_kg' => '0.650', 'in_dropdown' => false],
        '700g'  => ['label' => '700g',  'weight_kg' => '0.700', 'in_dropdown' => true],
        '750g'  => ['label' => '750g',  'weight_kg' => '0.750', 'in_dropdown' => false],
        '800g'  => ['label' => '800g',  'weight_kg' => '0.800', 'in_dropdown' => true],
        '850g'  => ['label' => '850g',  'weight_kg' => '0.850', 'in_dropdown' => false],
        '900g'  => ['label' => '900g',  'weight_kg' => '0.900', 'in_dropdown' => true],
        '950g'  => ['label' => '950g',  'weight_kg' => '0.950', 'in_dropdown' => true],
        '1kg'   => ['label' => '1kg',   'weight_kg' => '1.000', 'in_dropdown' => true],
    ],

    // 21 packs — one per product/size combination the owner has actually sold.
    'product_packs' => [
        ['product' => 'senvda',  'pack' => '300g', 'default_sell_price' => '30.00'],
        ['product' => 'senvda',  'pack' => '350g', 'default_sell_price' => '35.00'],
        ['product' => 'senvda',  'pack' => '375g', 'default_sell_price' => '35.00'],
        ['product' => 'senvda',  'pack' => '400g', 'default_sell_price' => '36.00'],
        ['product' => 'senvda',  'pack' => '700g', 'default_sell_price' => '70.00'],
        ['product' => 'senvda',  'pack' => '800g', 'default_sell_price' => '74.00'],
        ['product' => 'senvda',  'pack' => '900g', 'default_sell_price' => '85.00'],
        ['product' => 'senvda',  'pack' => '1kg',  'default_sell_price' => '100.00'],
        ['product' => 'sev',     'pack' => '350g', 'default_sell_price' => '38.00'],
        ['product' => 'sev',     'pack' => '400g', 'default_sell_price' => '44.00'],
        ['product' => 'sev',     'pack' => '800g', 'default_sell_price' => '88.00'],
        ['product' => 'sev',     'pack' => '900g', 'default_sell_price' => '106.00'],
        ['product' => 'sev',     'pack' => '950g', 'default_sell_price' => '105.00'],
        ['product' => 'sev',     'pack' => '1kg',  'default_sell_price' => '110.00'],
        ['product' => 'mix_sev', 'pack' => '250g', 'default_sell_price' => '32.00'],
        ['product' => 'mix_sev', 'pack' => '300g', 'default_sell_price' => '39.00'],
        ['product' => 'mix_sev', 'pack' => '350g', 'default_sell_price' => '43.00'],
        ['product' => 'mix_sev', 'pack' => '400g', 'default_sell_price' => '48.00'],
        ['product' => 'mix_sev', 'pack' => '800g', 'default_sell_price' => '105.00'],
        ['product' => 'mix_sev', 'pack' => '900g', 'default_sell_price' => '120.00'],
        ['product' => 'mix_sev', 'pack' => '1kg',  'default_sell_price' => '120.00'],
    ],
];
