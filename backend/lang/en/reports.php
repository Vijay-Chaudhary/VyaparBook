<?php
// lang/en/reports.php

return [
    'title' => 'Management dashboard',
    'heading' => 'Management dashboard',
    'back_to_app' => 'Back to app',
    'report_date' => 'Report date',
    'period' => 'Period',
    'view' => 'View',

    // Shown when the business has recorded nothing yet, so a screen full of
    // zeros reads as "new" rather than "broken".
    'empty_title' => 'No entries yet',
    'empty_body' => 'Record a sale, a purchase or a payment and this dashboard fills in. The figures below stay at zero until then.',
    'empty_cta' => 'Record the first sale',

    // Tiles
    'sales_today' => 'Total sales today',
    'sales_month' => 'Total sales (this month)',
    'customer_outstanding' => 'Customer outstanding',
    'production_month' => 'Production (this month)',
    'stock_low_alerts' => 'Low stock alerts (items)',

    // Customer outstanding list
    'customer_outstanding_list' => 'Customer outstanding (highest first)',
    'supplier_outstanding_list' => 'Supplier payable (highest first)',
    'supplier' => 'Supplier',
    'amount_payable' => 'Amount payable',
    'stock_value' => 'Stock value',
    'stock_value_hint' => 'On hand now',
    'stock_valuation' => 'Stock valuation',
    'cost_per_kg' => 'Cost per kg',
    'customer' => 'Customer',
    'village' => 'Village',
    'amount_due' => 'Amount due',

    // Key insights
    'key_insights' => 'Key insights',
    'highest_selling_product' => 'Highest selling product',
    'highest_profit_product' => 'Highest profit product',

    // Low stock
    'low_stock' => 'Low stock alert',
    'material' => 'Material',
    'on_hand' => 'On hand',
    'reorder' => 'Reorder',

    // Product performance
    'product_performance' => 'Product-wise performance (this year)',
    'product' => 'Product',
    'qty_sold' => 'Qty sold',
    'sales' => 'Sales',
    'cogs' => 'Cost of goods sold',
    'est_profit' => 'Est. profit',
    'margin' => 'Margin %',

    // Estimated gross profit (before operating expenses)
    'est_gross_profit_month' => 'Est. gross profit (this month)',
    'gross_profit' => 'Gross profit',
    'gross_profit_caveat' => '= Sales − what the goods cost to make; before operating expenses. Labour and electricity are counted below, as expenses.',
    'gross_profit_estimated' => ':amount of this month’s sales is still costed from your typed-in estimate, because those products have no production batches yet.',
    'monthly_gross_profit_chart' => 'Monthly est. gross profit',
    'monthly_money_chart' => 'Monthly performance (₹)',

    // P&L (Phase 1)
    'pnl' => 'Profit & loss (this month)',
    'expenses' => 'Operating expenses',
    'net_profit' => 'Net profit',
    'net_margin' => 'Net margin',
    'expenses_by_category' => 'Expenses by category',
    'manage_expenses' => 'Manage expenses',
    'manage_purchases' => 'Purchases',
    'manage_customers' => 'Customers',
    'manage_suppliers' => 'Suppliers',
    'monthly_net_profit_chart' => 'Monthly net profit',

    // Cash flow (Phase 3)
    'cash_flow' => 'Cash flow (this year)',
    'cash_position' => 'Cash position',
    'cash_position_hint' => 'Recorded in VyaparBook — not a bank balance',
    'cash_in' => 'Cash in',
    'cash_out' => 'Cash out',
    'net_cash' => 'Net cash',
    'net_cash_month' => 'Net cash (this month)',
    'monthly_net_cash_chart' => 'Monthly net cash',
    'cash_flow_caption' => 'Cash actually collected (customer payments) minus cash actually paid out (suppliers + expenses). Credit sales and unpaid purchases are not counted until money changes hands.',

    // Trend
    'monthly_trend' => 'Monthly trend (this year)',
    'month' => 'Month',
    'production' => 'Production (kg)',
    'monthly_sales_chart' => 'Monthly sales',
    'monthly_production_chart' => 'Monthly production',

    // Empty states
    'no_customers' => 'No customers yet.',
    'no_suppliers' => 'No suppliers yet.',
    'no_materials' => 'No raw materials yet.',
    'no_products' => 'No sales recorded this year yet.',
    'no_low_stock' => 'All materials are above their reorder level.',

    // Finished goods (PRD Phase 3)
    'finished_goods' => 'Finished goods in stock',
    'finished_goods_caption' => 'Produced minus sold, in kilograms, since you started. Sales are converted using each pack size. A negative figure means more was sold than was recorded as produced — check for missing production entries.',
    'finished_goods_empty' => 'Nothing produced or sold yet.',
    'produced_kg' => 'Produced (Kg)',
    'sold_kg' => 'Sold (Kg)',
    'on_hand_kg' => 'In stock (Kg)',

    // Owner-tool links in the dashboard header.
    'manage_orders' => 'Approvals',
    'manage_beats' => 'Beats',
    'manage_pricing' => 'Costs & prices',
    'manage_gst' => 'GST',
];
