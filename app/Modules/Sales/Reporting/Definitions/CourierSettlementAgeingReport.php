<?php

namespace App\Modules\Sales\Reporting\Definitions;

use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Hrm\Reporting\Contracts\ChartsOnScreen;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use App\Modules\Hrm\Reporting\ReportColumn;
use App\Modules\Hrm\Reporting\ReportMeta;
use App\Modules\Hrm\Reporting\ReportParameter;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Models\CourierStatement;
use App\Modules\Sales\Reporting\Concerns\FiltersByOrderBrand;
use App\Modules\Sales\Support\OrderBrand;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Courier settlement ageing (reports pack, 11 Oct 2026): cash on delivery the
 * couriers collected for us and have not paid out, by how long it has waited.
 *
 * ⭐ The same population as the COD chain widget's "Recorded, not settled":
 * parcels recorded delivered or part delivered with cash collected, on no
 * POSTED payout statement. As at a day, both halves are dated: the outcome
 * recorded by the day, and a statement counts only if it was posted by it.
 *
 * Age = whole days from the courier's answer (`outcome_on`) to the day.
 * Overdue uses the finance policy's courier_settlement_alert_days, by the
 * widget's rule (waited that many days or more), so the report and the
 * dashboard alert never disagree about which parcels are late.
 *
 * ⚠ Operational, from the parcels: what 1145 holds per courier is the ledger's
 * figure and is on Cash position. The two differ by fees a courier took on a
 * statement that paid nothing out, which the parcels screen already explains.
 */
class CourierSettlementAgeingReport implements ChartsOnScreen, ReportDefinition
{
    use FiltersByOrderBrand;

    private const BUCKETS = [
        'days_0_3' => [0, 3, '0-3 days'],
        'days_4_7' => [4, 7, '4-7 days'],
        'days_8_14' => [8, 14, '8-14 days'],
        'days_15_plus' => [15, null, '15+ days'],
    ];

    public function key(): string
    {
        return 'courier_settlement_ageing';
    }

    public function name(): string
    {
        return 'Courier settlement ageing';
    }

    public function description(): string
    {
        return 'Cash on delivery collected by couriers and not yet paid out on a posted statement, by courier and by days waiting (0-3, 4-7, 8-14, 15+), with the parcels overdue under the finance policy.';
    }

    public function group(): string
    {
        return 'Sales';
    }

    public function permission(): string
    {
        return 'sales.reports.view';
    }

    public function parameters(): array
    {
        $couriers = DB::table('money_accounts')
            ->where('business_id', $this->businessId())
            ->where('type', 'courier')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($c) => ['value' => (string) $c->id, 'label' => (string) $c->name])
            ->all();

