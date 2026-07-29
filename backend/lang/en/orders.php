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
    'total' => 'Total',
    'accept' => 'Accept',
    'reject' => 'Reject',
    'reason' => 'Reason',
    'accepted' => 'Order accepted.',
    'rejected' => 'Order rejected.',
    'not_pending' => 'That order is no longer waiting to be accepted.',
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
