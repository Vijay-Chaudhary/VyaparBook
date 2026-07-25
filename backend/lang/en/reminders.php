<?php

return [
    // --- The customer-facing message (Phase 4a) ---------------------------
    // Sent to the CUSTOMER in the shop's language. Keep it short, polite and
    // factual: it lands in a personal WhatsApp chat, and a debt reminder that
    // reads as a threat costs the shop the relationship as well as the money.
    // :shop is the business name, :amount an already-formatted ₹ figure.
    'message' => 'Namaste! This is a payment reminder from :shop. Your outstanding balance is :amount. Please pay at your convenience. Thank you!',

    // --- The owner-facing screen ------------------------------------------
    'title' => 'Payment reminders',
    'heading' => 'Payment reminders',
    'back_to_dashboard' => 'Back to dashboard',
    'explainer' => 'Tap Remind to open WhatsApp with the message ready to send. It goes from your own WhatsApp number, so replies come straight to you.',
    'thresholds' => 'Showing customers who owe :amount or more and have not paid in :days days.',
    'customer' => 'Customer',
    'village' => 'Village',
    'phone' => 'Phone',
    'outstanding' => 'Outstanding',
    'days_overdue' => 'Days since payment',
    'last_payment' => 'Last payment',
    'never_paid' => 'Never paid',
    'action' => 'Action',
    'remind' => 'Remind',
    'reminded_today' => 'Reminded today',
    'opt_out' => 'Stop reminders',
    'opt_in' => 'Allow reminders',
    'empty' => 'Nobody is overdue right now. Customers appear here once they owe :amount or more and have not paid in :days days.',

    // Why a row cannot be reminded — shown in place of the button.
    'blocked' => [
        'no_phone' => 'No phone on file',
        'bad_phone' => 'Check this phone number',
        'opted_out' => 'Reminders stopped',
    ],
    'add_phone' => 'Add a number',

    // Flash messages.
    'opted_out' => 'Reminders stopped for :name.',
    'opted_in' => 'Reminders allowed for :name.',
    'cannot_send' => 'That customer cannot be reminded right now.',
    'queued' => 'Reminder queued — it will be sent from the VyaparBook WhatsApp number shortly.',

    // Delivery status (Phase 4b), shown per row once a send has been attempted.
    'status' => [
        'queued' => 'Sending…',
        'sent' => 'Sent',
        'delivered' => 'Delivered',
        'read' => 'Read',
        'failed' => 'Failed',
    ],

    // --- Scheduled reminders (Phase 4c) ------------------------------------
    'scheduled_heading' => 'Scheduled to send',
    'scheduled_none' => 'Nothing is scheduled. Reminders are planned each morning once automation is on.',
    'automation' => 'Automatic reminders',
    'auto_enabled' => 'Send reminders automatically',
    'auto_warning' => 'When this is on, reminders are sent without asking you again. You can cancel any scheduled reminder before it goes out.',
    'send_at' => 'Send at',
    'send_at_hint' => 'Between 9:00 and 20:00 only.',
    'cooldown_days' => 'Wait between reminders (days)',
    'cooldown_hint' => 'The same customer will not be reminded automatically again within this many days.',
    'daily_cap' => 'Most reminders per day',
    'cancel' => 'Cancel',
    'cancelled' => 'Scheduled reminder cancelled.',
    'settings_saved' => 'Reminder settings saved.',
    'save' => 'Save',
];
