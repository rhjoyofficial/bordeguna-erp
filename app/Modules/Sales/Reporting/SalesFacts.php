<?php

namespace App\Modules\Sales\Reporting;

use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Support\OrderBrand;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * The invoiced-sales facts the sales and margin reports group, one row per
 * invoice line or return line, in the exact amounts the ledger was posted with.
 *
 * ⚠⚠ EVERY FIGURE IS THE JOURNAL'S OWN ARITHMETIC (PostSalesJournals), read
 * from the documents so it can be split by brand, channel and product:
 *
 *   gross     = line_net less the VAT inside it          = what 4101 was credited
 *   discount  = the line's share of the invoice discount,
 *               less the VAT inside that share           = what 4210 was debited
 *   returned  = credit_gross - tax_inside_gross,
 *               less the discount clawed back            = 4200 debit less the 4210 credit
 *   net       = gross - discount - returned              = 4101 + 4200 + 4210 movement
 *   cogs      = cost_released, less return cogs_relief    = 5100 movement (CostOfGoodsSold)
 *
 * so the reports' totals tie to the ledger rather than to a second idea of
 * what was sold. What is deliberately NOT here: the invoice total (it carries
 * VAT and the delivery charge), VAT itself, GDNI (cost of goods sent and not yet
 * billed), and advances (money received, not revenue).
 *
 * ⚠ The discount share. While VAT is on, ApplyInvoiceTax stores each goods
 * line's share; while it is off nothing is stored, and the share is taken pro
 * rata on line_net here, the rule ApplyInvoiceTax itself uses. The shares of
 * one invoice add up to its discount, so the total ties either way.
 *
 * ⚠ Brand and channel are the ORDER's, reached through the challan line
 * (OrderBrand's path). A line with no order behind it (a counter sale, or a
 * challan raised without an order) is channel "counter"; a line whose order
 * has no brand is brand null ("No brand").
 *
 * ⚠ Raw tables carry no BusinessScope: every query here filters business_id
 * by hand.
 */
final class SalesFacts
{
    public const COUNTER = 'counter';

    /**
     * A filter value naming ONE website store: `store:<id>`. Only a filter, a
     * row still groups under "Website" whichever shop it came from.
     */
    public const STORE_PREFIX = 'store:';

    /**
     * Channel values a filter may name, with their labels.
     *
     * ⭐ IP21 (25 Oct 2026): every SalesChannel case, not only website and
     * internal. B18 made phone, WhatsApp, Facebook, walk-in and other real
     * channels, and the filter still offered three, so Joy could not ask for
     * "WhatsApp orders this month". Counter is not a SalesChannel: it is a sale
     * with no order behind it.
     *
     * @return array<string, string>
     */
    public static function channels(): array
    {
        $channels = [];

        foreach (SalesChannel::cases() as $case) {
            $channels[$case->value] = $case === SalesChannel::Internal ? 'Internal (typed orders)' : $case->label();
        }

        $channels[self::COUNTER] = 'Counter (no order)';

        return $channels;
    }

    /**
     * The filter's options: every channel, and each website store of this
     * business on its own ("Website: LaLaDia"), right after "Website".
     *
     * @return array<string, string>
     */
    public static function channelOptions(int $businessId): array
    {
        $stores = DB::table('stores')
            ->where('business_id', $businessId)
            ->orderBy('name')
            ->pluck('name', 'id');

        $options = [];

        foreach (self::channels() as $value => $label) {
            $options[$value] = $label;

            if ($value === SalesChannel::Website->value) {
                foreach ($stores as $id => $name) {
                    $options[self::STORE_PREFIX.$id] = 'Website: '.$name;
                }
            }
        }

        return $options;
    }

    /**
     * @param  int|string|null  $brand  an OrderBrand filter: null, NONE or a brand id
     */
    public function __construct(
        private readonly int $businessId,
        private readonly ?Carbon $from,
        private readonly ?Carbon $to,
        private readonly int|string|null $brand = null,
        private readonly ?string $channel = null,
    ) {}

    public static function channelLabel(string $channel): string
    {
        return self::channels()[$channel] ?? ucfirst($channel);
    }

