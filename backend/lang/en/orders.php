<?php

return [
    'illegal_transition' => 'This order cannot go from :from to :to.',

    'title' => 'Approvals',
    'heading' => 'Order approvals',
    'pending_none' => 'Nothing waiting for approval.',
    'customer' => 'Customer',
    'order_date' => 'Ordered',
    'product' => 'Product',
    'qty' => 'Qty',
    'rate' => 'Rate',
    // Cost is advice, not a limit — the shop sells some packs under cost on
    // purpose. Shown only when a rate is actually below it, so the choice is
    // informed, never refused.
    'under_cost' => 'Under cost — :cost',
    'total' => 'Total',
    'accept' => 'Accept',
    'reject' => 'Reject',
    'reason' => 'Reason',
    'accepted' => 'Order accepted.',
    'rejected' => 'Order rejected.',
    'not_pending' => 'That order is no longer waiting to be accepted.',
    'cancel' => 'Cancel order',
    'cancelled' => 'Order cancelled.',
    'cannot_cancel' => 'That order can no longer be cancelled.',

    // Owner corrections, which may act on an order that is already delivered.
    'revised' => 'Order corrected.',
    'voided' => 'Order voided. Any sale it created has been reversed.',
    'cannot_revise' => 'A :status order has no figures left to correct.',
    'revise' => 'Correct figures',
    'revise_heading' => 'Correct this order',
    'revise_hint' => 'Delivered orders can be corrected. The sale already in the khata is reversed and a corrected one issued, so nothing is deleted.',
    'void' => 'Void order',
    'void_confirm' => 'Void this order? Any sale it created will be reversed in the khata. This cannot be undone from here.',
    'void_note' => 'Reason (optional)',
    'confirm_cancel' => 'Cancel this order? The salesman will see it on their next sync.',
    'recent' => 'Recently decided',
    // Shown only where acceptance actually changed something. Silence means
    // "nothing to show" — which also covers orders taken before this was
    // recorded — never "we checked and it was identical".
    'adjusted' => 'Changed at approval',
    'was' => 'was :value',
    'status' => 'Status',
    'statuses' => [
        'pending' => 'Waiting', 'accepted' => 'Accepted', 'packed' => 'Packed',
        'delivered' => 'Delivered', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled',
    ],
];
