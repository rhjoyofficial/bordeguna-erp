<?php

namespace App\Support\Dashboards\Widgets;

use App\Models\User;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Sales\Couriers\Enums\CourierStatus;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\CourierStatement;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\WidgetContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Cash on delivery, stage by stage (ChatGPT's most important correction:
 * delivery, COD collection, recording and courier settlement are separate
 * events):
 *
 *   1 Booked, awaiting delivery   COD on parcels the courier still carries
 *   2 Courier says collected      the courier reported delivery, nobody has recorded the outcome yet
 *   3 Recorded, not settled       the outcome is recorded (Dr 1145), no posted statement paid it out
 *   4 Settled (range)             paid out on a statement posted inside the range
 *   5 Overdue                     of stage 3, answered more than finance_policies.courier_settlement_alert_days ago
 *
 * Operational, from the parcels; the ledger's own balance per courier is
 * `courier_balances`.
 */
class CourierCodChainWidget extends CourierWidget
{
    public function key(): string
    {
        return 'courier_cod_chain';
    }

    public function title(): string
    {
        return 'Cash on delivery chain';
    }

    public function description(): ?string
    {
        return 'Where each parcel\'s cash on delivery stands: with the courier, reported collected, recorded in the ERP, settled on a posted statement, or overdue by the finance policy\'s days. Operational, from the parcels.';
    }

    public function dashboards(): array
    {
        return [DashboardKey::Owner, DashboardKey::Finance, DashboardKey::Manager, DashboardKey::Sales];
    }

    public function permission(): ?string
    {
        return 'sales.reports.view';
    }

    /** Sales reporting or the books: either one already shows these amounts. */
    public function authorize(User $user): bool
    {
        return $user->can('sales.reports.view') || $user->can('accounting.view');
    }

    public function size(): string
    {
        return 'md';
    }

    public function dateBasis(): ?string
    {
        return 'statement posted date';
    }

    public function drillDown(WidgetContext $context): ?string
    {
        return '/sales/courier-statements';
    }

    public function notifications(): array
    {
        return [['watch' => 'overdue', 'sound' => 'attention', 'message' => 'A courier settlement is overdue.']];
    }

    public static function alertDays(int $businessId): int
    {
        return (int) (FinancePolicy::for($businessId)->courier_settlement_alert_days ?: 7);
    }

    public function data(WidgetContext $context): array
    {
        $business = $context->businessId;
        $days = self::alertDays($business);
        $base = fn () => Consignment::query()->where('business_id', $business);
        $settled = fn (Builder $q) => $q->whereHas('statementLine', fn (Builder $line) => $line
            ->whereHas('statement', fn (Builder $s) => $s->where('status', CourierStatement::POSTED)));
        $notBackYet = [...CourierStatus::finalValues(), CourierStatus::Returning->value];

        $awaiting = $base()
            ->where('status', ConsignmentStatus::Booked->value)
            ->where(fn ($q) => $q->whereNull('courier_status')->orWhereNotIn('courier_status', $notBackYet));

        $reported = $base()
            ->where('status', ConsignmentStatus::Booked->value)
            ->whereIn('courier_status', self::values(self::COLLECTED_STATUSES));

        $recorded = fn () => $base()
            ->whereIn('status', [ConsignmentStatus::Delivered->value, ConsignmentStatus::PartiallyDelivered->value])
            ->where('collected_amount', '>', 0)
            ->whereNot(fn ($q) => $settled($q));

        $settledInRange = $base()->whereHas('statementLine', fn (Builder $line) => $line
            ->whereHas('statement', fn (Builder $s) => $s
                ->where('status', CourierStatement::POSTED)
                ->whereBetween('posted_at', [$context->range->fromDate().' 00:00:00', $context->range->toDate().' 23:59:59'])));

        $overdue = $recorded()->whereNotNull('outcome_on')->whereDate('outcome_on', '<=', Carbon::today()->subDays($days)->toDateString());

        $stage = fn (Builder $q, string $column) => (clone $q)->selectRaw("COUNT(*) as parcels, SUM({$column}) as total")->first();

        $stages = [
            'awaiting' => [$stage($awaiting, 'cod_amount'), 'Booked, awaiting delivery', null],
            'reported' => [$stage($reported, 'COALESCE(courier_collected_amount, cod_amount)'), 'Courier says collected', null],
            'recorded' => [$stage($recorded(), 'collected_amount'), 'Recorded, not settled', null],
            'settled' => [$stage($settledInRange, 'collected_amount'), 'Settled (range)', 'good'],
            'overdue' => [$stage($overdue, 'collected_amount'), "Overdue (over {$days} days)", 'bad'],
        ];

        $items = [];

        foreach ($stages as $key => [$row, $label, $tone]) {
            $count = (int) ($row->parcels ?? 0);
            $items[] = [
                'label' => $label,
                'value' => self::taka(self::minor($row->total ?? 0)),
                'tone' => $key === 'overdue' ? ($count > 0 ? 'bad' : null) : $tone,
                'hint' => $count === 1 ? '1 parcel' : "{$count} parcels",
                'url' => in_array($key, ['recorded', 'settled', 'overdue'], true) ? '/sales/courier-statements' : '/sales/consignments',
            ];
        }

        return [
            'kind' => 'breakdown',
            'chart' => 'bar',
            'unit' => 'money',
            'items' => $items,
            'caption' => "Overdue uses the finance policy: {$days} days from the courier's answer.",
            'basis' => 'operational',
            'watch' => ['overdue' => (int) ($stages['overdue'][0]->parcels ?? 0)],
        ];
    }
}
