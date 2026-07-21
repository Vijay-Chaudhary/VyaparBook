<?php

/*
 * The platform (Superadmin) console is an internal operator tool. It is kept in
 * English only — operator jargon (suspend, verify payment) does not translate
 * usefully — and fallback_locale='en' resolves these keys whatever the request
 * locale is. Shopkeeper-facing surfaces stay Hindi-default (billing, onboarding).
 */

return [
    'console' => 'Platform console',
    'tenants' => 'Tenants',
    'back_to_console' => 'Back to console',
    'sign_out' => 'Sign out',

    // Directory.
    'search_placeholder' => 'Search by shop name',
    'search' => 'Search',
    'no_tenants' => 'No tenants found.',
    'col_shop' => 'Shop',
    'col_city' => 'City',
    'col_plan' => 'Plan',
    'col_status' => 'Status',
    'col_joined' => 'Joined',
    'no_subscription' => 'No subscription',

    // Drill-down.
    'gstin' => 'GSTIN',
    'language' => 'Language',
    'created' => 'Created',
    'subscription' => 'Subscription',
    'trial_ends' => 'Trial ends',
    'valid_until' => 'Valid until',
    'members' => 'Members',
    'col_name' => 'Name',
    'col_email' => 'Email',
    'col_phone' => 'Phone',
    'col_role' => 'Role',
    'no_members' => 'No members yet.',

    // Subscription levers.
    'suspend' => 'Suspend (dunning)',
    'suspend_confirm' => 'Suspend this tenant? Their entries will be paused until reactivated.',
    'reactivate' => 'Reactivate',
    'reactivate_confirm' => 'Lift the suspension for this tenant?',
    'reason_optional' => 'Reason (optional)',

    // Payments.
    'payments' => 'Payments',
    'no_payments' => 'No payments recorded.',
    'col_date' => 'Date',
    'col_amount' => 'Amount',
    'col_mode' => 'Mode',
    'incl_gst' => 'incl. GST :amount',
    'period_months' => ':count month|:count months',
    'verify' => 'Verify',
    'verify_confirm' => 'Verify this payment and activate the plan?',
    'reject' => 'Reject',
    'reject_confirm' => 'Reject this payment? This cannot be undone.',
    'status_pending' => 'Pending',
    'status_verified' => 'Verified',
    'status_rejected' => 'Rejected',

    // Impersonation.
    'impersonate' => 'View as tenant (support)',
    'impersonate_hint' => 'Open this tenant\'s app exactly as one of its roles sees it, to reproduce a support issue.',
    'role' => 'Role',
    'impersonate_action' => 'View as tenant',
    'impersonate_readonly_note' => 'Read-only: the support view can never make changes, and the session expires on its own after 30 minutes.',

    // Flash messages.
    'flash_suspended' => 'Tenant suspended.',
    'flash_reactivated' => 'Tenant reactivated.',
    'flash_payment_verified' => 'Payment verified and plan activated.',
    'flash_payment_rejected' => 'Payment rejected.',
    'error_not_found' => 'Not found.',
    'error_no_subscription' => 'This tenant has no subscription.',
    'error_verify_rejected' => 'A rejected payment cannot be verified.',
    'error_reject_verified' => 'A verified payment cannot be rejected.',
    'error_role_absent' => 'This tenant has no member with that role.',
];
