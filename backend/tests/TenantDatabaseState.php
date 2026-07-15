<?php

namespace Tests;

/**
 * Holds the once-per-run migration flag. A static on the trait itself would be
 * rebound per using-class, re-migrating for every test class.
 */
final class TenantDatabaseState
{
    public static bool $migrated = false;
}
