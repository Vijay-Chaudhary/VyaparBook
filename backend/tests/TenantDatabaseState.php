<?php

namespace Tests;

/**
 * Holds the once-per-run migration flag and the schema shape the between-test
 * sweep needs. A static on the trait itself would be rebound per using-class,
 * re-migrating for every test class.
 */
final class TenantDatabaseState
{
    public static bool $migrated = false;

    /**
     * Every table the sweep clears. Cached because the schema cannot change
     * mid-run and the information_schema lookup costs ~250ms cold.
     *
     * @var list<string>|null
     */
    public static ?array $tables = null;

    /**
     * The subset carrying an AUTO_INCREMENT column, as a set. Only these need
     * their identity reset, and only when the sweep actually removed rows.
     *
     * @var array<string, true>|null
     */
    public static ?array $autoIncrementTables = null;
}
