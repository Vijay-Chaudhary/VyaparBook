<?php
// app/Console/Commands/TenantEraseCommand.php

namespace App\Console\Commands;

use App\Export\TenantEraser;
use App\Models\Business;
use App\Platform\PlatformAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Executes a DPDP erasure request (PRD §13). Irreversible.
 *
 * Deliberately artisan-only, with no console endpoint: this destroys a tenant's
 * entire books, and keeping it off the HTTP surface means it requires shell
 * access to the box rather than a stolen admin token.
 *
 * Run tenant:export first — once this completes the data is gone, and a
 * portability request that arrives afterwards cannot be served.
 */
class TenantEraseCommand extends Command
{
    protected $signature = 'tenant:erase {business_id} {--force : Skip the confirmation prompt, for scripted offboarding}';

    protected $description = 'Permanently erase all of a tenant\'s data (DPDP erasure). Irreversible.';

    public function handle(TenantEraser $eraser): int
    {
        $businessId = (string) $this->argument('business_id');
        $business = Str::isUuid($businessId) ? Business::find($businessId) : null;

        if ($business === null) {
            $this->error('Business not found.');

            return self::FAILURE;
        }

        if ($business->erased_at !== null) {
            $this->error("Tenant was already erased at {$business->erased_at}.");

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirmDestruction($business)) {
            $this->info('Aborted — nothing was erased.');

            return self::FAILURE;
        }

        try {
            $deleted = $eraser->erase($businessId);
        } catch (Throwable $e) {
            // The eraser rolls back on any failure, so the tenant is intact.
            $this->error('Erasure failed (rolled back, tenant intact): '.$e->getMessage());

            return self::FAILURE;
        }

        PlatformAudit::record('erase_tenant', $businessId, [
            'via' => 'cli',
            // The name is snapshotted here because the businesses row no longer
            // holds it — without this the trail cannot say WHICH shop was erased.
            'business_name' => $business->name,
            'rows' => array_sum($deleted),
            'deleted' => $deleted,
        ]);

        $this->info(sprintf('Erased %d rows across %d tables.', array_sum($deleted), count($deleted)));

        foreach ($deleted as $table => $count) {
            $this->line(sprintf('  %-24s %d', $table, $count));
        }

        $this->info('Business row retained as a tombstone (identifying fields cleared, erased_at stamped).');

        return self::SUCCESS;
    }

    /**
     * Require the operator to type the business name. A y/n prompt is too easy
     * to answer reflexively for something with no undo; retyping the name forces
     * them to confirm they are erasing the tenant they think they are.
     */
    private function confirmDestruction(Business $business): bool
    {
        $this->warn(sprintf('About to PERMANENTLY erase tenant: %s (%s)', $business->name, $business->city ?? 'no city'));
        $this->warn('Every customer, sale, payment and stock record for this tenant will be destroyed.');
        $this->warn('This cannot be undone. Run tenant:export first if the data is still needed.');

        $typed = (string) $this->ask('Type the business name to confirm');

        if ($typed !== $business->name) {
            $this->error('Name did not match.');

            return false;
        }

        return true;
    }
}