        return [
            ReportParameter::date('as_at', 'As at', required: true, default: now()->toDateString()),
            $this->brandParameter(),
            ReportParameter::select('courier', 'Courier', $couriers),
            ReportParameter::select('group_by', 'Show', [
                ['value' => 'courier', 'label' => 'Summary by courier'],
                ['value' => 'parcel', 'label' => 'Every parcel'],
            ], default: 'courier'),
        ];
    }

    public function columns(?ReportParameters $parameters = null): array
    {
        if ($this->perParcel($parameters)) {
            return [
                ReportColumn::text('courier', 'Courier', 14),
                ReportColumn::text('consignment', 'Consignment', 16),
                ReportColumn::text('challan', 'Challan', 14),
                ReportColumn::text('brand', 'Brand', 14),
                ReportColumn::text('status', 'Status', 14),
                ReportColumn::date('answered', 'Courier answered'),
                ReportColumn::integer('days', 'Days waiting'),
                ReportColumn::text('bucket', 'Age', 10),
                ReportColumn::money('collected', 'Collected, not paid out'),
                ReportColumn::boolean('overdue', 'Overdue'),
            ];
        }

        $buckets = [];

        foreach (self::BUCKETS as $key => [, , $label]) {
            $buckets[] = ReportColumn::money($key, $label);
        }

        return [
            ReportColumn::text('courier', 'Courier', 18),
            ReportColumn::integer('parcels', 'Parcels'),
            ReportColumn::money('collected', 'Collected, not paid out'),
            ...$buckets,
            ReportColumn::integer('overdue_parcels', 'Overdue parcels'),
            ReportColumn::money('overdue_amount', 'Overdue amount'),
        ];
    }

    public function totalColumns(?ReportParameters $parameters = null): array
    {
        if ($this->perParcel($parameters)) {
            return ['collected'];
        }

        return ['parcels', 'collected', ...array_keys(self::BUCKETS), 'overdue_parcels', 'overdue_amount'];
    }

    public function rows(ReportParameters $parameters): LazyCollection
    {
        return LazyCollection::make(function () use ($parameters) {
            $asAt = $this->asAt($parameters);
            $alertDays = $this->alertDays();
            $parcels = $this->query($parameters, $asAt)
                ->orderBy('consignments.outcome_on')
                ->orderBy('consignments.id')
                ->get([
                    'consignments.id', 'consignments.consignment_number', 'consignments.status', 'consignments.delivery_id',
                    'consignments.outcome_on', 'consignments.collected_amount', 'deliveries.delivery_number', 'money_accounts.name as courier',
                ]);

            if ($this->perParcel($parameters)) {
                $brands = OrderBrand::forDeliveries($parcels->pluck('delivery_id'));
                $names = $this->brandNames();

                foreach ($parcels as $parcel) {
                    $days = $this->days($parcel->outcome_on, $asAt);

                    yield [
                        'courier' => (string) $parcel->courier,
                        'consignment' => (string) $parcel->consignment_number,
                        'challan' => (string) $parcel->delivery_number,
                        'brand' => self::brandLabel($brands[(int) $parcel->delivery_id] ?? null, $names),
                        'status' => ConsignmentStatus::tryFrom((string) $parcel->status)?->label() ?? (string) $parcel->status,
                        'answered' => substr((string) $parcel->outcome_on, 0, 10),
                        'days' => $days,
                        'bucket' => self::BUCKETS[self::bucket($days)][2],
                        'collected' => round((float) $parcel->collected_amount, 2),
                        'overdue' => $days >= $alertDays,
                    ];
                }

                return;
            }

            $couriers = [];

            foreach ($parcels as $parcel) {
                $days = $this->days($parcel->outcome_on, $asAt);
                $amount = (float) $parcel->collected_amount;
                $name = (string) $parcel->courier;

                $couriers[$name] ??= ['parcels' => 0, 'collected' => 0.0, 'overdue_parcels' => 0, 'overdue_amount' => 0.0]
                    + array_fill_keys(array_keys(self::BUCKETS), 0.0);

                $couriers[$name]['parcels']++;
                $couriers[$name]['collected'] += $amount;
                $couriers[$name][self::bucket($days)] += $amount;

                if ($days >= $alertDays) {
                    $couriers[$name]['overdue_parcels']++;
                    $couriers[$name]['overdue_amount'] += $amount;
                }
            }

            ksort($couriers);

            foreach ($couriers as $name => $row) {
                $out = ['courier' => $name, 'parcels' => $row['parcels'], 'collected' => round($row['collected'], 2)];

                foreach (array_keys(self::BUCKETS) as $key) {
                    $out[$key] = round($row[$key], 2);
                }

                yield $out + ['overdue_parcels' => $row['overdue_parcels'], 'overdue_amount' => round($row['overdue_amount'], 2)];
            }
        });
    }

    public function estimatedRows(ReportParameters $parameters): int
    {
        return $this->query($parameters, $this->asAt($parameters))->count();
    }

    public function countRows(ReportParameters $parameters): int
    {
        return $this->perParcel($parameters) ? $this->estimatedRows($parameters) : $this->rows($parameters)->count();
    }

    public function meta(ReportParameters $parameters): ReportMeta
    {
        $days = $this->alertDays();

        return new ReportMeta(
            title: $this->name(),
            subtitle: 'As at '.$this->asAt($parameters)->format('d M Y'),
            note: 'Basis: parcels recorded delivered or part delivered with cash collected by the day, that no payout statement posted by the day has settled; the amount is what the courier collected. '
                ."Days waiting count from the courier's answer. Overdue means waiting {$days} days or more (finance policy, courier settlement alert days). "
                .'Operational, from the parcels: the ledger balance with each courier (1145) is on Cash position, and can differ by fees a courier took on a statement that paid nothing out.',
        );
    }

    public function chart(ReportParameters $parameters): ?array
    {
        if ($this->perParcel($parameters)) {
            return [
                'type' => 'bar',
                'title' => 'Waiting for payout, by courier',
                'label' => 'courier',
                'series' => [['key' => 'collected', 'label' => 'Collected, not paid out']],
            ];
        }

        $series = [];

        foreach (self::BUCKETS as $key => [, , $label]) {
            $series[] = ['key' => $key, 'label' => $label];
        }

        return ['type' => 'stacked_bar', 'title' => 'Waiting for payout, by age', 'label' => 'courier', 'series' => $series];
    }

    private function query(ReportParameters $parameters, Carbon $asAt): Builder
    {
        $business = $this->businessId();
        $on = $asAt->toDateString();
        $courier = $parameters->string('courier');

        $query = DB::table('consignments')
            ->join('deliveries', 'deliveries.id', '=', 'consignments.delivery_id')
            ->join('money_accounts', 'money_accounts.id', '=', 'consignments.money_account_id')
            ->where('consignments.business_id', $business)
            ->whereIn('consignments.status', [ConsignmentStatus::Delivered->value, ConsignmentStatus::PartiallyDelivered->value])
            ->where('consignments.collected_amount', '>', 0)
            ->whereNotNull('consignments.outcome_on')
            ->whereDate('consignments.outcome_on', '<=', $on)
            // ⚠ Settled = on a statement POSTED by the day. A draft statement
            // has paid nothing, and one posted later had not paid it yet.
            ->whereNotExists(fn (Builder $sub) => $sub->selectRaw('1')
                ->from('courier_statement_lines')
                ->join('courier_statements', 'courier_statements.id', '=', 'courier_statement_lines.courier_statement_id')
                ->whereColumn('courier_statement_lines.consignment_id', 'consignments.id')
                ->where('courier_statements.business_id', $business)
                ->where('courier_statements.status', CourierStatement::POSTED)
                ->whereNotNull('courier_statements.posted_at')
                ->whereDate('courier_statements.posted_at', '<=', $on))
            ->when($courier !== null && ctype_digit($courier), fn (Builder $q) => $q->where('consignments.money_account_id', '=', (int) $courier));

        return OrderBrand::onConsignments($query, $this->brandFilter($parameters));
    }

    private function asAt(ReportParameters $parameters): Carbon
    {
        return ($parameters->date('as_at') ?? Carbon::today())->startOfDay();
    }

    private function alertDays(): int
    {
        return (int) (FinancePolicy::for($this->businessId())->courier_settlement_alert_days ?: 7);
    }

    private function days(mixed $outcomeOn, Carbon $asAt): int
    {
        return max(0, (int) Carbon::parse(substr((string) $outcomeOn, 0, 10))->startOfDay()->diffInDays($asAt, false));
    }

    private static function bucket(int $days): string
    {
        foreach (self::BUCKETS as $key => [$from, $to]) {
            if ($days >= $from && ($to === null || $days <= $to)) {
                return $key;
            }
        }

        return 'days_15_plus';
    }

    private function perParcel(?ReportParameters $parameters): bool
    {
        return $parameters?->string('group_by') === 'parcel';
    }
}
