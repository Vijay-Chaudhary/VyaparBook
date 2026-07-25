<?php
// app/Console/Commands/RemindersPlanCommand.php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\ReminderPlanner;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Builds today's reminder batch for every tenant with automation on (Phase 4c).
 *
 * Plans only — nothing is sent here. The owner gets the day to cancel anything
 * they disagree with before `reminders:dispatch` releases it.
 *
 * One tenant's failure must not stop the rest: each is planned in its own
 * transaction and its own tenant pin, and an error is reported and stepped over.
 */
class RemindersPlanCommand extends Command
{
    protected $signature = 'reminders:plan {--date= : Plan for a specific Y-m-d instead of today}';

    protected $description = 'Plan automated payment reminders for tenants that have opted in';

    public function handle(ReminderPlanner $planner): int
    {
        $date = $this->option('date') ? Carbon::parse((string) $this->option('date')) : Carbon::today();

        $tenants = Business::query()
            ->where('reminder_auto_enabled', true)
            ->whereNull('erased_at')
            ->pluck('id');

        if ($tenants->isEmpty()) {
            $this->info('No tenants have reminder automation enabled.');

            return self::SUCCESS;
        }

        $planned = 0;

        foreach ($tenants as $businessId) {
            try {
                $batch = DB::transaction(function () use ($businessId, $planner, $date) {
                    TenantContext::switchTo($businessId);
                    app()->bind('tenant.id', fn () => $businessId);

                    return $planner->planFor($businessId, $date);
                });
            } catch (Throwable $e) {
                // Keep going: one tenant's bad data must not deny every other
                // shop their reminders for the day.
                $this->error("{$businessId}: planning failed — {$e->getMessage()}");

                continue;
            }

            if ($batch === null) {
                $this->line("{$businessId}: nothing to plan (already planned today, or automation off)");

                continue;
            }

            $planned++;
            $reason = $batch->stopped_reason ? " (stopped: {$batch->stopped_reason})" : '';
            $this->line("{$businessId}: planned {$batch->planned_count}{$reason}");
        }

        $this->info("Planned batches for {$planned} of {$tenants->count()} tenant(s).");

        return self::SUCCESS;
    }
}
