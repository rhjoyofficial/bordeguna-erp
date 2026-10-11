<?php

namespace App\Support\Attention\Sources;

use App\Models\User;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\CourierStatement;
use App\Support\Attention\AttentionItem;
use App\Support\Dashboards\Widgets\CourierCodChainWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Delivered parcels whose cash the courier still holds past the finance
 * policy's days: the COD chain's "overdue" stage, one item per courier (each
 * courier's money stays its own line, Joy 11 Oct 2026).
 */
class CourierSettlementOverdueSource extends Source
{
    public function key(): string
    {
        return 'courier_settlement_overdue';
    }

    public function area(): string
    {
        return 'couriers';
    }

    protected function permissions(): array
    {
        return ['sales.reports.view', 'accounting.view'];
    }

    public function items(User $user, int $businessId): array
    {
        $days = CourierCodChainWidget::alertDays($businessId);
        $cutoff = Carbon::today()->subDays($days)->toDateString();

        $groups = Consignment::query()
            ->where('business_id', $businessId)
            ->whereIn('status', [ConsignmentStatus::Delivered->value, ConsignmentStatus::PartiallyDelivered->value])
            ->where('collected_amount', '>', 0)
            ->whereNotNull('outcome_on')
            ->whereDate('outcome_on', '<=', $cutoff)
            ->whereDoesntHave('statementLine', fn (Builder $line) => $line
                ->whereHas('statement', fn (Builder $s) => $s->where('status', CourierStatement::POSTED)))
            ->with('courier:id,name')
            ->get(['id', 'money_account_id', 'collected_amount', 'outcome_on'])
            ->groupBy('money_account_id');

        $items = [];

        foreach ($groups as $accountId => $parcels) {
            $oldest = Carbon::parse($parcels->min(fn (Consignment $c) => (string) $c->outcome_on));
            $late = $oldest->lt(Carbon::today()->subDays($days * 2));

            $items[] = $this->item(
                (string) $accountId,
                $late ? AttentionItem::CRITICAL : AttentionItem::HIGH,
                'cod_overdue',
                '{count} delivered parcels on {courier} not settled after {days} days (Tk {amount}).',
                [
                    'count' => $parcels->count(),
                    'courier' => (string) ($parcels->first()->courier?->name ?? ''),
                    'days' => $days,
                    'amount' => self::money($parcels->sum(fn (Consignment $c) => (float) $c->collected_amount)),
                ],
                '/sales/courier-statements',
                ['since' => $oldest, 'ownerRole' => 'Finance', 'count' => $parcels->count()],
            );
        }

        return $items;
    }
}
