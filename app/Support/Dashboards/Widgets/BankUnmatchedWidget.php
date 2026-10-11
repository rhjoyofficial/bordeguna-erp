<?php

namespace App\Support\Dashboards\Widgets;

use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\Widget;
use App\Support\Dashboards\WidgetContext;
use Illuminate\Support\Facades\DB;

/**
 * Imported bank statement lines nobody has matched to a posting yet (shown on
 * no other screen until now), with the oldest one's date.
 */
class BankUnmatchedWidget extends Widget
{
    public function key(): string
    {
        return 'bank_unmatched';
    }

    public function title(): string
    {
        return 'Bank lines to match';
    }

    public function description(): ?string
    {
        return 'Imported bank statement lines not yet matched to a posting or set aside.';
    }

    public function category(): string
    {
        return 'Money';
    }

    public function dashboards(): array
    {
        return [DashboardKey::Finance];
    }

    public function permission(): ?string
    {
        return 'accounting.view';
    }

    public function drillDown(WidgetContext $context): ?string
    {
        return '/accounting/bank-statements';
    }

    public function notifications(): array
    {
        return [['watch' => 'unmatched', 'sound' => 'attention', 'message' => 'New bank lines are waiting to be matched.']];
    }

    public function data(WidgetContext $context): array
    {
        $query = DB::table('bank_statement_lines')
            ->where('business_id', $context->businessId)
            ->where('match_status', 'unmatched');

        $count = (clone $query)->count();
        $oldest = (clone $query)->min('txn_date');
        $amount = (float) (clone $query)->sum(DB::raw('ABS(amount)'));

        return [
            'kind' => 'stat',
            'unit' => 'count',
            'value' => $count,
            'tone' => $count > 0 ? 'warn' : 'good',
            'parts' => array_values(array_filter([
                ['label' => 'Amount', 'value' => round($amount, 2), 'unit' => 'money'],
                $oldest !== null ? ['label' => 'Oldest', 'value' => substr((string) $oldest, 0, 10), 'unit' => 'text'] : null,
            ])),
            'basis' => 'operational',
            'watch' => ['unmatched' => $count],
        ];
    }
}
