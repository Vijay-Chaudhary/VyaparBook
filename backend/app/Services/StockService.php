<?php
// app/Services/StockService.php

namespace App\Services;

use App\Models\RawMaterial;
use Illuminate\Support\Collection;

class StockService
{
    /**
     * On hand = Σ qty over the material's movements. qty is signed (in +, out −,
     * adjust ±), so the sum is the real balance — always recomputable, never a
     * stored number that can drift from the ledger (PRD §10).
     *
     * ::text so Postgres returns an exact decimal string, never a PHP float that
     * could drift before we read it. Scale 3 mirrors the movement/reorder scale.
     */
    public function onHandFor(RawMaterial $material): string
    {
        $sum = (string) $material->movements()->selectRaw('coalesce(sum(qty), 0)::text as agg')->value('agg');

        return bcadd($sum, '0', 3);
    }

    /**
     * True when a reorder level is set and on-hand has fallen below it. Negative
     * on-hand (over-consumption) is naturally below any non-negative threshold.
     * A null reorder_level means "not tracked" → never flagged.
     */
    public function belowReorder(RawMaterial $material): bool
    {
        if ($material->reorder_level === null) {
            return false;
        }

        return bccomp($this->onHandFor($material), (string) $material->reorder_level, 3) < 0;
    }

    /**
     * The per-material ledger: every movement in time order (movement_date, then
     * created_at as the tiebreak) with a running on-hand. The last running value
     * equals onHandFor(). bcadd at scale 3 — quantities must not drift.
     */
    public function ledgerFor(RawMaterial $material): Collection
    {
        $movements = $material->movements()->get()
            ->sortBy([
                ['movement_date', 'asc'],
                fn ($a, $b) => $a->created_at <=> $b->created_at,
            ])
            ->values();

        $running = '0.000';

        return $movements->map(function ($movement) use (&$running) {
            $running = bcadd($running, (string) $movement->qty, 3);

            return [
                'movement' => $movement,
                'running_on_hand' => $running,
            ];
        });
    }
}
