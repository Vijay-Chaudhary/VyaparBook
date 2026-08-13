<?php
// lang/en/customers.php

return [
    'title' => 'Customers',
    'heading' => 'Customers',
    'back_to_dashboard' => 'Back to dashboard',
    'back_to_customers' => 'Back to customers',

    'add' => 'Add customer',
    'name' => 'Name',
    'village' => 'Village',
    'phone' => 'Phone',
    'opening_balance' => 'Opening balance',
    'opening_hint' => 'What they already owed before this book started. Sales and payments are recorded in the app.',
    'save' => 'Save',
    'no_customers' => 'No customers yet. Add the first one above.',
    'total_outstanding' => 'Total outstanding',

    'edit' => 'Edit details',
    'update' => 'Update',
    'phone_hint' => 'Reminders need a phone number — without one this customer is skipped.',

    'archive' => 'Archive',
    'archive_confirm' => 'Archive this customer? Their khata is kept and can be restored.',
    'archived_heading' => 'Archived',
    'archived_hint' => 'Archived customers keep their khata and stay out of the lists.',
    'restore' => 'Restore',

    'outstanding' => 'Outstanding',
    'read_only' => 'Sales and payments are recorded in the app. Here you can correct or delete one.',

    // Corrections change the row itself: an edit says what really happened and a
    // delete takes the entry off the khata, instead of the cancelling entry this
    // replaced. Deleting is undoable — see the deleted list below the ledger.
    'edit_sale' => 'Edit sale',
    'edit_payment' => 'Edit payment',
    'save_changes' => 'Save changes',
    'delete' => 'Delete',
    'qty' => 'Qty',
    'rate' => 'Rate',
    'confirm_delete_sale' => 'Delete this sale? It leaves the khata and the balance changes. You can restore it below.',
    'confirm_delete_payment' => 'Delete this payment? It leaves the khata and the balance changes. You can restore it below.',
    'sale_updated' => 'Sale updated.',
    'payment_updated' => 'Payment updated.',
    'sale_deleted' => 'Sale deleted. Restore it from the deleted list if that was a mistake.',
    'payment_deleted' => 'Payment deleted. Restore it from the deleted list if that was a mistake.',
    'sale_restored' => 'Sale restored to the khata.',
    'payment_restored' => 'Payment restored to the khata.',

    'deleted_heading' => 'Deleted entries',
    'deleted_hint' => 'These are off the khata and out of every total. Restore puts one back.',
    'deleted_on' => 'Deleted :date',

    'mode' => 'Mode',
    'modes' => [
        'cash' => 'Cash', 'upi' => 'UPI', 'cheque' => 'Cheque',
        'bank' => 'Bank transfer', 'other' => 'Other',
    ],

    // A khata can still contain a reversal PAIR, written by the REST API or by
    // an order correction, both of which stay append-only. Neither half is
    // editable here, so both are labelled instead of offered an action.
    'is_correction' => 'correction',
    'corrected' => 'corrected',

    // LedgerReverser's own refusals. Still reached through the API and the order
    // workflow, which is why they outlive the console's void/reverse buttons.
    'cannot_void_reversal' => 'That row is already a correction, so it cannot be voided.',
    'already_voided' => 'That sale has already been voided.',
    'cannot_reverse_reversal' => 'That row is already a correction, so it cannot be reversed.',
    'already_reversed' => 'That payment has already been reversed.',

    // Refusals. Each one is a figure somebody outside this screen also holds.
    'cannot_edit_reversal' => 'That row is one half of an older correction, so it has no figures of its own to change.',
    'cannot_edit_reversed' => 'That row was already corrected by a cancelling entry, and the two cancel out as they stand.',
    'cannot_edit_invoiced' => 'A tax invoice was issued for this sale, so it cannot be changed or deleted. Cancel the invoice first.',
    'cannot_edit_order_sale' => 'This sale came from an order. Correct the order instead — that re-issues the sale.',

    'ledger' => 'Khata',
    'date' => 'Date',
    'particulars' => 'Particulars',
    'amount' => 'Amount',
    'balance' => 'Balance',
    'opening' => 'Opening balance',
    'sale' => 'Sale',
    'payment' => 'Payment',
    'sale_reversal' => 'Sale reversed',
    'payment_reversal' => 'Payment reversed',
    'no_entries' => 'No sales or payments yet.',
];
