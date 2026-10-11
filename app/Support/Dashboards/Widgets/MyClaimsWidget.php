<?php

namespace App\Support\Dashboards\Widgets;

use App\Events\Dashboards\DashboardEventType;
use App\Modules\Hrm\Models\ExpenseClaim;
use App\Support\Dashboards\WidgetContext;

/**
 * My expense claims by where they stand (ExpenseClaim::state, the claims
 * screen's own wording), newest first.
 */
class MyClaimsWidget extends EmployeeWidget
{
    public function key(): string
    {
        return 'my_claims';
    }

    public function title(): string
    {
        return 'My expense claims';
    }

    public function description(): ?string
    {
        return 'Your claims and where each one stands.';
    }

    public function size(): string
    {
        return 'md';
    }

    public function refreshSeconds(): int
    {
        return 120;
    }

    public function emptyText(): string
    {
        return 'You have no expense claims.';
    }

    public function drillDown(WidgetContext $context): ?string
    {
        return '/hrm/my-expenses';
    }

    public function refreshOn(): array
    {
        return [DashboardEventType::APPROVAL_DECIDED];
    }

    public function notifications(): array
    {
        return [['watch' => 'settled', 'sound' => 'success', 'message' => 'A claim of yours was paid.']];
    }

    public function data(WidgetContext $context): array
    {
        $me = $this->me($context);

        if ($me === null) {
            return $this->notLinked();
        }

        $claims = ExpenseClaim::query()->where('employee_id', $me->id)->latest('spent_on')->latest('id')->limit(50)->get();
        $states = $claims->groupBy(fn (ExpenseClaim $claim) => $claim->state());

        return [
            'kind' => 'list',
            'parts' => $states->map(fn ($group, $state) => [
                'label' => ucwords(str_replace('_', ' ', (string) $state)),
                'value' => $group->count(),
                'unit' => 'count',
            ])->values()->all(),
            'columns' => [
                ['key' => 'number', 'label' => 'Claim'],
                ['key' => 'state', 'label' => 'State'],
                ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'unit' => 'money'],
            ],
            'rows' => $claims->take(6)->map(fn (ExpenseClaim $claim) => [
                'id' => $claim->id,
                'url' => '/hrm/my-expenses',
                'cells' => [
                    'number' => $claim->claim_number,
                    'state' => ucwords(str_replace('_', ' ', (string) $claim->state())),
                    'amount' => round((float) $claim->amount, 2),
                ],
            ])->values()->all(),
            'total_count' => $claims->count(),
            'basis' => 'operational',
            'watch' => ['settled' => $claims->filter(fn (ExpenseClaim $claim) => $claim->isSettled())->count()],
        ];
    }
}
