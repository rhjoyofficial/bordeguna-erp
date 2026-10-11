<?php

namespace App\Modules\Sales\Reporting\Definitions;

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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Delivery economics (reports pack, 11 Oct 2026): what the customer was billed
 * for delivery against what the courier charged us, per parcel and in total by
 * courier, brand or band.
 *
 * ⭐ The cost is labelled by where it came from, best first:
 *
 *   1 Statement           the courier's payout statement, posted: net delivery fee + COD fee
 *   2 Statement, draft    the same line on a statement not yet posted
 *   3 Courier quote       what the courier's API quoted at booking (fee + COD fee)
 *   4 Estimate            the ERP's own estimate from the rate card
 *   5 None                no figure anywhere
 *
 * Mixing an actual with an estimate silently is how a margin report lies, so
 * every row says which it is and the totals count the parcels on an actual.
 *
 * ⚠ The charge billed is the invoice line billed for the parcel's order
 * (`billed_delivery_for_order_id`), excluding VAT: what Delivery Charges
 * Income 4300 was credited. An order billed once and sent in two parcels
 * counts on its FIRST parcel; a parcel whose order is not invoiced yet (still
 * with the courier, or returned) has been billed nothing, which is the truth.
 *
 * ⚠ Extra charges, compensation and adjustments on a statement are not one
 * parcel's delivery cost and stay out; they are on the courier statement.
 */
class DeliveryEconomicsReport implements ChartsOnScreen, ReportDefinition
{
    use FiltersByOrderBrand;

    private const GROUPS = [
        'parcel' => 'Per parcel',
        'courier' => 'Total by courier',
        'brand' => 'Total by brand',
        'band' => 'Total by band',
    ];

    private const SOURCES = [
        'statement' => 'Statement',
        'statement_draft' => 'Statement, not posted',
        'quote' => 'Courier quote',
        'estimate' => 'Estimate',
        'none' => 'No figure',
    ];

    public function key(): string
    {
        return 'delivery_economics';
    }

    public function name(): string
    {
        return 'Delivery economics';
    }

    public function description(): string
    {
        return 'Per parcel: the delivery charge billed to the customer against the courier\'s actual cost (statement, else quote, else estimate, labelled), the margin and the COD fee share. Totals by courier, brand or band.';
    }

    public function group(): string
    {
        return 'Sales';
    }

    public function permission(): string
    {
        return 'sales.reports.costs';
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

        $groups = [];

        foreach (self::GROUPS as $value => $label) {
            $groups[] = ['value' => $value, 'label' => $label];
        }

        return [
            ReportParameter::dateRange('dispatched', 'Challan dated between', required: false),
            $this->brandParameter(),
            ReportParameter::select('courier', 'Courier', $couriers),
            ReportParameter::select('group_by', 'Show', $groups, default: 'parcel'),
        ];
    }

    public function columns(?ReportParameters $parameters = null): array
    {
        $money = [
            ReportColumn::money('charge_billed', 'Delivery charge billed'),
            ReportColumn::money('courier_cost', 'Courier cost'),
            ReportColumn::money('cod_fee', 'of which COD fee'),
            ReportColumn::money('margin', 'Margin'),
            ReportColumn::decimal('cod_share', 'COD fee share %', 9),
        ];

        if ($this->groupBy($parameters) === 'parcel') {
            return [
                ReportColumn::text('consignment', 'Consignment', 16),
                ReportColumn::text('challan', 'Challan', 14),
                ReportColumn::date('dispatched', 'Challan date'),
                ReportColumn::text('courier', 'Courier', 14),
                ReportColumn::text('brand', 'Brand', 14),
                ReportColumn::text('band', 'Band', 12),
                ReportColumn::text('status', 'Status', 14),
                ...$money,
                ReportColumn::text('cost_source', 'Cost from', 16),
            ];
        }

        return [
            ReportColumn::text('label', ucfirst($this->groupBy($parameters)), 18),
            ReportColumn::integer('parcels', 'Parcels'),
            ReportColumn::integer('on_actual', 'Cost from a statement'),
            ...$money,
        ];
    }

    public function totalColumns(?ReportParameters $parameters = null): array
    {
        $money = ['charge_billed', 'courier_cost', 'cod_fee', 'margin'];

        return $this->groupBy($parameters) === 'parcel' ? $money : ['parcels', 'on_actual', ...$money];
    }

