<?php
// app/Console/Commands/TenantImportCommand.php

namespace App\Console\Commands;

use App\Import\CsvReader;
use App\Import\ImportReport;
use App\Import\TenantImporter;
use App\Models\Business;
use App\Models\Membership;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Operator-run onboarding: ingest a shop's customers or raw materials from a
 * CSV into a tenant. Valid rows are applied; invalid rows are reported and
 * skipped; a non-zero exit flags that some rows failed so a wrapping script
 * notices. --dry-run validates and tallies without persisting anything.
 */
class TenantImportCommand extends Command
{
    protected $signature = 'tenant:import {business_id} {type : customers|raw-materials} {path} {--dry-run}';

    protected $description = 'Import a tenant\'s customers or raw materials from a CSV file.';

    public function handle(TenantImporter $importer): int
    {
        $businessId = (string) $this->argument('business_id');
        $business = Str::isUuid($businessId) ? Business::find($businessId) : null;
        if ($business === null) {
            $this->error('Business not found.');

            return self::FAILURE;
        }

        $type = (string) $this->argument('type');
        if (! in_array($type, ['customers', 'raw-materials'], true)) {
            $this->error("Unknown type '{$type}'. Use 'customers' or 'raw-materials'.");

            return self::FAILURE;
        }

        $path = (string) $this->argument('path');
        if (! is_readable($path)) {
            $this->error("File not readable: {$path}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $rows = (new CsvReader())->rows($path);

        if ($type === 'customers') {
            $report = $importer->importCustomers($business->id, $rows, $dryRun);
        } else {
            $ownerUserId = $this->ownerUserId($business->id);
            if ($ownerUserId === null) {
                $this->error('No owner membership found for this business.');

                return self::FAILURE;
            }
            $report = $importer->importRawMaterials($business->id, $rows, $dryRun, $ownerUserId);
        }

        $this->reportOut($report, $dryRun);

        return $report->hasErrors() ? self::FAILURE : self::SUCCESS;
    }

    private function reportOut(ImportReport $report, bool $dryRun): void
    {
        $this->info($report->summaryLine());

        foreach ($report->errors as $error) {
            $this->warn("Row {$error['row']}: {$error['message']}");
        }

        if ($dryRun) {
            $this->info('Dry run — nothing was persisted.');
        }
    }

    /**
     * The owner's user id, read under tenant context (memberships are RLS-scoped
     * to the current tenant). Opening-stock movements are attributed to them.
     */
    private function ownerUserId(string $businessId): ?int
    {
        return DB::transaction(function () use ($businessId) {
            TenantContext::switchTo($businessId);

            return Membership::where('business_id', $businessId)
                ->where('role', 'owner')
                ->first()?->user_id;
        });
    }
}
