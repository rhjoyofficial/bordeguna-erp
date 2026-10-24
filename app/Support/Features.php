<?php

namespace App\Support;

/**
 * The switches for the features Joy hid on 24 Oct 2026 (config/features.php).
 *
 * ⚠⚠ ONE QUESTION, ONE PLACE. Every menu, badge, search group, signal and
 * route gate asks `Features::on()` and nothing else, so turning a feature back
 * on is the config line and never a hunt through the screens. An unknown key
 * is OFF: a typo must hide, not show.
 */
final class Features
{
    public const CHAT = 'chat';

    public const CRM_TICKETS = 'crm_tickets';

    public const RECRUITMENT = 'recruitment';

    public const BUDGETS = 'budgets';

    public const CASH_FORECAST = 'cash_forecast';

    public const DUNNING = 'dunning';

    public const NOTES = 'notes';

    public const CALENDAR = 'calendar';

    public const ORG_CHART = 'org_chart';

    /** @var list<string> */
    public const ALL = [
        self::CHAT, self::CRM_TICKETS, self::RECRUITMENT, self::BUDGETS, self::CASH_FORECAST,
        self::DUNNING, self::NOTES, self::CALENDAR, self::ORG_CHART,
    ];

    /**
     * Reports that belong to a hidden feature: the report goes when the
     * feature goes (Budget vs actual reads nothing but the budgets).
     */
    public const REPORTS = [
        'accounting_budget_vs_actual' => self::BUDGETS,
    ];

    public static function on(string $feature): bool
    {
        return (bool) config('features.'.$feature, false);
    }

    /** A report is runnable unless it belongs to a feature that is off. */
    public static function reportOn(string $reportKey): bool
    {
        $feature = self::REPORTS[$reportKey] ?? null;

        return $feature === null || self::on($feature);
    }

    /** @return array<string, bool> every switch, for the shared Inertia props */
    public static function all(): array
    {
        return collect(self::ALL)->mapWithKeys(fn (string $feature) => [$feature => self::on($feature)])->all();
    }
}
