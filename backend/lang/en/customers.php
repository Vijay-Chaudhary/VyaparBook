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
    'read_only' => 'Sales and payments are recorded in the app. Here you can correct one.',

    // Corrections are append-only: voiding writes a mirror-image row rather
    // than deleting, so outstanding, cash flow, COGS and any issued invoice
    // stay consistent with what is on the books.
    'void' => 'Void',
    'reverse' => 'Reverse',
    'confirm_void' => 'Void this sale? A cancelling entry is added — nothing is deleted.',
    'confirm_reverse' => 'Reverse this payment? A cancelling entry is added — nothing is deleted.',
    'is_correction' => 'correction',
    'corrected' => 'corrected',
    'voided' => 'Sale voided. A cancelling entry was added.',
    'reversed' => 'Payment reversed. A cancelling entry was added.',

    // Correcting a payment: reverse the original and record what was meant, so
    // the statement explains the change instead of a balance quietly differing.
    'correct_payment' => 'Correct payment',
    'payment_corrected' => 'Payment corrected. The original was reversed and the corrected amount recorded.',
    'mode' => 'Mode',
    'modes' => [
        'cash' => 'Cash', 'upi' => 'UPI', 'cheque' => 'Cheque',
        'bank' => 'Bank transfer', 'other' => 'Other',
    ],
    'cannot_void_reversal' => 'That row is already a correction, so it cannot be voided.',
    'already_voided' => 'That sale has already been voided.',
    'cannot_reverse_reversal' => 'That row is already a correction, so it cannot be reversed.',
    'already_reversed' => 'That payment has already been reversed.',

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
