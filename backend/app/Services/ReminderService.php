<?php
// app/Services/ReminderService.php

namespace App\Services;

use App\Models\Business;
use App\Models\Customer;
use App\Reminders\OverdueCustomer;
use App\Reminders\ReminderMessage;
use Illuminate\Support\Carbon;

/**
 * Who should the owner chase today? (Phase 4a)
 *
 * Read-only and tenant-pinned, like DashboardReportService: every method
 * assumes an already-pinned transaction (RLS FORCE'd), and the explicit
 * ->where('business_id', ...) is the app-level layer on top — never one alone.
 *
 * Outstanding is NOT recomputed here: it comes from KhataService, the one
 * definition of what a customer owes (PRD §9, always recomputable). This
 * service only decides who crosses the shop's thresholds, and why someone
 * cannot be messaged.
 */
class ReminderService
{
    public function __construct(private readonly KhataService $khata) {}

    /**
     * The overdue list, biggest debt first.
     *
     * Customers who owe enough but cannot be reached (no phone, unusable phone,
     * opted out) are INCLUDED with sendable=false — the owner still needs to
     * see the money, and a silently shortened list would misstate it.
     *
     * @return list<OverdueCustomer>
     */
    public function overdue(string $businessId): array
    {
        $business = Business::query()->findOrFail($businessId);
        $minOutstanding = (string) $business->reminder_min_outstanding;
        $minDays = (int) $business->reminder_min_days;
        $today = Carbon::today();

        // One query for the dates; outstanding then comes from KhataService per
        // customer, matching how the dashboard already totals it. Sub-selects
        // rather than a per-customer query for last-payment/first-sale, so this
        // stays 1 + N rather than 3N.
        $customers = Customer::query()
            ->where('business_id', $businessId)
            ->whereNull('archived_at')
            ->selectRaw('customers.*')
            ->selectRaw('(select max(payment_date) from payments p where p.customer_id = customers.id)::text as last_payment_on')
            ->selectRaw('(select min(sale_date) from sales s where s.customer_id = customers.id)::text as first_sale_on')
            ->get();

        $rows = [];

        foreach ($customers as $customer) {
            $outstanding = $this->khata->outstandingFor($customer);

            // bccomp, never <: these are decimal strings, not numbers.
            if (bccomp($outstanding, $minOutstanding, 2) < 0) {
                continue;
            }

            // Never paid → count from their first sale. A customer with neither
            // a payment nor a sale cannot owe anything and is already excluded
            // above, but guard rather than assume.
            $since = $customer->last_payment_on ?? $customer->first_sale_on;

            if ($since === null) {
                continue;
            }

            $daysOverdue = Carbon::parse($since)->diffInDays($today);

            if ($daysOverdue < $minDays) {
                continue;
            }

            $rows[] = $this->row($customer, $outstanding, (int) $daysOverdue);
        }

        // Biggest debt first — that is the order an owner works the list in.
        usort($rows, fn (OverdueCustomer $a, OverdueCustomer $b) => bccomp($b->outstandingRupees, $a->outstandingRupees, 2));

        return $rows;
    }

    /**
     * Build the row, resolving why (if at all) it cannot be messaged. Opt-out
     * outranks a bad phone: a customer who asked not to be contacted must not
     * be presented as "fix the number and try again".
     */
    private function row(Customer $customer, string $outstanding, int $daysOverdue): OverdueCustomer
    {
        $e164 = ReminderMessage::normalisePhone($customer->phone);

        $blockedReason = match (true) {
            $customer->reminder_opt_out_at !== null => 'opted_out',
            $customer->phone === null || trim($customer->phone) === '' => 'no_phone',
            $e164 === null => 'bad_phone',
            default => null,
        };

        return new OverdueCustomer(
            customerId: $customer->id,
            name: $customer->name,
            village: $customer->village,
            phone: $customer->phone,
            phoneE164: $e164,
            outstandingRupees: $outstanding,
            daysOverdue: $daysOverdue,
            lastPaymentOn: $customer->last_payment_on,
            sendable: $blockedReason === null,
            blockedReason: $blockedReason,
        );
    }
}
