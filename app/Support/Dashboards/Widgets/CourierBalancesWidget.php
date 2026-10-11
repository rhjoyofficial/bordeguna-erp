<?php

namespace App\Support\Dashboards\Widgets;

use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\Ledger;
use App\Support\Dashboards\WidgetContext;

/**
 * Money with each courier (1145 Receivable from Couriers) and owed to each
 * courier (2165 Payable to Couriers), one row per courier money account, from
 * posted journal lines by money_account_id.
 *
 * ⚠⚠ Joy, 11 Oct 2026: "every line on these AND on 1145 must identify the
 * exact courier account, so each courier company's balance is separate". Two
 * couriers are never added into one row. A line that names no courier shows as
 * its own row, so a gap in the books is visible rather than hidden in a total.
 */
class CourierBalancesWidget extends CourierWidget
{
    public const RECEIVABLE = 'courier_receivable';

    public const PAYABLE = 'courier_payable';

    public function key(): string
    {
        return 'courier_balances';
    }

    public function title(): string
    {
        return 'Money with couriers';
    }

    public function description(): ?string
    {
        return 'Per courier: cash on delivery the courier holds for us (1145) and what we owe the courier (2165). Posted journals only.';
    }

    public function dashboards(): array
    {
        return [DashboardKey::Owner, DashboardKey::Finance, DashboardKey::Manager, DashboardKey::Sales];
    }

    public function permission(): ?string
    {
        return 'accounting.view';
    }

    public function size(): string
    {
        return 'md';
    }

    public function drillDown(WidgetContext $context): ?string
    {
        return '/accounting/cash-and-bank';
    }

    /**
     * @return array<int|string, array{receivable: int, payable: int}> money account id (or 'none') => paisa
     */
    public static function balances(int $businessId): array
    {
        $sums = Ledger::lines($businessId)
            ->whereIn('accounts.system_slug', [self::RECEIVABLE, self::PAYABLE])
            ->groupBy('journal_lines.money_account_id', 'accounts.system_slug')
            ->selectRaw('journal_lines.money_account_id as money_account_id, accounts.system_slug as slug, SUM(journal_lines.amount_minor) as total')
            ->get();

        $out = [];

        foreach ($sums as $row) {
            $key = $row->money_account_id === null ? 'none' : (int) $row->money_account_id;
            $out[$key] ??= ['receivable' => 0, 'payable' => 0];

            if ($row->slug === self::RECEIVABLE) {
                $out[$key]['receivable'] += (int) $row->total;
            } else {
                // A liability is a credit (negative); owed to the courier reads positive.
                $out[$key]['payable'] += -1 * (int) $row->total;
            }
        }

        return $out;
    }

    public function data(WidgetContext $context): array
    {
        $balances = self::balances($context->businessId);

        $couriers = MoneyAccount::query()
            ->where('business_id', $context->businessId)
            ->where(fn ($q) => $q->where('type', MoneyAccountType::Courier->value)->orWhereIn('id', array_filter(array_keys($balances), 'is_int')))
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);

        $rows = [];

        foreach ($couriers as $courier) {
            $figures = $balances[$courier->id] ?? ['receivable' => 0, 'payable' => 0];

            if (! $courier->is_active && $figures['receivable'] === 0 && $figures['payable'] === 0) {
                continue;
            }

            $rows[] = [
                'id' => $courier->id,
                'url' => '/accounting/cash-and-bank',
                'tone' => $figures['receivable'] < 0 ? 'warn' : null,
                'cells' => [
                    'courier' => $courier->name,
                    'with_courier' => self::taka($figures['receivable']),
                    'payable' => self::taka($figures['payable']),
                ],
            ];
        }

        if (isset($balances['none'])) {
            $rows[] = [
                'id' => 'none',
                'tone' => 'bad',
                'cells' => [
                    'courier' => 'No courier named on the line',
                    'with_courier' => self::taka($balances['none']['receivable']),
                    'payable' => self::taka($balances['none']['payable']),
                ],
            ];
        }

        if ($rows === []) {
            return ['kind' => 'empty', 'message' => 'No courier accounts yet.'];
        }

        return [
            'kind' => 'list',
            'columns' => [
                ['key' => 'courier', 'label' => 'Courier'],
                ['key' => 'with_courier', 'label' => 'With the courier', 'align' => 'right', 'unit' => 'money'],
                ['key' => 'payable', 'label' => 'We owe them', 'align' => 'right', 'unit' => 'money'],
            ],
            'rows' => $rows,
            'total_count' => count($rows),
            'basis' => 'ledger',
        ];
    }
}
