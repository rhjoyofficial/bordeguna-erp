<?php

namespace App\Support\Dashboards\Widgets;

use App\Events\Dashboards\DashboardEventType;
use App\Modules\Accounting\Support\MoneyAvailability;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\Widget;
use App\Support\Dashboards\WidgetContext;

/**
 * The balance of every active cash box, bank account, wallet, courier and
 * gateway account, from the ledger through MoneyAvailability::balanceMinor
 * (the same reading the payment forms use, courier and gateway filtered by
 * their own money_account_id, Slice 0 D4).
 */
class MoneyAccountsWidget extends Widget
{
    public function key(): string
    {
        return 'money_accounts';
    }

    public function title(): string
    {
        return 'Balance per account';
    }

    public function description(): ?string
    {
        return 'Every active account with its posted balance. Courier and gateway lines are money owed to us, not cash.';
    }

    public function category(): string
    {
        return 'Money';
    }

    public function dashboards(): array
    {
        return [DashboardKey::Finance, DashboardKey::Owner];
    }

    public function permission(): ?string
    {
        return 'accounting.view';
    }

    public function size(): string
    {
        return 'md';
    }

    public function cacheSeconds(): int
    {
        return 20;
    }

    public function drillDown(WidgetContext $context): ?string
    {
        return '/accounting/cash-and-bank';
    }

    public function refreshOn(): array
    {
        return [DashboardEventType::PAYMENT_RECEIVED];
    }

    public function data(WidgetContext $context): array
    {
        $accounts = MoneyAccount::query()->where('is_active', true)->orderBy('type')->orderBy('name')->get();

        return [
            'kind' => 'list',
            'columns' => [
                ['key' => 'name', 'label' => 'Account'],
                ['key' => 'type', 'label' => 'Kind'],
                ['key' => 'balance', 'label' => 'Balance', 'align' => 'right', 'unit' => 'money'],
            ],
            'rows' => $accounts->map(fn (MoneyAccount $account) => [
                'id' => $account->id,
                'url' => '/accounting/cash-and-bank',
                'cells' => [
                    'name' => $account->name,
                    'type' => $account->type->label(),
                    'balance' => self::taka(MoneyAvailability::balanceMinor($account)),
                ],
            ])->values()->all(),
            'total_count' => $accounts->count(),
            'basis' => 'ledger',
        ];
    }
}
