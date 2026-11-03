<?php

namespace App\Support;

/**
 * What each staff role may do. One table, so who can do what is read in one
 * place and tested as one (tests/Unit/RolesTest.php).
 *
 * The roles are those of the plan: the owner does everything; sales sells
 * (customers, trials, packages); finance bills and collects, and may block a
 * company over a debt; support looks and helps; a viewer only reads.
 */
class Roles
{
    public const ABILITIES = [
        'customers.view', 'customers.manage',
        'packages.view', 'packages.manage',
        'subscriptions.view', 'subscriptions.assign', 'subscriptions.block',
        'billing.view', 'billing.manage',
        'support.impersonate',
        'reports.view', 'audit.view',
        'staff.manage',
    ];

    private const VIEW = ['customers.view', 'packages.view', 'subscriptions.view', 'billing.view', 'reports.view'];

    /** @return array<string, array<int, string>> */
    public static function map(): array
    {
        return [
            'owner' => self::ABILITIES,
            'sales' => ['customers.view', 'customers.manage', 'packages.view', 'subscriptions.view', 'subscriptions.assign', 'reports.view'],
            'finance' => ['customers.view', 'packages.view', 'subscriptions.view', 'subscriptions.block', 'billing.view', 'billing.manage', 'reports.view'],
            'support' => ['customers.view', 'packages.view', 'subscriptions.view', 'support.impersonate'],
            'viewer' => self::VIEW,
        ];
    }

    /** @return array<int, string> */
    public static function names(): array
    {
        return array_keys(self::map());
    }

    public static function allows(?string $role, string $ability): bool
    {
        return in_array($ability, self::map()[$role] ?? [], true);
    }
}
