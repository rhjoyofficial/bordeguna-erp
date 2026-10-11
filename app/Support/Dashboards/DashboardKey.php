<?php

namespace App\Support\Dashboards;

/**
 * The five role dashboards, in rank order (Joy, 11 Oct 2026: "every person lands
 * on the dashboard of their highest role").
 *
 * ⚠ The rank is the stable tie-breaker ChatGPT asked for (ruling 1): somebody
 * holding Finance and Manager always lands on Finance, whatever order the roles
 * were granted in.
 */
enum DashboardKey: string
{
    case Owner = 'owner';
    case Finance = 'finance';
    case Manager = 'manager';
    case Sales = 'sales';
    case Employee = 'employee';

    public function rank(): int
    {
        return match ($this) {
            self::Owner => 50,
            self::Finance => 40,
            self::Manager => 30,
            self::Sales => 20,
            self::Employee => 10,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Finance => 'Finance',
            self::Manager => 'Manager',
            self::Sales => 'Sales and counter',
            self::Employee => 'My dashboard',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => 'The whole business: money, sales, stock, people and what needs you.',
            self::Finance => 'Cash per account, who owes what, reconciliation and the close.',
            self::Manager => 'Your team today, approvals, orders to dispatch and stock to reorder.',
            self::Sales => 'Your orders, your target and what the counter can sell right now.',
            self::Employee => 'Your own attendance, leave, payslip, claims and to-dos.',
        };
    }

    /**
     * Highest rank first.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        $all = self::cases();
        usort($all, fn (self $a, self $b) => $b->rank() <=> $a->rank());

        return $all;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $key) => $key->value, self::cases());
    }

    /**
     * The seeded default for a role, by its name (the migration and the seeder
     * use the same names).
     */
    public static function defaultForRoleName(string $name): ?self
    {
        return match ($name) {
            'Owner' => self::Owner,
            'Finance' => self::Finance,
            'Manager' => self::Manager,
            'Staff' => self::Sales,
            'Self-service' => self::Employee,
            default => null,
        };
    }
}
