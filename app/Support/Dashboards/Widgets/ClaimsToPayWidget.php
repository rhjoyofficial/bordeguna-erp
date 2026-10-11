<?php

namespace App\Support\Dashboards\Widgets;

use App\Events\Dashboards\DashboardEventType;
use App\Modules\Hrm\Models\ExpenseClaim;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\Widget;
use App\Support\Dashboards\WidgetContext;

/**
 * Approved expense claims nobody has paid back yet (ExpenseClaim::
 * awaitingSettlement, the list the claims screen settles from).
 */
class ClaimsToPayWidget extends Widget
{
    public function key(): string
    {
        return 'claims_to_pay';
    }

    public function title(): string
    {
        return 'Expense claims to pay';
    }

    public function description(): ?string
    {
        return 'Approved claims waiting to be paid back or added to payroll.';
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
        // Paying claims back is a payroll act (the settle routes' gate).
        return 'hrm.payroll.manage';
    }

    public function size(): string
    {
        return 'md';
    }

    public function drillDown(WidgetContext $context): ?string
    {
        return '/hrm/expense-claims';
    }

    public function refreshOn(): array
    {
        return [DashboardEventType::CLAIM_SUBMITTED, DashboardEventType::APPROVAL_DECIDED];
    }

    public function notifications(): array
    {
        return [['watch' => 'to_pay', 'sound' => 'attention', 'message' => 'An approved claim is waiting to be paid.']];
    }

    public function data(WidgetContext $context): array
    {
        $claims = ExpenseClaim::query()->awaitingSettlement()->with('employee:id,first_name,last_name')->orderBy('spent_on')->get();

        return [
            'kind' => 'list',
            'parts' => [
                ['label' => 'Claims', 'value' => $claims->count(), 'unit' => 'count'],
                ['label' => 'Total', 'value' => round((float) $claims->sum('amount'), 2), 'unit' => 'money'],
            ],
            'columns' => [
                ['key' => 'number', 'label' => 'Claim'],
                ['key' => 'employee', 'label' => 'Employee'],
                ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'unit' => 'money'],
            ],
            'rows' => $claims->take(6)->map(fn (ExpenseClaim $claim) => [
                'id' => $claim->id,
                'url' => '/hrm/expense-claims',
                'cells' => [
                    'number' => $claim->claim_number,
                    'employee' => trim(($claim->employee?->first_name ?? '').' '.($claim->employee?->last_name ?? '')),
                    'amount' => round((float) $claim->amount, 2),
                ],
            ])->values()->all(),
            'total_count' => $claims->count(),
            'basis' => 'operational',
            'watch' => ['to_pay' => $claims->count()],
        ];
    }
}
