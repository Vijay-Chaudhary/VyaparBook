<?php
// app/Services/BeatService.php

namespace App\Services;

use App\Models\Beat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which beats run today, and for whom (PRD Phase 3).
 *
 * Read-only and tenant-pinned like the other services. Scheduling is a weekday
 * cycle rather than a calendar, so "today" is answered without anyone having to
 * keep planning every week — an unmaintained calendar leaves a salesman staring
 * at an empty screen, which reads as broken rather than as "nothing today".
 */
class BeatService
{
    /**
     * Beats scheduled for $date, optionally only those assigned to one user.
     *
     * @return Collection<int, Beat>
     */
    public function forDate(string $businessId, Carbon $date, ?int $assignedUserId = null): Collection
    {
        $isoDay = (int) $date->isoWeekday();   // 1 = Monday … 7 = Sunday

        $query = Beat::query()
            ->where('business_id', $businessId)
            ->whereNull('archived_at')
            // JSON_CONTAINS is MySQL's equivalent of Postgres's `@>`: true when
            // every element of the candidate array is present in the column.
            ->whereRaw('json_contains(weekdays, ?)', [json_encode([$isoDay])])
            ->with(['beatCustomers.customer'])
            ->orderBy('name');

        if ($assignedUserId !== null) {
            $query->where('assigned_user_id', $assignedUserId);
        }

        return $query->get();
    }
}
