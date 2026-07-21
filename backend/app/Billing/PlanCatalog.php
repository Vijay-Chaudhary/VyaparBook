<?php
// app/Billing/PlanCatalog.php

namespace App\Billing;

use InvalidArgumentException;

/**
 * The plan definitions — Free and Pro — in code, not tenant data. The set is
 * fixed and versioned with deploys. A `null` limit means unlimited (never 0);
 * an unknown plan throws rather than silently granting the world.
 */
class PlanCatalog
{
    /** A live trial grants Pro entitlement; effectivePlan() resolves it. */
    public const TRIAL_ENTITLEMENT = 'pro';

    /** @var array<string, array{max_customers: ?int, max_users: ?int, features: list<string>}> */
    private const PLANS = [
        'free' => ['max_customers' => 50, 'max_users' => 1, 'features' => []],
        'pro' => ['max_customers' => null, 'max_users' => 5, 'features' => ['stock_production']],
    ];

    /**
     * @return array{max_customers: ?int, max_users: ?int, features: list<string>}
     */
    public static function limits(string $plan): array
    {
        if (! isset(self::PLANS[$plan])) {
            throw new InvalidArgumentException("Unknown plan: {$plan}");
        }

        return self::PLANS[$plan];
    }

    public static function maxCustomers(string $plan): ?int
    {
        return self::limits($plan)['max_customers'];
    }

    public static function maxUsers(string $plan): ?int
    {
        return self::limits($plan)['max_users'];
    }

    public static function has(string $plan, string $feature): bool
    {
        return in_array($feature, self::limits($plan)['features'], true);
    }
}
