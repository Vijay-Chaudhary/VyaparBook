<?php
// app/Console/Commands/TenantExportCommand.php

namespace App\Console\Commands;

use App\Export\TenantExporter;
use App\Models\Business;
use App\Platform\PlatformAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Operator-run per-tenant export (PRD §13): the portability and offboarding
 * deliverable, and the thing to run before tenant:erase.
 *
 * Writes one JSON file containing every tenant-owned row, read under the
 * tenant's own bound context. The export is audited — handing a shop's complete
 * books to someone is exactly the kind of action the trail exists for.
 */
class TenantExportCommand extends Command
{
    // Braces cannot appear in a description: the signature parser reads any
    // {...} as another argument token.
    protected $signature = 'tenant:export {business_id} {--output= : File to write, default ./tenant-ID-TIMESTAMP.json}';

    protected $description = 'Export all of a tenant\'s data to a portable JSON file.';

    public function handle(TenantExporter $exporter): int
    {
        $businessId = (string) $this->argument('business_id');

        if (! Str::isUuid($businessId) || Business::find($businessId) === null) {
            $this->error('Business not found.');

            return self::FAILURE;
        }

        $path = (string) ($this->option('output') ?: sprintf('tenant-%s-%s.json', $businessId, now()->format('Ymd-His')));

        try {
            $manifest = $exporter->exportToFile($businessId, $path);
        } catch (Throwable $e) {
            $this->error('Export failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $total = array_sum($manifest['counts']);

        // admin_user_id is null here: this runs on the box, not as a logged-in
        // console admin. The trail still records that it happened and to whom.
        PlatformAudit::record('export_tenant', $businessId, [
            'via' => 'cli',
            'path' => $path,
            'rows' => $total,
            'format_version' => $manifest['format_version'],
        ]);

        $this->info(sprintf('Exported %d rows across %d tables to %s', $total, count($manifest['counts']), $path));

        foreach ($manifest['counts'] as $table => $count) {
            $this->line(sprintf('  %-24s %d', $table, $count));
        }

        return self::SUCCESS;
    }
}
