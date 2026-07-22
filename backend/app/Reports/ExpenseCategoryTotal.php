<?php
// app/Reports/ExpenseCategoryTotal.php
namespace App\Reports;

final readonly class ExpenseCategoryTotal
{
    public function __construct(
        public string $category,      // ExpenseCategory key, e.g. 'rent'
        public string $amountRupees,  // scale-2 decimal string
    ) {}
}
