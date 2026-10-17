<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Sales\Actions\ConfirmMarketPrice;
use App\Modules\Sales\Models\ProductPriceHistory;
use App\Modules\Sales\Support\ProductPricing;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Today's price of a market product, set by hand or confirmed on a receipt
 * line after the fact (Phase 2 / 1B, 17 Oct 2026).
 *
 * ⚠ Both doors lead to ConfirmMarketPrice, the one chokepoint: the cost
 * floor, the margin gate and the history row are its, not this screen's.
 */
class MarketPriceController extends Controller
{
    /**
     * The proposal for one product (from its latest received batch) or for
     * one receipt line (from the batch that line created). JSON, read-only.
     */
    public function proposal(Request $request): JsonResponse
    {
        $this->authorizeEitherDoor($request);
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'product_id' => ['nullable', 'integer', 'required_without:receipt_line_id', Rule::exists('products', 'id')->where('business_id', $businessId)],
            'receipt_line_id' => ['nullable', 'integer', 'required_without:product_id', Rule::exists('goods_receipt_lines', 'id')->where('business_id', $businessId)],
        ]);

        if (! empty($data['receipt_line_id'])) {
            $line = GoodsReceiptLine::with('purchaseOrderLine.product', 'stockMovement.batch')->findOrFail($data['receipt_line_id']);
            $product = $line->purchaseOrderLine->product;
            $batch = $line->stockMovement?->batch;
            $basis = $batch === null ? null : ['cost' => (string) $batch->unit_cost, 'batch_id' => $batch->id];
        } else {
            $product = Product::findOrFail($data['product_id']);
            $basis = ProductPricing::latestCostBasis($product);
        }

        if (! $product->isMarketPriced()) {
            return response()->json(['refusal' => "{$product->name} is priced by its MRP and the price list, not from the market."], 422);
        }

        if ($basis === null) {
            return response()->json([
                'refusal' => "{$product->name} has never been received, so there is no cost to price it from. Receive a lot first; the receipt will propose today's price.",
            ], 422);
        }

        return response()->json(['product_id' => $product->id, 'batch_id' => $basis['batch_id']] + ProductPricing::proposal($product, $basis['cost']));
    }

    /**
     * Manual reprice from Sales setup > Selling prices or the product page:
     * same cost floor, margin rule, reason and permission as a receipt, and a
     * history row. The cost basis is the latest received batch.
     */
    public function store(Request $request, ConfirmMarketPrice $action): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('business_id', $businessId)],
            'price' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [], ['price' => "today's price"]);

        $product = Product::findOrFail($data['product_id']);
        $basis = ProductPricing::latestCostBasis($product);

        if ($basis === null) {
            throw ValidationException::withMessages([
                'product_id' => "{$product->name} has never been received, so there is no cost to price it from. Receive a lot first.",
            ]);
        }

        $batch = StockBatch::query()->withoutGlobalScopes()->find($basis['batch_id']);

        try {
            $action->handle(
                product: $product,
                price: (string) $data['price'],
                cost: $basis['cost'],
                by: $request->user(),
                batch: $batch,
                reason: $data['reason'] ?? null,
                source: ProductPriceHistory::SOURCE_MANUAL,
            );
        } catch (InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages(['price' => $e->getMessage()]);
        }

        return back()->with('success', "Today's price of {$product->name} is Tk ".ProductPricing::plain(bcadd((string) $data['price'], '0', 4)).'.');
    }

    /**
     * Confirm the price a receipt proposed but nobody confirmed at the time.
     * The receiver's permission or the price-setter's; the cost is THAT
     * line's batch cost, never the latest one.
     */
    public function confirmReceiptLine(Request $request, GoodsReceiptLine $receiptLine, ConfirmMarketPrice $action): RedirectResponse
    {
        $this->authorizeEitherDoor($request);

        $data = $request->validate([
            'price' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [], ['price' => "today's price"]);

        $receiptLine->load('purchaseOrderLine.product', 'stockMovement.batch');
        $product = $receiptLine->purchaseOrderLine->product;
        $batch = $receiptLine->stockMovement?->batch;

        if ($batch === null || ! $product->isMarketPriced()) {
            throw ValidationException::withMessages(['price' => 'This receipt line has no batch to price from.']);
        }

        if ($receiptLine->market_price_status === ProductPricing::STATUS_CONFIRMED) {
            throw ValidationException::withMessages(['price' => "This line's price was already confirmed. Set a new price from Sales setup > Selling prices."]);
        }

        try {
            $action->handle(
                product: $product,
                price: (string) $data['price'],
                cost: (string) $batch->unit_cost,
                by: $request->user(),
                batch: $batch,
                receiptLine: $receiptLine,
                reason: $data['reason'] ?? null,
            );
        } catch (InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages(['price' => $e->getMessage()]);
        }

        return back()->with('success', "Today's price of {$product->name} is Tk ".ProductPricing::plain(bcadd((string) $data['price'], '0', 4)).'.');
    }

    /**
     * The receiver (purchasing.receipt.record) or the price-setter
     * (sales.prices.manage). Going UNDER the margin still needs the latter,
     * checked in the action; this only opens the door.
     */
    private function authorizeEitherDoor(Request $request): void
    {
        $user = $request->user();

        abort_unless($user->can('purchasing.receipt.record') || $user->can('sales.prices.manage'), 403);
    }
}
