<?php

return [
    'title' => 'Plan & billing',
    'heading' => 'Plan & billing',
    'back_to_app' => 'Back to app',

    // Plan names.
    'plan_free' => 'Free',
    'plan_pro' => 'Pro',
    'current_plan' => 'Current plan',

    // Status banners — one is shown at the top depending on subscription state.
    'trial_banner' => '{1} Your free trial ends tomorrow.|[2,*] Your free trial ends in :days days.',
    'trial_ends_on' => 'Trial ends :date',
    'active_banner' => 'Your Pro plan is active.',
    'renews_on' => 'Valid until :date',
    'past_due_banner' => 'Your Pro period has ended — you are on the Free plan. Upgrade to restore Pro features.',
    'read_only_banner' => 'Your account is past due, so new entries are paused. Record a payment to restore full access — your data is safe.',

    // Usage against plan limits.
    'usage' => 'Usage',
    'customers' => 'Customers',
    'staff' => 'Staff',
    'unlimited' => 'Unlimited',
    'over_limit' => 'Over limit',
    'of' => 'of',

    // Record-payment form.
    'upgrade_heading' => 'Upgrade to Pro',
    'upgrade_hint' => 'Pay by UPI or bank transfer, then record it here. We verify it and activate Pro — usually within a day.',
    'amount' => 'Amount (₹)',
    'gst_note' => '18% GST will be added.',
    'mode' => 'Payment mode',
    'mode_upi' => 'UPI',
    'mode_bank' => 'Bank transfer',
    'mode_manual' => 'Cash / other',
    'reference' => 'Reference / UPI ref (optional)',
    'period_months' => 'Months',
    'note' => 'Note (optional)',
    'record_payment' => 'Record payment',
    'payment_recorded' => 'Payment recorded — pending verification. Pro activates once we confirm it.',

    // Payment history.
    'history' => 'Payment history',
    'no_payments' => 'No payments yet.',
    'col_date' => 'Date',
    'col_amount' => 'Amount',
    'col_mode' => 'Mode',
    'col_status' => 'Status',
    'status_pending' => 'Pending',
    'status_verified' => 'Verified',
    'status_rejected' => 'Rejected',
    'incl_gst' => 'incl. GST :amount',
];