    public function rows(ReportParameters $parameters): LazyCollection
    {
        return LazyCollection::make(function () use ($parameters) {
            $parcels = $this->parcels($parameters);
            $group = $this->groupBy($parameters);

            if ($group === 'parcel') {
                foreach ($parcels as $parcel) {
                    yield [
                        'consignment' => $parcel['consignment'],
                        'challan' => $parcel['challan'],
                        'dispatched' => $parcel['dispatched'],
                        'courier' => $parcel['courier'],
                        'brand' => $parcel['brand'],
                        'band' => $parcel['band'],
                        'status' => $parcel['status'],
                        ...self::money($parcel['charge_billed'], $parcel['courier_cost'], $parcel['cod_fee']),
                        'cost_source' => self::SOURCES[$parcel['source']],
                    ];
                }

                return;
            }

            $totals = [];

            foreach ($parcels as $parcel) {
                $label = $parcel[$group];
                $totals[$label] ??= ['parcels' => 0, 'on_actual' => 0, 'charge_billed' => 0.0, 'courier_cost' => 0.0, 'cod_fee' => 0.0];
                $totals[$label]['parcels']++;
                $totals[$label]['on_actual'] += $parcel['source'] === 'statement' ? 1 : 0;
                $totals[$label]['charge_billed'] += $parcel['charge_billed'];
                $totals[$label]['courier_cost'] += $parcel['courier_cost'];
                $totals[$label]['cod_fee'] += $parcel['cod_fee'];
            }

            uasort($totals, fn (array $a, array $b) => $b['parcels'] <=> $a['parcels']);

            foreach ($totals as $label => $row) {
                yield [
                    'label' => (string) $label,
                    'parcels' => $row['parcels'],
                    'on_actual' => $row['on_actual'],
                    ...self::money($row['charge_billed'], $row['courier_cost'], $row['cod_fee']),
                ];
            }
        });
    }

    public function estimatedRows(ReportParameters $parameters): int
    {
        return $this->consignments($parameters)->count();
    }

    public function countRows(ReportParameters $parameters): int
    {
        return $this->groupBy($parameters) === 'parcel'
            ? $this->estimatedRows($parameters)
            : $this->rows($parameters)->count();
    }

    public function meta(ReportParameters $parameters): ReportMeta
    {
        return new ReportMeta(
            title: $this->name(),
            subtitle: $this->periodSubtitle($parameters, 'dispatched', 'Every parcel to date'),
            note: 'Basis: parcels whose challan is dated in the period. Delivery charge billed is the invoice line billed for the parcel\'s order, excluding VAT '
                .'(Delivery Charges Income 4300); an order billed once and sent in two parcels counts on its first parcel, and a parcel not invoiced yet has been billed nothing. '
                .'Courier cost is net delivery fee plus COD fee, taken from the courier\'s statement where there is one (posted first), else the courier\'s quote at booking, '
                .'else the ERP\'s estimate, as each row says. Extra charges, compensation and adjustments are not per parcel and are not here. '
                .'Band is the estimate\'s destination band. Brand is the order\'s brand.',
        );
    }

    public function chart(ReportParameters $parameters): ?array
    {
        $group = $this->groupBy($parameters);

        return [
            'type' => 'bar',
            'title' => 'Billed against courier cost',
            'label' => $group === 'parcel' ? 'courier' : 'label',
            'series' => [
                ['key' => 'charge_billed', 'label' => 'Charge billed'],
                ['key' => 'courier_cost', 'label' => 'Courier cost'],
            ],
            'limit' => 12,
        ];
    }

    /** @return array{charge_billed: float, courier_cost: float, cod_fee: float, margin: float, cod_share: float|null} */
    private static function money(float $charge, float $cost, float $cod): array
    {
        $charge = round($charge, 2);
        $cost = round($cost, 2);
        $cod = round($cod, 2);

        return [
            'charge_billed' => $charge,
            'courier_cost' => $cost,
            'cod_fee' => $cod,
            'margin' => round($charge - $cost, 2),
            'cod_share' => $cost == 0.0 ? null : round($cod / $cost * 100, 2),
        ];
    }

    private function groupBy(?ReportParameters $parameters): string
    {
        $group = $parameters?->string('group_by') ?? 'parcel';

        return array_key_exists($group, self::GROUPS) ? $group : 'parcel';
    }

