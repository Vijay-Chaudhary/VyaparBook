<?php
// app/Traits/HasSyncSequence.php

namespace App\Traits;

use Illuminate\Support\Facades\DB;

/**
 * Stamps a globally monotonic `sync_seq` on every insert and update, drawn from
 * the `sync_seq_global` sequence. Delta pull orders rows by this column and
 * resumes from the max value it last returned.
 *
 * Kept separate from HasVersion deliberately: `version` is a per-row counter for
 * conflict detection (it starts at 1 on every row), while `sync_seq` is a
 * cross-row cursor. They answer different questions and neither derives from the
 * other.
 */
trait HasSyncSequence
{
    public static function bootHasSyncSequence(): void
    {
        static::saving(function ($model) {
            // Draw on the model's own connection so a row written via pgsql_migrate
            // (tests, seeders) and one written via pgsql (a request) both advance
            // the same sequence. nextval() needs USAGE on the sequence, which the
            // restricted role has by default privilege — see the sequence migration.
            $next = DB::connection($model->getConnectionName())
                ->selectOne('SELECT nextval(?) AS v', ['sync_seq_global'])->v;

            $model->sync_seq = (int) $next;
        });
    }
}
