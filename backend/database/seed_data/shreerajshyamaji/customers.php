<?php
// database/seed_data/shreerajshyamaji/customers.php
//
// [name, village]. Opening balance is 0.00 for everyone: every balance derives
// from the seeded sales and payments, never stored (PRD §9).
//
// Phone is deliberately absent — the owner supplied none. ReminderService
// therefore blocks every customer as `no_phone`, which is the honest state.
//
// Two names repeat across villages (Santosh Singh in Aziz and Harpur; Vikash ji
// in Asna and Lohepar). They are different people; the UI shows village beneath
// the name, so they stay distinguishable.
//
// The last five appear only in transactions, not in the owner's master list.
// Confirmed as genuine customers, not typos of the names above them.

return [
    ['Manish ji', 'Hata'],
    ['Byash ji', 'Bhaisahi'],
    ['Vikash ji', 'Asna'],
    ['Mishra ji', 'Tinahawan'],
    ['Raju', 'Harpur'],
    ['Chotte lal', 'Mathauli'],
    ['Rajan', 'Pattan'],
    ['Rajan Ke Chana', 'Pattan'],
    ['Santosh Singh', 'Aziz'],
    ['Munna', 'Bankatawan'],
    ['Vishnu', 'Ragarganj'],
    ['Amit ji', 'Jhingrahiyan'],
    ['Santosh Singh', 'Harpur'],
    ['Bajarangi', 'Mathauli'],
    ['Guppta ji', 'Nanhu mudera'],
    ['Richa Bakers', 'Aziz'],
    ['Yadav ji', 'Aziz'],
    ['Dilip ji', 'Aziz'],
    ['Ache lal', 'Satbhariyan'],
    ['Krishna ji', 'Sohsa'],
    ['Girja Sankar', 'Sohsa'],
    ['Golu ji', 'Hata'],
    ['Anarudh', 'Bhiswan'],
    ['Sharma ji', 'Gaderi pati'],
    ['Ajay Singh', 'Ahirauli'],
    ['Vikash ji', 'Lohepar'],
    ['Munna Singh', 'Ahirauli thana'],
    ['Bhim ji', 'Mathauli'],
    ['Dharmendra ji', 'Khaurantanwa'],
    ['Ashish', 'Ragar ganj'],
    ['Gurudev ji', 'Jhanga'],
    ['Sahil ji', 'Laxmipur'],
    ['Star ji', 'Mathauli'],
    ['Vinod gupta', 'Lohepar'],
    ['Santosh Jaysawal', 'parsauni'],
    ['Dwivedi ji', 'Aziz'],
    ['Ghore lal', 'Mathauli'],
    ['Madhav', 'Ragarganj'],
    ['Munna Singh', 'Nandu Mundera'],
    ['Parthiv', 'Khairatwa'],
];
