<?php

return [
    // Corrections are append-only: on-hand is the sum of every movement and
    // finished goods the sum of every batch output, so nothing may be deleted
    // or edited — a correction is a new row with the amounts negated.
    'correction_note' => 'Correction',

    'cannot_reverse_reversal' => 'That row is already a correction, so it cannot be reversed.',
    'already_reversed' => 'That movement has already been reversed.',
    'batch_already_reversed' => 'That batch has already been reversed.',

    // A movement created by a batch or a purchase is not a free-standing fact.
    // Reversing it alone would leave the cause disagreeing with stock.
    'reverse_the_batch' => 'This movement came from a production batch. Reverse the batch instead.',
    'reverse_the_purchase' => 'This movement came from a purchase. Reverse the purchase instead.',
];
