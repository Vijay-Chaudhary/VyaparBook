<?php

return [
    'title' => 'Pricing',
    'heading' => 'Costs & prices',

    // Says plainly what the sell column is. Every sale in this shop is
    // negotiated per customer, so presenting it as "the price" would
    // misdescribe how the business actually trades.
    'intro' => 'Cost is what a pack costs you. Selling price is only a starting suggestion — every sale is negotiated with the customer. Selling below cost is allowed; it is shown in red so you can see it.',

    'cost_per_kg' => 'Cost per kg',
    'pack' => 'Pack',
    'weight' => 'Weight (kg)',
    'pack_cost' => 'Pack cost',
    'sell' => 'Suggested price',
    'margin' => 'Margin',

    // Precedence, said out loud. Without this an owner edits cost per kg,
    // sees no floor move, and concludes the screen is broken.
    'from_per_kg' => 'from cost per kg: :value',
    'overrides_per_kg' => 'overrides cost per kg',
    'no_cost' => 'no cost set',

    'recost' => 'Set every :product pack cost from :value per kg',
    'recosted' => 'Recosted :count packs from the per-kg cost.',
    'recost_needs_per_kg' => 'Set a cost per kg first, then save, then recost.',

    'save_all' => 'Save',
    'saved' => 'Costs and prices saved.',
    'no_packs' => 'No packs for this product yet.',
    'no_products' => 'No products yet.',
];
