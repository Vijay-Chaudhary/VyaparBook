<?php
// database/seed_data/shreerajshyamaji/materials.php
//
// [name, unit, reorder_level]. Opening stock is 0.00 for all, as the owner's
// sheet states; on-hand accrues from the seeded stock movements.
//
// Black Salt: the owner's master lists it in Packet, but the purchase records
// 50.00 Kg at Rs 25 -- the same rate as White Salt. Seeded as kg.
//
// Masur Dal and Spices Mix are in the master with no purchases and no
// consumption. They seed at zero on-hand, below reorder, which is correct: they
// are exactly the materials the low-stock report should be shouting about.

return [
    ['Besan (Gram Flour)', 'kg', '100.000'],
    ['Refined Oil', 'tina', '10.000'],
    ['Masur Dal', 'kg', '50.000'],
    ['Peanuts', 'kg', '30.000'],
    ['Chawal Anta', 'kg', '100.000'],
    ['Spices Mix', 'kg', '2.000'],
    ['White Salt', 'kg', '20.000'],
    ['Black Salt', 'kg', '20.000'],
    ['Panni 10x14', 'kg', '3.000'],
    ['Panni 7x10', 'kg', '3.000'],
    ['Maida', 'kg', '100.000'],
    ['LDO', 'litre', '30.000'],
    ['Achar', 'packet', '100.000'],
    ['Bora 24x36', 'piece', '100.000'],
    ['Bora 24x42', 'piece', '100.000'],
    ['Panni rangin 10x14', 'kg', '5.000'],
];
