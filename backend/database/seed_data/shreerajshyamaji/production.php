<?php
// database/seed_data/shreerajshyamaji/production.php
//
// [date, product, output_kg, [[material, qty], ...]].
//
// RECONSTRUCTED, not transcribed -- see the spec's "The production problem".
// The owner's log covers 3 batches / 770 kg against 1,654 kg sold, with no
// Senvda batch at all, and Rs 2.34 lakh of consumption against it. Seeding that
// verbatim gives Rs 304/kg cost against Rs 102/kg revenue.
//
// The owner's three batches (15/16/17-May) are kept. Four are added to cover
// what was actually sold. Consumption follows a per-kilo recipe:
//   0.85 kg flour + 0.20 kg oil + Rs 4.00 of packing and salt.
// Flour blend: Senvda 100% maida; Sev 50/50 besan/chawal anta;
// Mix Sev 60/15/25 besan/peanuts/chawal anta.
//
// Besan is the binding constraint at 400 kg purchased -- it is what sets those
// blends. Refined Oil qty is in TINA, the unit it is stocked in.
//
// Packing and salt are allocated pro-rata by batch output, at 58.2% of each
// material purchased, which is the Rs 4.00/kg allowance.

return [
    ['2026-04-25', 'senvda', '400.000', [
        ['Maida', '340.000'],
        ['Refined Oil', '5.333'],
        ['White Salt', '5.985'],
        ['Black Salt', '5.985'],
        ['Panni 10x14', '2.394'],
        ['Panni 7x10', '1.795'],
        ['Bora 24x36', '23.938'],
        ['Bora 24x42', '5.985'],
        ['Panni rangin 10x14', '0.359'],
        ['Achar', '0.359'],
    ]],
    ['2026-05-15', 'sev', '345.000', [
        ['Besan (Gram Flour)', '146.625'],
        ['Chawal Anta', '146.625'],
        ['Refined Oil', '4.600'],
        ['White Salt', '5.162'],
        ['Black Salt', '5.162'],
        ['Panni 10x14', '2.065'],
        ['Panni 7x10', '1.549'],
        ['Bora 24x36', '20.647'],
        ['Bora 24x42', '5.162'],
        ['Panni rangin 10x14', '0.310'],
        ['Achar', '0.310'],
    ]],
    ['2026-05-16', 'sev', '80.000', [
        ['Besan (Gram Flour)', '34.000'],
        ['Chawal Anta', '34.000'],
        ['Refined Oil', '1.067'],
        ['White Salt', '1.197'],
        ['Black Salt', '1.197'],
        ['Panni 10x14', '0.479'],
        ['Panni 7x10', '0.359'],
        ['Bora 24x36', '4.788'],
        ['Bora 24x42', '1.197'],
        ['Panni rangin 10x14', '0.072'],
        ['Achar', '0.072'],
    ]],
    ['2026-05-17', 'mix_sev', '345.000', [
        ['Besan (Gram Flour)', '175.950'],
        ['Peanuts', '43.987'],
        ['Chawal Anta', '73.312'],
        ['Refined Oil', '4.600'],
        ['White Salt', '5.162'],
        ['Black Salt', '5.162'],
        ['Panni 10x14', '2.065'],
        ['Panni 7x10', '1.549'],
        ['Bora 24x36', '20.647'],
        ['Bora 24x42', '5.162'],
        ['Panni rangin 10x14', '0.310'],
        ['Achar', '0.310'],
    ]],
    ['2026-05-20', 'senvda', '400.000', [
        ['Maida', '340.000'],
        ['Refined Oil', '5.333'],
        ['White Salt', '5.985'],
        ['Black Salt', '5.985'],
        ['Panni 10x14', '2.394'],
        ['Panni 7x10', '1.795'],
        ['Bora 24x36', '23.938'],
        ['Bora 24x42', '5.985'],
        ['Panni rangin 10x14', '0.359'],
        ['Achar', '0.359'],
    ]],
    ['2026-06-05', 'senvda', '355.000', [
        ['Maida', '301.750'],
        ['Refined Oil', '4.733'],
        ['White Salt', '5.311'],
        ['Black Salt', '5.311'],
        ['Panni 10x14', '2.125'],
        ['Panni 7x10', '1.593'],
        ['Bora 24x36', '21.245'],
        ['Bora 24x42', '5.311'],
        ['Panni rangin 10x14', '0.319'],
        ['Achar', '0.319'],
    ]],
    ['2026-06-05', 'sev', '20.000', [
        ['Besan (Gram Flour)', '8.500'],
        ['Chawal Anta', '8.500'],
        ['Refined Oil', '0.267'],
        ['White Salt', '0.299'],
        ['Black Salt', '0.299'],
        ['Panni 10x14', '0.120'],
        ['Panni 7x10', '0.090'],
        ['Bora 24x36', '1.197'],
        ['Bora 24x42', '0.299'],
        ['Panni rangin 10x14', '0.018'],
        ['Achar', '0.018'],
    ]],
];
