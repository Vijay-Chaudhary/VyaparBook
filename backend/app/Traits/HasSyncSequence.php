<?php
// app/Traits/HasSyncSequence.php

namespace App\Traits;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Stamps a monotonic `sync_seq` on every insert and update. Delta pull orders
 * rows by this column and resumes from the max value it last returned.
 *
 * Postgres drew this from a lock-free global sequence. MySQL has none, so it
 * comes from a per-tenant counter row (see the sync_sequences migration): a
 * single global counter would serialise every write on the platform, and
 * sync_seq only needs to be monotonic WITHIN a tenant because the delta pull is
 * always scoped by business_id.
 *
 * Kept separate from HasVersion deliberately: `version` is a per-row counter for
 * conflict detection, while `sync_seq` is a cross-row cursor. They answer
 * different questions and neither derives from the other.
 */
trait HasSyncSequence
{
    public static function bootHasSyncSequence(): void
    {
        static::saving(function ($model) {
            $model->sync_seq = self::nextSyncSeq(self::resolveTenantId($model));
        });
    }

    /**
     * Whose counter to draw from.
     *
     * Eloquent fires `saving` BEFORE `creating`, and BelongsToTenant sets
     * business_id on `creating` — so on a brand-new model the attribute is
     * still null here and the container binding is the only source. Removing
     * this fallback breaks every insert.
     */
    private static function resolveTenantId($model): string
    {
        $tenantId = $model->business_id ?? app('tenant.id');

        if ($tenantId === null) {
            throw new RuntimeException(
                'Cannot draw a sync_seq without a tenant: '.$model::class.
                ' is synced, and every synced row belongs to exactly one business.'
            );
        }

        return (string) $tenantId;
    }

    /**
     * Atomically take the next value for one tenant.
     *
     * UPDATE ... LAST_INSERT_ID(value + 1) is MySQL's sequence idiom: the
     * increment and the read are one statement, so two concurrent writers
     * cannot take the same number. LAST_INSERT_ID() is per-connection, so the
     * read afterwards is not racy either.
     *
     * The INSERT IGNORE makes it self-healing — a tenant with no counter row
     * gets one rather than silently receiving sync_seq 0 forever.
     */
    private static function nextSyncSeq(string $tenantId): int
    {
        DB::insert(
            'INSERT IGNORE INTO sync_sequences (business_id, value) VALUES (?, 0)',
            [$tenantId]
        );

        DB::update(
            'UPDATE sync_sequences SET value = LAST_INSERT_ID(value + 1) WHERE business_id = ?',
            [$tenantId]
        );

        return (int) DB::selectOne('SELECT LAST_INSERT_ID() AS v')->v;
    }
}
