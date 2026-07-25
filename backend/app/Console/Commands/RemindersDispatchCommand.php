<?php
// app/Console/Commands/RemindersDispatchCommand.php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\ReminderDispatcher;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Releases today's planned batches to the queue (Phase 4c).
 *
 * Runs frequently rather than once: a batch held back by quiet hours, or by a
 * send time that has not arrived, simply waits for the next tick. The
 * dispatcher itself re-checks every safety condition, so running this often is
 * cheap and safe.
 */
class RemindersDispatchCommand extends Command
{
    protected $signature = 'reminders:dispatch';

    protected $description = 'Send today\'s planned payment reminders that are due and still eligible';

    public function handle(ReminderDispatcher $dispatcher): int
    {
        $now = Carbon::now();

        $tenants = Business::query()
            ->where('reminder_auto_enabled', true)
            ->whereNull('erased_at')
            ->pluck('id');

        foreach ($tenants as $businessId) {
            try {
                DB::transaction(function () use ($businessId, $dispatcher, $now) {
                    TenantContext::switchTo($businessId);
                    app()->bind('tenant.id', fn () => $businessId);

                    $dispatcher->dispatchFor($businessId, $now);
                });
            } catch (Throwable $e) {
                // As with planning: one tenant must not block the others.
                $this->error("{$businessId}: dispatch failed — {$e->getMessage()}");
            }
        }

        $this->info("Dispatch pass complete for {$tenants->count()} tenant(s).");

        return self::SUCCESS;
    }
}