    /** Parcels in range, with their challan, courier and brand. */
    private function consignments(ReportParameters $parameters): Builder
    {
        $business = $this->businessId();
        $courier = $parameters->string('courier');

        $query = DB::table('consignments')
            ->join('deliveries', 'deliveries.id', '=', 'consignments.delivery_id')
            ->join('money_accounts', 'money_accounts.id', '=', 'consignments.money_account_id')
            ->where('consignments.business_id', $business)
            ->where('deliveries.business_id', $business)
            ->when($parameters->from('dispatched'), fn (Builder $q, $from) => $q->whereDate('deliveries.delivered_at', '>=', $from->toDateString()))
            ->when($parameters->to('dispatched'), fn (Builder $q, $to) => $q->whereDate('deliveries.delivered_at', '<=', $to->toDateString()))
            ->when($courier !== null && ctype_digit($courier), fn (Builder $q) => $q->where('consignments.money_account_id', '=', (int) $courier));

        return OrderBrand::onConsignments($query, $this->brandFilter($parameters));
    }

    /**
     * Every parcel's figures, resolved in a handful of set queries (never one
     * per parcel).
     *
     * @return list<array<string, mixed>>
     */
    private function parcels(ReportParameters $parameters): array
    {
        $business = $this->businessId();
        $rows = $this->consignments($parameters)
            ->orderBy('deliveries.delivered_at')
            ->orderBy('consignments.id')
            ->get([
                'consignments.id', 'consignments.consignment_number', 'consignments.status', 'consignments.delivery_id',
                'consignments.provider_fee_quoted', 'consignments.provider_cod_fee_quoted',
                'deliveries.delivery_number', 'deliveries.delivered_at', 'money_accounts.name as courier',
            ]);

        if ($rows->isEmpty()) {
            return [];
        }

        $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $deliveryIds = $rows->pluck('delivery_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        $brands = OrderBrand::forDeliveries($deliveryIds);
        $names = $this->brandNames();
        $charges = $this->chargesByConsignment($deliveryIds, $business);
        $lines = $this->statementLines($ids, $business);
        $estimates = $this->estimates($ids, $deliveryIds, $business);

        $parcels = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $estimate = $estimates['consignment'][$id] ?? $estimates['delivery'][(int) $row->delivery_id] ?? null;
            $line = $lines[$id] ?? null;

            [$source, $cost, $cod] = match (true) {
                $line !== null => [$line['posted'] ? 'statement' : 'statement_draft', $line['net'] + $line['cod'], $line['cod']],
                $row->provider_fee_quoted !== null => ['quote', (float) $row->provider_fee_quoted + (float) $row->provider_cod_fee_quoted, (float) $row->provider_cod_fee_quoted],
                $estimate !== null && $estimate->total !== null => ['estimate', (float) $estimate->total, (float) $estimate->cod_fee],
                default => ['none', 0.0, 0.0],
            };

            $parcels[] = [
                'consignment' => (string) $row->consignment_number,
                'challan' => (string) $row->delivery_number,
                'dispatched' => substr((string) $row->delivered_at, 0, 10),
                'courier' => (string) $row->courier,
                'brand' => self::brandLabel($brands[(int) $row->delivery_id] ?? null, $names),
                'band' => $estimate?->to_band !== null ? (string) $estimate->to_band : 'Not estimated',
                'status' => ConsignmentStatus::tryFrom((string) $row->status)?->label() ?? (string) $row->status,
                'charge_billed' => $charges[$id] ?? 0.0,
                'courier_cost' => $cost,
                'cod_fee' => $cod,
                'source' => $source,
            ];
        }

        return $parcels;
    }