    /**
     * Goods lines of trading invoices dated in the period.
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function invoiceLines(): LazyCollection
    {
        $shares = DB::table('sales_invoice_lines')
            ->where('business_id', $this->businessId)
            ->whereNull('billed_delivery_for_order_id')
            ->groupBy('sales_invoice_id')
            ->selectRaw('sales_invoice_id')
            ->selectRaw('SUM(CASE WHEN line_net > 0 THEN line_net ELSE 0 END) as goods_base')
            ->selectRaw('SUM(ABS(discount_share)) as stored_shares');

        $query = DB::table('sales_invoice_lines as l')
            ->join('sales_invoices as inv', 'inv.id', '=', 'l.sales_invoice_id')
            ->joinSub($shares, 'ib', 'ib.sales_invoice_id', '=', 'l.sales_invoice_id')
            ->join('customers as c', 'c.id', '=', 'inv.customer_id')
            ->leftJoin('delivery_lines as dl', 'dl.id', '=', 'l.delivery_line_id')
            ->leftJoin('sales_order_lines as sol', 'sol.id', '=', 'dl.sales_order_line_id')
            ->leftJoin('sales_orders as so', 'so.id', '=', 'sol.sales_order_id')
            ->where('l.business_id', $this->businessId)
            ->where('inv.business_id', $this->businessId)
            ->where('inv.is_opening', false)
            ->whereNull('inv.reversed_at')
            // ⭐ Phase 2 (17 Oct 2026): staff purchases at cost are not retail
            // sales; they stay in the ledger and the audit tab, not the KPIs.
            ->where('inv.is_staff_sale', false)
            // ⭐ D-B (18 Oct 2026): owner sales at cost are not retail either.
            ->where('inv.is_owner_sale', false)
            ->whereNull('l.billed_delivery_for_order_id');

        $this->dated($query, 'inv.invoice_date');
        $this->narrow($query);

        $query->select(['l.id', 'l.product_id', 'l.sales_invoice_id', 'so.brand_id', 'c.type as customer_type', 'l.quantity', 'l.cost_released'])
            ->selectRaw($this->channelSql().' as channel')
            ->selectRaw('l.line_net - CASE WHEN l.prices_include_tax = 1 THEN l.tax_amount + l.tax_on_discount ELSE 0 END as gross')
            ->selectRaw('(CASE WHEN ib.stored_shares <> 0 THEN l.discount_share WHEN ib.goods_base > 0 AND l.line_net > 0 THEN inv.invoice_discount * l.line_net / ib.goods_base ELSE 0 END) - l.tax_on_discount as discount')
            ->orderBy('l.id');

        return LazyCollection::make(function () use ($query) {
            foreach ($query->cursor() as $row) {
                $gross = (float) $row->gross;
                $discount = (float) $row->discount;

                yield [
                    'kind' => 'invoice',
                    'product_id' => $row->product_id === null ? null : (int) $row->product_id,
                    'brand_id' => $row->brand_id === null ? null : (int) $row->brand_id,
                    'channel' => (string) $row->channel,
                    'customer_type' => (string) $row->customer_type,
                    'invoice_id' => (int) $row->sales_invoice_id,
                    'quantity' => (float) $row->quantity,
                    'gross' => $gross,
                    'discount' => $discount,
                    'returned' => 0.0,
                    'net' => $gross - $discount,
                    'cogs' => (float) $row->cost_released,
                    'zero_cost' => (float) $row->quantity > 0 && round((float) $row->cost_released, 4) === 0.0,
                ];
            }
        });
    }

    /**
     * Return lines dated in the period: revenue and cost given back.
     *
     * ⚠ Quantity is the INVOICED part returned (sales_return_invoice_lines);
     * goods returned before they were billed were never in the units sold.
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function returnLines(): LazyCollection
    {
        $invoicedBack = DB::table('sales_return_invoice_lines')
            ->where('business_id', $this->businessId)
            ->groupBy('sales_return_line_id')
            ->selectRaw('sales_return_line_id, SUM(quantity) as quantity');

        $query = DB::table('sales_return_lines as r')
            ->join('sales_returns as sr', 'sr.id', '=', 'r.sales_return_id')
            ->join('customers as c', 'c.id', '=', 'sr.customer_id')
            ->join('delivery_lines as dl', 'dl.id', '=', 'r.delivery_line_id')
            ->leftJoinSub($invoicedBack, 'ib', 'ib.sales_return_line_id', '=', 'r.id')
            ->leftJoin('sales_order_lines as sol', 'sol.id', '=', 'dl.sales_order_line_id')
            ->leftJoin('sales_orders as so', 'so.id', '=', 'sol.sales_order_id')
            ->where('r.business_id', $this->businessId)
            ->where('sr.business_id', $this->businessId);

        $this->dated($query, 'sr.returned_at');
        $this->narrow($query);

        $query->select(['r.id', 'dl.product_id', 'so.brand_id', 'c.type as customer_type', 'r.cogs_relief'])
            ->selectRaw($this->channelSql().' as channel')
            ->selectRaw('COALESCE(ib.quantity, 0) as quantity')
            ->selectRaw('r.credit_gross - r.tax_inside_gross as returned')
            ->selectRaw('r.credit_discount - r.tax_on_discount as clawback')
            ->orderBy('r.id');

        return LazyCollection::make(function () use ($query) {
            foreach ($query->cursor() as $row) {
                // The discount clawed back reduces what the return takes off
                // revenue, exactly as 4210 is credited on the return journal.
                $returned = (float) $row->returned - (float) $row->clawback;

                yield [
                    'kind' => 'return',
                    'product_id' => $row->product_id === null ? null : (int) $row->product_id,
                    'brand_id' => $row->brand_id === null ? null : (int) $row->brand_id,
                    'channel' => (string) $row->channel,
                    'customer_type' => (string) $row->customer_type,
                    'invoice_id' => null,
                    'quantity' => -1 * (float) $row->quantity,
                    'gross' => 0.0,
                    'discount' => 0.0,
                    'returned' => $returned,
                    'net' => -1 * $returned,
                    'cogs' => -1 * (float) $row->cogs_relief,
                    'zero_cost' => false,
                ];
            }
        });
    }

    /**
     * Delivery charges billed on trading invoices in the period, ex-VAT: what
     * 4300 was credited. Their own figure, never part of goods revenue.
     *
     * @return LazyCollection<int, array{brand_id: int|null, channel: string, customer_type: string, amount: float}>
     */
    public function deliveryCharges(): LazyCollection
    {
        $query = DB::table('sales_invoice_lines as l')
            ->join('sales_invoices as inv', 'inv.id', '=', 'l.sales_invoice_id')
            ->join('customers as c', 'c.id', '=', 'inv.customer_id')
            ->join('sales_orders as so', 'so.id', '=', 'l.billed_delivery_for_order_id')
            ->where('l.business_id', $this->businessId)
            ->where('inv.business_id', $this->businessId)
            ->where('inv.is_opening', false)
            ->whereNull('inv.reversed_at')
            ->whereNotNull('l.billed_delivery_for_order_id');

        $this->dated($query, 'inv.invoice_date');
        $this->narrow($query);

        $query->select(['l.id', 'so.brand_id', 'c.type as customer_type'])
            ->selectRaw($this->channelSql().' as channel')
            ->selectRaw('l.line_net - CASE WHEN l.prices_include_tax = 1 THEN l.tax_amount ELSE 0 END as amount')
            ->orderBy('l.id');

        return LazyCollection::make(function () use ($query) {
            foreach ($query->cursor() as $row) {
                yield [
                    'brand_id' => $row->brand_id === null ? null : (int) $row->brand_id,
                    'channel' => (string) $row->channel,
                    'customer_type' => (string) $row->customer_type,
                    'amount' => (float) $row->amount,
                ];
            }
        });
    }

