<?php
// database/seed_data/shreerajshyamaji/purchases.php
//
// [date, material, qty, unit_cost, supplier]. Rs 3,42,305 across 23 rows.
// qty is in the material's own unit -- Refined Oil in Tina, not litres.
//
// Two 04-Jun rows named suppliers absent from the master ("Spice World Traders"
// for Maida, "PackTech Industries" for Refined Oil). Both are remapped to the
// supplier who supplied that material on every other date.

return [
    ['2026-04-21', 'Besan (Gram Flour)', '400.000', '55.00', 'Kamakhya GKP'],
    ['2026-04-21', 'Chawal Anta', '400.000', '29.00', 'Kamakhya GKP'],
    ['2026-04-21', 'Refined Oil', '20.000', '2450.00', 'Balaji Trader Hata'],
    ['2026-04-21', 'White Salt', '50.000', '25.00', 'Balaji Trader Hata'],
    ['2026-04-21', 'Black Salt', '50.000', '25.00', 'Balaji Trader Hata'],
    ['2026-04-21', 'Maida', '500.000', '29.00', 'Floar Mill Hata'],
    ['2026-04-21', 'Peanuts', '50.000', '123.00', 'Balaji Trader Hata'],
    ['2026-04-21', 'Panni 10x14', '5.000', '220.00', 'PPP (Panni) Shambu GKP'],
    ['2026-04-21', 'Panni 7x10', '5.000', '95.00', 'PPP (Panni) Shambu GKP'],
    ['2026-04-21', 'LDO', '480.000', '73.00', 'LDO Supplier'],
    ['2026-04-28', 'Panni 10x14', '5.000', '220.00', 'PPP (Panni) Shambu GKP'],
    ['2026-04-28', 'Refined Oil', '20.000', '2500.00', 'Balaji Trader Hata'],
    ['2026-05-22', 'Maida', '1000.000', '29.00', 'Floar Mill Hata'],
    ['2026-05-22', 'Refined Oil', '10.000', '2550.00', 'Balaji Trader Hata'],
    ['2026-06-04', 'Maida', '1000.000', '29.00', 'Floar Mill Hata'],
    ['2026-06-04', 'Refined Oil', '20.000', '2550.00', 'Balaji Trader Hata'],
    ['2026-06-04', 'Peanuts', '50.000', '123.00', 'Balaji Trader Hata'],
    ['2026-06-04', 'Achar', '3.000', '60.00', 'Balaji Trader Hata'],
    ['2026-06-04', 'Panni 7x10', '10.000', '220.00', 'PPP (Panni) Shambu GKP'],
    ['2026-06-04', 'Panni 10x14', '10.000', '220.00', 'PPP (Panni) Shambu GKP'],
    ['2026-06-04', 'Bora 24x36', '200.000', '10.00', 'PPP (Bora) Shambu GKP'],
    ['2026-06-04', 'Bora 24x42', '50.000', '13.00', 'PPP (Bora) Shambu GKP'],
    ['2026-06-04', 'Panni rangin 10x14', '3.000', '320.00', 'PPP (Panni) Shambu GKP'],
];
