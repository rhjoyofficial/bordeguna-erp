<?php

namespace App\Modules\Purchasing\Support;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\MoneyAccountBalances;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Inventory\Support\PurchaseUnitConversion;
use App\Modules\Purchasing\Actions\ReturnPurchasedGoods;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\SupplierBill;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ⭐ Initial plan B9 (R66, R68, R69; Joy's Q21, 25 Oct 2026): what the
 * "Return" popup on a goods receipt or a supplier bill needs, read once on the
 * server: per order line, how much can still go back from THAT delivery's
 * batches (never more than a batch still holds), the order price the supplier
 * credits, the landed cost per unit that will be written off, how much of the
 * line is still unbilled (goes back with no credit), and what the open bills
 * of the order still owe (the credit reduces those first).
 *
 * ⚠ A preview: ReturnPurchasedGoods and the posting work the real figures out
 * again inside the transaction.
 */
final class PurchaseReturnForm
{
    /**
     * @return array<string, mixed>|null null when nothing can go back or the reader may not return goods
     */
    public static function forReceipt(GoodsReceipt $receipt, User $user): ?array
    {
        if ($receipt->isReversed() || $receipt->purchaseOrder === null) {
            return null;
        }

        return self::build($receipt->purchaseOrder, $receipt->lines()->get(), null, $user, $receipt->received_at);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function forBill(SupplierBill $bill, User $user): ?array
    {
        if ($bill->isReversed()) {
            return null;
        }

        $receiptLineIds = $bill->lines()->whereNotNull('goods_receipt_line_id')->pluck('goods_receipt_line_id');
        $order = $bill->purchaseOrder ?? PurchaseOrder::query()->whereHas('lines', fn ($q) => $q->whereIn('id', $bill->lines()->whereNotNull('purchase_order_line_id')->pluck('purchase_order_line_id')))->first();

        if ($order === null) {
            return null;
        }

        $receiptLines = $receiptLineIds->isNotEmpty()
            ? GoodsReceiptLine::query()->whereIn('id', $receiptLineIds)->get()
            : GoodsReceiptLine::query()->whereIn('purchase_order_line_id', $order->lines()->pluck('id'))->get();

        // A bill naming deliveries of more than one order returns per order:
        // the popup works on this order's lines only.
        $receiptLines = $receiptLines->filter(fn (GoodsReceiptLine $line) => $order->lines->contains('id', $line->purchase_order_line_id))->values();

        $earliest = GoodsReceipt::query()->whereIn('id', $receiptLines->pluck('goods_receipt_id'))->min('received_at');

        return self::build($order, $receiptLines, $bill, $user, $earliest === null ? null : Carbon::parse($earliest));
    }

    /**
     * @param  Collection<int, GoodsReceiptLine>  $receiptLines
     * @return array<string, mixed>|null
     */
    private static function build(PurchaseOrder $order, Collection $receiptLines, ?SupplierBill $bill, User $user, ?Carbon $earliest): ?array
    {
        if (! $user->can('purchasing.po.approve')) {
            return null;
        }

        $receiptLines->loadMissing([
            'stockMovement.batch',
            'purchaseOrderLine.product.unitOfMeasure',
            'purchaseOrderLine.product.purchaseUnit',
        ]);

        $scale = fn (string $value) => (int) round(((float) $value) * 10000);
        $unscale = fn (int $value) => number_format($value / 10000, 4, '.', '');

        $lines = $receiptLines
            ->filter(fn (GoodsReceiptLine $line) => $line->stockMovement?->batch !== null && $line->purchaseOrderLine !== null)
            ->groupBy('purchase_order_line_id')
            ->map(function (Collection $group) use ($scale, $unscale) {
                $poLine = $group->first()->purchaseOrderLine;
                $product = $poLine->product;
                $available = 0;
                $stockValuePerUnit = null;
                $batches = [];

                foreach ($group as $receiptLine) {
                    $batch = $receiptLine->stockMovement->batch;
                    $factor = $batch->conversion_factor !== null ? (string) $batch->conversion_factor : null;
                    $onHand = (string) $batch->current_quantity;
                    $inPurchaseUnit = $factor === null ? $onHand : PurchaseUnitConversion::toPurchaseQuantity($onHand, $factor);
                    $available += max(0, $scale($inPurchaseUnit));
                    // What one purchase unit of this batch is carried at now.
                    $stockValuePerUnit ??= (float) $batch->unit_cost * ($factor === null ? 1.0 : (float) $factor);
                    $batches[] = $batch->batch_number;
                }

                $returnable = $scale($poLine->quantityReturnable());
                $canReturn = min($available, $returnable);
                $price = (float) $poLine->valueOf('1')->toTaka();
                $unbilled = max(0, $scale((string) $poLine->quantity_received) - $scale((string) $poLine->quantity_returned) - $scale((string) $poLine->quantity_invoiced));

                return [
                    'purchase_order_line_id' => $poLine->id,
                    'product' => $product?->name,
                    'unit' => $product?->purchaseUnit?->abbreviation ?? $product?->unitOfMeasure?->abbreviation,
                    'batches' => array_values(array_unique($batches)),
                    'can_return' => $unscale($canReturn),
                    'price' => number_format($price, 4, '.', ''),
                    // The landed cost one unit carries above the order price: written off.
                    'landed_per_unit' => number_format(max(0, ($stockValuePerUnit ?? $price) - $price), 4, '.', ''),
                    'unbilled' => $unscale($unbilled),
                    'used_up' => $canReturn <= 0,
                ];
            })
            ->values();

        if ($lines->isEmpty()) {
            return null;
        }

        $owed = ReturnPurchasedGoods::openBillsOf($order, $bill)
            ->reduce(fn (Money $sum, SupplierBill $open) => $sum->plus($open->outstanding()), Money::zero());

        $canRefund = $user->can('purchasing.advance.record');
        $accounts = $canRefund ? MoneyAccount::query()->handled()->withLedger()->where('is_active', true)->orderBy('name')->get(['id', 'business_id', 'name', 'type', 'account_id']) : collect();
        $balances = $accounts->isEmpty() ? [] : MoneyAccountBalances::for($accounts);

        return [
            'order_id' => $order->id,
            'po_number' => $order->po_number,
            'supplier' => $order->supplier?->name,
            'today' => Carbon::today()->toDateString(),
            'earliest' => $earliest?->toDateString(),
            'lines' => $lines,
            // What the order's open bills still owe: the credit reduces these first.
            'bills_owe' => $owed->toTaka(),
            'can_credit' => $user->can('purchasing.credit_note.record'),
            'can_refund' => $canRefund,
            'money_accounts' => $accounts->map(fn (MoneyAccount $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'balance' => $balances[$account->id] ?? null,
            ])->values(),
        ];
    }
}