    /**
     * Delivery charges billed, ex-VAT, by the parcel they count on: each order's
     * charge goes to the FIRST parcel (lowest id) that carried any of its goods.
     *
     * @param  list<int>  $deliveryIds
     * @return array<int, float> consignment id => charge
     */
    private function chargesByConsignment(array $deliveryIds, int $business): array
    {
        $firstParcel = [];

        foreach (array_chunk($deliveryIds, 500) as $chunk) {
            $orders = DB::table('delivery_lines')
                ->join('sales_order_lines', 'sales_order_lines.id', '=', 'delivery_lines.sales_order_line_id')
                ->where('delivery_lines.business_id', $business)
                ->whereIn('delivery_lines.delivery_id', $chunk)
                ->distinct()
                ->pluck('sales_order_lines.sales_order_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($orders === []) {
                continue;
            }

            // The first parcel of each order across ALL its challans, not only
            // those in range, so a charge is never counted on two parcels.
            DB::table('consignments')
                ->join('delivery_lines', 'delivery_lines.delivery_id', '=', 'consignments.delivery_id')
                ->join('sales_order_lines', 'sales_order_lines.id', '=', 'delivery_lines.sales_order_line_id')
                ->where('consignments.business_id', $business)
                ->whereIn('sales_order_lines.sales_order_id', $orders)
                ->groupBy('sales_order_lines.sales_order_id')
                ->selectRaw('sales_order_lines.sales_order_id as order_id, MIN(consignments.id) as consignment_id')
                ->get()
                ->each(function ($row) use (&$firstParcel) {
                    $firstParcel[(int) $row->order_id] = (int) $row->consignment_id;
                });
        }

        if ($firstParcel === []) {
            return [];
        }

        $charges = [];

        foreach (array_chunk(array_keys($firstParcel), 500) as $chunk) {
            DB::table('sales_invoice_lines')
                ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_invoice_lines.sales_invoice_id')
                ->where('sales_invoice_lines.business_id', $business)
                ->where('sales_invoices.is_opening', false)
                ->whereIn('sales_invoice_lines.billed_delivery_for_order_id', $chunk)
                ->get([
                    'sales_invoice_lines.billed_delivery_for_order_id as order_id',
                    DB::raw('sales_invoice_lines.line_net - CASE WHEN sales_invoice_lines.prices_include_tax = 1 THEN sales_invoice_lines.tax_amount ELSE 0 END as amount'),
                ])
                ->each(function ($row) use (&$charges, $firstParcel) {
                    $parcel = $firstParcel[(int) $row->order_id] ?? null;

                    if ($parcel !== null) {
                        $charges[$parcel] = ($charges[$parcel] ?? 0.0) + (float) $row->amount;
                    }
                });
        }

        return $charges;
    }

    /**
     * The courier's statement line for each parcel: a posted statement's line
     * first, else the latest draft one.
     *
     * @param  list<int>  $ids
     * @return array<int, array{posted: bool, net: float, cod: float}>
     */
    private function statementLines(array $ids, int $business): array
    {
        $lines = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table('courier_statement_lines')
                ->join('courier_statements', 'courier_statements.id', '=', 'courier_statement_lines.courier_statement_id')
                ->where('courier_statement_lines.business_id', $business)
                ->where('courier_statements.business_id', $business)
                ->whereIn('courier_statement_lines.consignment_id', $chunk)
                ->orderBy('courier_statement_lines.id')
                ->get([
                    'courier_statement_lines.consignment_id', 'courier_statements.status',
                    'courier_statement_lines.net_fee', 'courier_statement_lines.delivery_fee',
                    'courier_statement_lines.discount', 'courier_statement_lines.cod_fee',
                ])
                ->each(function ($row) use (&$lines) {
                    $id = (int) $row->consignment_id;
                    $posted = $row->status === CourierStatement::POSTED;

                    // A posted line beats a draft one; among equals the latest wins.
                    if (isset($lines[$id]) && $lines[$id]['posted'] && ! $posted) {
                        return;
                    }

                    $lines[$id] = [
                        'posted' => $posted,
                        'net' => $row->net_fee !== null ? (float) $row->net_fee : (float) $row->delivery_fee - (float) $row->discount,
                        'cod' => (float) $row->cod_fee,
                    ];
                });
        }

        return $lines;
    }

    /**
     * The latest fee estimate per parcel, and per challan for an estimate made
     * before the parcel existed.
     *
     * @param  list<int>  $ids
     * @param  list<int>  $deliveryIds
     * @return array{consignment: array<int, object>, delivery: array<int, object>}
     */
    private function estimates(array $ids, array $deliveryIds, int $business): array
    {
        $found = ['consignment' => [], 'delivery' => []];
        $wanted = array_flip($ids);

        foreach (array_chunk($deliveryIds, 500) as $chunk) {
            DB::table('courier_fee_estimates')
                ->where('business_id', $business)
                ->whereIn('delivery_id', $chunk)
                ->orderBy('id')
                ->get(['id', 'consignment_id', 'delivery_id', 'to_band', 'delivery_fee', 'cod_fee', 'total'])
                ->each(function ($row) use (&$found, $wanted) {
                    if ($row->consignment_id !== null && isset($wanted[(int) $row->consignment_id])) {
                        $found['consignment'][(int) $row->consignment_id] = $row;
                    }

                    $found['delivery'][(int) $row->delivery_id] = $row;
                });
        }

        return $found;
    }
}
