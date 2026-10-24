<?php

namespace App\Modules\Sales\Support;

use App\Modules\Accounting\Support\Money;
use App\Modules\Sales\Models\CommissionAgreement;
use App\Modules\Sales\Models\CommissionAgreementTier;
use App\Modules\Sales\Models\CommissionSettlement;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ What a commission agreement has counted and earned (Joy's Q18, 25 Oct 2026).
 *
 * WHICH SALES COUNT, line by line on issued invoices dated inside the window
 * (and inside the agreement's own dates):
 *   - a product the agreement names (or in a category it names; no items =
 *     every product). A delivery charge or a service line has no product and
 *     never counts.
 *   - the person is credited for it ("Sold by / agent" on the invoice, or,
 *     when the invoice has none, on the order it was made from), when the
 *     agreement counts credited sales
 *   - or, for a customer's agreement that counts their own purchases, the
 *     customer bought it
 *   - not an opening invoice, a reversed one, a staff sale or an owner sale
 *     (none of them is a sale anybody earned)
 * LESS what customers returned off those lines, by the return's own date.
 *
 * VALUE is the line net after its share of the invoice discount, without VAT,
 * so a percent deal pays on what the customer really paid for the goods.
 *
 * ⚠ Operational (documents), not the ledger: the ledger does not know who
 * sold a line, the same reason SalesTargetProgress reads documents.
 */
final class CommissionTracker
{
    /** @return array{quantity: string, value: string} */
    public function measure(CommissionAgreement $agreement, Carbon $from, Carbon $through): array
    {
        $from = $from->copy()->max($agreement->valid_from);
        $through = $agreement->valid_to !== null ? $through->copy()->min($agreement->valid_to) : $through->copy();

        if ($from->gt($through)) {
            return ['quantity' => '0.0000', 'value' => '0.0000'];
        }

        $ratio = 'CASE WHEN sales_invoices.subtotal > 0 THEN (sales_invoices.subtotal - sales_invoices.invoice_discount) * 1.0 / sales_invoices.subtotal ELSE 1 END';

        $sold = $this->countedLines($agreement)
            ->whereBetween('sales_invoices.invoice_date', [$from->toDateString(), $through->toDateString()])
            ->selectRaw("COALESCE(SUM(sales_invoice_lines.quantity), 0) as qty, COALESCE(SUM(sales_invoice_lines.line_net * {$ratio}), 0) as val")
            ->first();

        $returned = $this->countedLines($agreement)
            ->join('sales_return_invoice_lines as returned', 'returned.sales_invoice_line_id', '=', 'sales_invoice_lines.id')
            ->join('sales_return_lines', 'sales_return_lines.id', '=', 'returned.sales_return_line_id')
            ->join('sales_returns', 'sales_returns.id', '=', 'sales_return_lines.sales_return_id')
            ->whereBetween('sales_returns.returned_at', [$from->toDateString(), $through->toDateString()])
            // A return only takes back a sale this agreement counted.
            ->where('sales_invoices.invoice_date', '>=', $agreement->valid_from->toDateString())
            ->when($agreement->valid_to !== null, fn (Builder $q) => $q->where('sales_invoices.invoice_date', '<=', $agreement->valid_to->toDateString()))
            // ⚠ A return's `net` already carries its share of the invoice
            // discount (RecordSalesReturn), so no ratio here.
            ->selectRaw('COALESCE(SUM(returned.quantity), 0) as qty, COALESCE(SUM(returned.net), 0) as val')
            ->first();

        $quantity = round((float) $sold->qty - (float) $returned->qty, 4);
        $value = round((float) $sold->val - (float) $returned->val, 2);

        return ['quantity' => number_format($quantity, 4, '.', ''), 'value' => number_format($value, 2, '.', '')];
    }

    /** Earned from the agreement's start through `$through`, never below zero. */
    public function earnedThrough(CommissionAgreement $agreement, Carbon $through): Money
    {
        $sold = $this->measure($agreement, $agreement->valid_from->copy(), $through);

        return $this->earnedFor($agreement, $sold['quantity'], $sold['value']);
    }

    public function earnedFor(CommissionAgreement $agreement, string $quantity, string $value): Money
    {
        $earned = match ($agreement->deal_type) {
            'per_unit' => Money::ofProduct($quantity, (string) $agreement->rate),
            'percent' => Money::ofProduct($value, number_format((float) $agreement->rate / 100, 6, '.', '')),
            default => $this->reward($agreement, $agreement->target_basis === 'units' ? (float) $quantity : (float) $value),
        };

        return $earned->minor < 0 ? Money::zero() : $earned;
    }

    /** What live settlements (posted or waiting) already took. */
    public function settled(CommissionAgreement $agreement, ?int $exceptId = null): Money
    {
        return Money::sum(
            CommissionSettlement::query()
                ->where('commission_agreement_id', $agreement->id)
                ->whereIn('status', [CommissionSettlement::PENDING, CommissionSettlement::POSTED])
                ->whereNull('reversed_at')
                ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
                ->pluck('amount')
                ->map(fn ($amount) => Money::ofTaka((string) $amount)),
        );
    }

    /** The last day a live settlement closed, or null. */
    public function lastSettledThrough(CommissionAgreement $agreement): ?Carbon
    {
        $last = CommissionSettlement::query()
            ->where('commission_agreement_id', $agreement->id)
            ->whereIn('status', [CommissionSettlement::PENDING, CommissionSettlement::POSTED])
            ->whereNull('reversed_at')
            ->max('through_date');

        return $last === null ? null : Carbon::parse($last)->startOfDay();
    }

    /**
     * The progress the screen shows: sold so far, earned so far, settled,
     * still to settle, and for a target deal the next step and how far to it.
     *
     * @return array<string, mixed>
     */
    public function progress(CommissionAgreement $agreement, ?Carbon $asOf = null): array
    {
        $through = $agreement->lastCountingDay($asOf ?? Carbon::today());
        $sold = $this->measure($agreement, $agreement->valid_from->copy(), $through);
        $earned = $this->earnedFor($agreement, $sold['quantity'], $sold['value']);
        $settled = $this->settled($agreement);
        $achieved = $agreement->target_basis === 'units' ? (float) $sold['quantity'] : (float) $sold['value'];

        $next = $agreement->deal_type === 'target'
            ? $agreement->tiers->first(fn (CommissionAgreementTier $tier) => (float) $tier->threshold > $achieved)
            : null;
        $top = $agreement->deal_type === 'target' ? $agreement->tiers->last() : null;

        return [
            'sold_quantity' => $sold['quantity'],
            'sold_value' => $sold['value'],
            'earned' => $earned->toTaka(),
            'settled' => $settled->toTaka(),
            'to_settle' => $earned->plus($settled->negated())->toTaka(),
            'target_percent' => $top !== null && (float) $top->threshold > 0 ? round(min($achieved / (float) $top->threshold, 9.99) * 100, 1) : null,
            'next_threshold' => $next?->threshold,
            'next_reward' => $next?->reward,
            'next_gap' => $next === null ? null : number_format((float) $next->threshold - $achieved, 2, '.', ''),
        ];
    }

    /** The highest step reached; none reached = nothing. */
    private function reward(CommissionAgreement $agreement, float $achieved): Money
    {
        $reached = $agreement->tiers->filter(fn (CommissionAgreementTier $tier) => (float) $tier->threshold <= $achieved + 0.00001);

        return $reached->isEmpty() ? Money::zero() : Money::ofTaka((string) $reached->max(fn (CommissionAgreementTier $tier) => (float) $tier->reward));
    }

    /** The invoice lines this agreement could count, before the date window. */
    private function countedLines(CommissionAgreement $agreement): Builder
    {
        $agreement->loadMissing('items');
        $productIds = $agreement->items->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->values()->all();
        $categoryIds = $agreement->items->pluck('category_id')->filter()->map(fn ($id) => (int) $id)->values()->all();
        $partyType = $agreement->party_type;
        $partyId = $agreement->partyId();

        return DB::table('sales_invoice_lines')
            ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_invoice_lines.sales_invoice_id')
            ->leftJoin('delivery_lines', 'delivery_lines.id', '=', 'sales_invoice_lines.delivery_line_id')
            ->leftJoin('sales_order_lines', 'sales_order_lines.id', '=', 'delivery_lines.sales_order_line_id')
            ->leftJoin('sales_orders', 'sales_orders.id', '=', 'sales_order_lines.sales_order_id')
            ->leftJoin('products', 'products.id', '=', 'sales_invoice_lines.product_id')
            ->where('sales_invoices.business_id', $agreement->business_id)
            ->where('sales_invoices.is_opening', false)
            ->whereNull('sales_invoices.reversed_at')
            ->where('sales_invoices.is_staff_sale', false)
            ->where('sales_invoices.is_owner_sale', false)
            ->whereNotNull('sales_invoice_lines.product_id')
            ->whereNull('sales_invoice_lines.billed_delivery_for_order_id')
            ->when($productIds !== [] || $categoryIds !== [], fn (Builder $q) => $q->where(function (Builder $scope) use ($productIds, $categoryIds) {
                $scope->whereIn('sales_invoice_lines.product_id', $productIds ?: [0])
                    ->orWhereIn('products.category_id', $categoryIds ?: [0]);
            }))
            ->where(function (Builder $who) use ($agreement, $partyType, $partyId) {
                if ($agreement->counts_credited_sales) {
                    // The invoice's own agent wins; an invoice with none takes
                    // the agent of the order it was made from.
                    $who->orWhere(fn (Builder $q) => $q->where('sales_invoices.agent_type', $partyType)->where('sales_invoices.agent_id', $partyId))
                        ->orWhere(fn (Builder $q) => $q->whereNull('sales_invoices.agent_type')
                            ->where('sales_orders.agent_type', $partyType)->where('sales_orders.agent_id', $partyId));
                }

                if ($agreement->counts_own_purchases && $partyType === 'customer') {
                    $who->orWhere('sales_invoices.customer_id', $partyId);
                }

                // Neither box ticked: nothing counts, rather than everything.
                $who->orWhereRaw('1 = 0');
            });
    }
}