    /** "counter" when no order is joined, else the order's channel (internal when unset). */
    private function channelSql(): string
    {
        return "CASE WHEN so.id IS NULL THEN '".self::COUNTER."' ELSE COALESCE(so.channel, 'internal') END";
    }

    private function dated(Builder $query, string $column): void
    {
        if ($this->from !== null) {
            $query->whereDate($column, '>=', $this->from->toDateString());
        }

        if ($this->to !== null) {
            $query->whereDate($column, '<=', $this->to->toDateString());
        }
    }

    /** The brand and channel filters, on the joined order (`so`). */
    private function narrow(Builder $query): void
    {
        if ($this->brand !== null) {
            OrderBrand::onOrders($query, $this->brand, 'so.brand_id');
        }

        match ($this->channel) {
            null, '' => null,
            self::COUNTER => $query->whereNull('so.id'),
            'internal' => $query->whereNotNull('so.id')->where(fn (Builder $q) => $q->whereNull('so.channel')->orWhere('so.channel', 'internal')),
            // One shop: its website orders only. The store id is checked
            // against this business by channelOptions before it gets here.
            default => str_starts_with($this->channel, self::STORE_PREFIX)
                ? $query->whereNotNull('so.id')->where('so.channel', SalesChannel::Website->value)
                    ->where('so.store_id', (int) substr($this->channel, strlen(self::STORE_PREFIX)))
                : $query->whereNotNull('so.id')->where('so.channel', $this->channel),
        };
    }
}
