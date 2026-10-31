<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Actions\RejectApprovable;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\ApproveInventoryAdjustment;
use App\Modules\Inventory\Actions\CreateInventoryAdjustment;
use App\Modules\Inventory\Actions\DispatchStockTransfer;
use App\Modules\Inventory\Actions\MoveStockBackToSale;
use App\Modules\Inventory\Actions\ReceiveStockTransfer;
use App\Modules\Inventory\Corrections\StockTransferActionPolicy;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\InventoryAdjustmentType;
use App\Modules\Inventory\Enums\MaterialKind;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\InventorySetting;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\AdjustmentCause;
use App\Modules\Inventory\Support\AvailableToSell;
use App\Support\PerPage;
use App\Support\Traceability\BatchSuppliers;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

class StockController extends Controller
{
    /** The three tabs of the stock page. */
    public const KINDS = ['finished', 'raw', 'packaging'];

    /** 1 finished good, 2 raw material, 3 packaging; the order the page lists them in. */
    private const KIND_RANK_SQL = "case when kp.type = 'finished_good' then 1 when kp.material_kind = 'packaging' then 3 else 2 end";

    /** Narrow a products query to one tab. */
    public static function ofKind($query, string $kind)
    {
        return match ($kind) {
            'finished' => $query->where('type', ProductType::FinishedGood),
            'packaging' => $query->where('type', ProductType::RawMaterial)->where('material_kind', MaterialKind::Packaging),
            default => $query->where('type', ProductType::RawMaterial)->where(fn ($q) => $q->whereNull('material_kind')->orWhere('material_kind', '!=', MaterialKind::Packaging)),
        };
    }

    /**
     * How many products have stock on hand under each tab, for the chosen
     * warehouse (or all), whatever the other filters say.
     *
     * @return array{finished:int, raw:int, packaging:int}
     */
    private function kindCounts(string $warehouseId): array
    {
        $onHand = StockBatch::query()
            ->where('status', StockBatchStatus::Active)
            ->when($warehouseId, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->select('product_id');

        $counts = [];

        foreach (self::KINDS as $kind) {
            $counts[$kind] = Product::query()->whereIn('id', $onHand)->where(fn ($q) => self::ofKind($q, $kind))->count();
        }

        return $counts;
    }

    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->toString(),
            'warehouse_id' => $request->string('warehouse_id')->toString(),
            // ⭐ Redesign (15 Oct 2026): the two chips beside the warehouses.
            'low' => $request->boolean('low') ? '1' : '',
            'expired' => $request->boolean('expired') ? '1' : '',
            // ⭐ 31 Oct 2026: finished goods, raw materials and packaging each
            // on their own tab, so a jar or a label is never read as a product.
            'kind' => in_array($request->string('kind')->toString(), self::KINDS, true) ? $request->string('kind')->toString() : '',
        ];

        $stockByProductWarehouse = StockBatch::query()
            ->where('status', StockBatchStatus::Active)
            ->when($filters['search'], fn ($query, $search) => $query->whereHas(
                'product',
                fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")
            ))
            ->when($filters['warehouse_id'], fn ($query, $warehouseId) => $query->where('warehouse_id', $warehouseId))
            ->when($filters['kind'], fn ($query, $kind) => $query->whereHas('product', fn ($q) => self::ofKind($q, $kind)))
            // ⚠ Low is the product's TOTAL against its threshold (the Low stock
            // screen's rule), not this warehouse's share of it.
            ->when($filters['low'], fn ($query) => $query->whereIn('product_id', $this->lowStockProductIds()))
            ->when($filters['expired'], fn ($query) => $query->havingRaw('SUM(CASE WHEN expiry_date IS NOT NULL AND expiry_date < ? THEN current_quantity ELSE 0 END) > 0', [now()->toDateString()]))
            ->selectRaw('product_id, warehouse_id, SUM(current_quantity) as quantity')
            // ⚠ 14 Oct 2026 (audit #14): how much of that is past its date. Still
            // on hand (and still valued) until written off, but no longer
            // sellable, so the list says so beside the figure. The date rule is
            // StockBatch::expired()'s, written as a sum here.
            ->selectRaw('SUM(CASE WHEN expiry_date IS NOT NULL AND expiry_date < ? THEN current_quantity ELSE 0 END) as expired_quantity', [now()->toDateString()])
            // ⭐ Traceability M2 (17 Oct 2026): how much of it is ON HOLD. Still
            // on hand and valued, but nothing will pick it until released.
            ->selectRaw('SUM(CASE WHEN held_at IS NOT NULL THEN current_quantity ELSE 0 END) as held_quantity')
            ->groupBy('product_id', 'warehouse_id')
            // Finished goods first, then raw materials, then packaging, each by
            // name. A correlated lookup, so it stays valid under GROUP BY.
            ->orderByRaw('(select '.self::KIND_RANK_SQL.' from products kp where kp.id = stock_batches.product_id)')
            ->orderByRaw('(select kp.name from products kp where kp.id = stock_batches.product_id)')
            ->orderBy('warehouse_id')
            // ⚠ The unit rides along: "12" of rice means nothing until it says
            // kg or bags, and the list used to say neither.
            ->with(['product:id,name,sku,type,material_kind,low_stock_threshold,unit_of_measure_id', 'product.unitOfMeasure:id,abbreviation', 'warehouse:id,name,not_for_sale'])
            ->paginate(PerPage::from())
            ->withQueryString();

        // ⭐ B17 (25 Oct 2026, Q4 = A): beside on hand, what confirmed orders
        // and approved requests hold, and what is left to sell.
        $grid = AvailableToSell::grid($stockByProductWarehouse->getCollection()->pluck('product_id')->unique()->values()->all());
        $stockByProductWarehouse->getCollection()->each(function ($row) use ($grid) {
            $cell = $grid[(int) $row->warehouse_id][(int) $row->product_id] ?? null;
            $row->setAttribute('reserved', $cell['reserved'] ?? '0');
            $row->setAttribute('available', $cell['available'] ?? '0');
        });

        $user = $request->user();
        $warehouses = Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name', 'is_default', 'not_for_sale']);

        return Inertia::render('inventory/stock/index', [
            'stock' => $stockByProductWarehouse,
            'kind_counts' => $this->kindCounts($filters['warehouse_id']),
            'products' => Product::stocked()->where('is_active', true)
                ->with('unitOfMeasure:id,abbreviation')
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'unit_of_measure_id'])
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'unit' => $product->unitOfMeasure?->abbreviation,
                ]),
            // ⭐ B7: a Not for sale warehouse is marked, so its rows offer Move
            // back to stock and its write-offs default to "Damaged returns".
            'warehouses' => $warehouses->map(fn (Warehouse $warehouse) => ['id' => $warehouse->id, 'name' => $warehouse->name, 'not_for_sale' => $warehouse->not_for_sale])->values(),
            // Preselected in both dialogs: most corrections and moves start in
            // the default warehouse, and a one-warehouse business has no choice.
            'default_warehouse_id' => $warehouses->firstWhere('is_default', true)?->id
                ?? ($warehouses->count() === 1 ? $warehouses->first()->id : null),
            'on_hand' => $this->onHand(),
            'last_costs' => $this->lastCosts(),
            // ⚠ Annotated with the type each reason means, so the dialog asks
            // the right follow-up: "how much was lost" for damage, "more or less
            // than the system shows" for a count correction. See AdjustmentCause.
            'adjustment_reasons' => Reason::active()->forContext('inventory.stock_adjustment')->orderBy('name')->get()
                ->map(fn (Reason $reason) => AdjustmentCause::describe($reason))->values(),
            'transfer_reasons' => Reason::active()->forContext('inventory.stock_transfer')->orderBy('name')->get(['id', 'name']),
            'can_adjust_directly' => $user->can('inventory.stock.adjust'),
            // Staff: they may RAISE a correction and ASK for a move, and a
            // manager decides. Somebody with neither reads the list only.
            'can_request' => $user->can('inventory.stock.request'),
            'can_set_threshold' => $user->can('inventory.stock.approve'),
            // ⚠ Read, never created: InventorySetting::forCurrentBusiness()
            // writes a row, and opening a page should not.
            'approval_threshold' => InventorySetting::query()->value('adjustment_approval_threshold'),
            'awaiting_approval' => $request->session()->get('awaitingApproval'),
            'in_transit_count' => StockTransfer::where('status', TransferStatus::InTransit)->count(),
            'filters' => $filters,
        ]);
    }

    /**
     * The products the Low stock screen lists: held, and at or below their
     * threshold, by THE rule (Product::stockLevelIsLow).
     *
     * @return array<int, int>
     */
    private function lowStockProductIds(): array
    {
        $held = StockBatch::query()
            ->where('status', StockBatchStatus::Active)
            ->selectRaw('product_id, SUM(current_quantity) as quantity')
            ->groupBy('product_id')
            ->pluck('quantity', 'product_id');

        return Product::query()
            ->whereNotNull('low_stock_threshold')
            ->whereIn('id', $held->keys())
            ->get(['id', 'low_stock_threshold'])
            ->filter(fn (Product $product) => (float) $held[$product->id] > 0
                && Product::stockLevelIsLow($product->low_stock_threshold, (float) $held[$product->id]))
            ->pluck('id')
            ->all();
    }

    /**
     * What each product holds in each warehouse, so the dialog can say "12 kg
     * on hand" beside the quantity box before anybody writes off 120.
     *
     * @return array<string, string> "{product_id}-{warehouse_id}" => quantity
     */
    private function onHand(): array
    {
        return StockBatch::query()
            ->where('status', StockBatchStatus::Active)
            ->selectRaw('product_id, warehouse_id, SUM(current_quantity) as quantity')
            ->groupBy('product_id', 'warehouse_id')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->product_id.'-'.$row->warehouse_id => (string) $row->quantity])
            ->all();
    }

    /**
     * C-SC-1: found stock needs a cost, and the cost is never invented. The
     * newest batch's cost is SUGGESTED beside the box and a human accepts or
     * overrides it - the same suggestion the stock count makes.
     *
     * @return array<int, string> product_id => unit cost
     */
    private function lastCosts(): array
    {
        return StockBatch::query()
            ->whereIn('id', StockBatch::query()->selectRaw('MAX(id)')->groupBy('product_id'))
            ->pluck('unit_cost', 'product_id')
            ->map(fn ($cost) => (string) $cost)
            ->all();
    }

    /**
     * ⚠⚠ This used to call `ApplyStockAdjustment` directly, which was the live
     * second path: stock changed, movements were written, and nothing recorded
     * that a decision had been made, by whom, or when it applied. It now creates
     * an `InventoryAdjustment` like everything else, and that record either
     * approves itself under the configured threshold or waits for the Founder.
     *
     * The permission check stays: `inventory.stock.adjust` is what lets somebody
     * RAISE a correction. Whether it posts immediately is the threshold's
     * decision, not the controller's.
     */
    public function storeAdjustment(Request $request, CreateInventoryAdjustment $action): RedirectResponse
    {
        // ⚠ Either permission may RAISE a correction. `inventory.stock.request`
        // is what Staff hold, and removing their ability to raise one would have
        // been a silent loss of function in a refactor - the old flow let them
        // submit a request. Whether it POSTS is decided by the threshold and by
        // `inventory.stock.adjust`, not by who typed it.
        abort_unless(
            $request->user()->can('inventory.stock.adjust') || $request->user()->can('inventory.stock.request'),
            403,
        );

        $businessId = app(BusinessContext::class)->id();

        // ⚠⚠ `type` is OPTIONAL since 21 Sep 2026. The screen never sent it and
        // the refusal landed on a key it never rendered, so no correction typed
        // on the Stock screen ever saved. A person answers "What happened?"
        // once (the reason) and the type follows from it; a caller that knows
        // better - a stock count, a test - still sends its own.
        //
        // ⚠ The three lookups are scoped to THIS business. A bare
        // `exists:products,id` passes another tenant's id and the findOrFail
        // below then answers with a blank 404 instead of a field error.
        $data = $request->validate([
            'type' => ['nullable', new Enum(InventoryAdjustmentType::class)],
            'product_id' => ['required', Rule::exists('products', 'id')->where('business_id', $businessId)],
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('business_id', $businessId)],
            'direction' => ['required', new Enum(AdjustmentDirection::class)],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['nullable', 'required_if:direction,increase', 'numeric', 'min:0'],
            // ⚠ Bug hunt 16 Oct 2026: a stock correction's own reasons only,
            // not any reason of the business (a transfer's, a leave's).
            'reason_id' => ['required', Rule::exists('reasons', 'id')->where('business_id', $businessId)->where('context', 'inventory.stock_adjustment')],
            'effective_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
            // ⭐ Initial plan B6 (Joy, 24 Oct 2026; R71): "select the specific
            // Batch Number that spoiled ... writes off the exact correct
            // value, not a generic average". Optional: blank lets the engine
            // take the oldest batches as before. Checked against the product
            // and warehouse below.
            'stock_batch_id' => ['nullable', 'integer', Rule::exists('stock_batches', 'id')->where('business_id', $businessId)],
        ], [
            'reason_id.required' => 'Choose what happened.',
            'reason_id.exists' => 'Choose one of the stock correction reasons.',
            'product_id.required' => 'Choose the product.',
            'warehouse_id.required' => 'Choose the warehouse.',
            'quantity.required' => 'Enter how many.',
            'quantity.gt' => 'Enter a quantity above zero.',
            'direction.required' => 'Choose whether there is more or less than the system shows.',
            'unit_cost.required_if' => 'Stock that turned up needs a cost for each unit.',
        ]);

        $reason = Reason::findOrFail($data['reason_id']);
        $type = isset($data['type']) ? InventoryAdjustmentType::from($data['type']) : AdjustmentCause::typeFor($reason);

        // ⚠ Bug hunt 16 Oct 2026: damaged, expired, lost and used-up stock only
        // ever takes stock away. The dialog fixed the direction, but a crafted
        // request could raise stock "because it was damaged". Refused here, on
        // the screen's path; a stock count still posts through the engine.
        if (AdjustmentCause::onlyReduces($type) && $data['direction'] === AdjustmentDirection::Increase->value) {
            throw ValidationException::withMessages([
                'direction' => "{$type->label()} can only take stock away. Stock that turned up is recorded as found stock.",
            ]);
        }

        $product = Product::findOrFail($data['product_id']);
        $warehouse = Warehouse::findOrFail($data['warehouse_id']);
        $batch = $this->chosenBatch($data, $product, $warehouse);

        // ⚠⚠ C-INV-E1. CreateInventoryAdjustment raises NO ValidationException at
        // all: every one of its refusals is a DomainException or an
        // InvalidArgumentException, and the period lock rides in on top. The
        // whole action was therefore unreadable from this screen. `stock` is the
        // form-level key the three stock forms now render.
        try {
            $adjustment = $action->handle(
                type: $type,
                direction: AdjustmentDirection::from($data['direction']),
                product: $product,
                warehouse: $warehouse,
                quantity: $data['quantity'],
                reason: $reason,
                createdBy: $request->user(),
                unitCost: $data['unit_cost'] ?? null,
                note: $data['note'] ?? null,
                effectiveDate: $data['effective_date'] ?? null,
                // ⭐ B6: a named batch leaves at its own cost
                // (ApproveInventoryAdjustment::decreaseNamedBatch), onto the
                // reason's own loss head (Q14 = A, StockLossHeads).
                batch: $batch,
            );
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages(['stock' => $e->getMessage()]);
        }

        if ($adjustment->approvalRequest->isComplete()) {
            return back()->with('success', 'Stock adjustment saved.');
        }

        // ⚠ "Submitted for approval" with nowhere to go is a dead end: the
        // person has to know the correction changes nothing yet, and where the
        // approver will find it. The page turns this into a notice with a link
        // to /approvals (a flashed key is not a prop until a page passes it).
        return back()
            ->with('success', 'Stock adjustment submitted for approval.')
            ->with('awaitingApproval', 'Stock adjustment submitted for approval. Stock does not change until it is approved.');
    }

    /**
     * ⭐ Initial plan B6 (Joy, 24 Oct 2026; R71): the batches a correction may
     * name, for the batch picker on "What happened to the stock?". Every batch
     * of the product still holding stock in that warehouse, soonest expiry
     * first (the order the engine would take them in), with its own cost so
     * the person sees what the loss will be valued at. Expired and held
     * batches are listed and marked: those are exactly the ones written off.
     *
     * ⚠ A GET that writes nothing, like the SKU proposal, for the same reason
     * (the business stamp rides on Inertia visits, not on a plain fetch).
     */
    public function batchOptions(Request $request): JsonResponse
    {
        // ⭐ B8: whoever records production runs picks their batches here too.
        abort_unless(
            $request->user()->can('inventory.stock.adjust') || $request->user()->can('inventory.stock.request') || $request->user()->can('inventory.production.manage'),
            403,
        );

        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('business_id', $businessId)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('business_id', $businessId)],
        ]);

        $today = Carbon::today()->toDateString();

        $rows = StockBatch::query()
            ->where('product_id', $data['product_id'])
            ->where('warehouse_id', $data['warehouse_id'])
            ->where('current_quantity', '>', 0)
            ->orderByRaw('case when expiry_date is null then 1 else 0 end')
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->get(['id', 'batch_number', 'expiry_date', 'received_at', 'current_quantity', 'unit_cost', 'held_at']);

        // ⭐ Initial plan B8 (Joy, 24 Oct 2026): the production run's picker
        // shows who each batch was bought from, too.
        $suppliers = BatchSuppliers::for($rows->pluck('id')->map(fn ($id) => (int) $id)->all());

        $batches = $rows
            ->map(fn (StockBatch $batch) => [
                'id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'expiry_date' => $batch->expiry_date?->toDateString(),
                'received_on' => $batch->received_at?->toDateString(),
                'current_quantity' => Product::plainQuantity((string) $batch->current_quantity),
                'unit_cost' => number_format((float) $batch->unit_cost, 2, '.', ''),
                'expired' => $batch->expiry_date !== null && $batch->expiry_date->toDateString() < $today,
                'on_hold' => $batch->held_at !== null,
                'supplier' => $suppliers[$batch->id] ?? null,
            ])
            ->values();

        return response()->json(['batches' => $batches]);
    }

    /**
     * The batch a correction named, refused in a sentence when it cannot be
     * the one: stock that turned up has no batch to come out of, the batch
     * must be this product in this warehouse, and it cannot lose more than
     * it holds (the approval checks again, under a lock).
     *
     * @param  array<string, mixed>  $data
     */
    private function chosenBatch(array $data, Product $product, Warehouse $warehouse): ?StockBatch
    {
        if (empty($data['stock_batch_id'])) {
            return null;
        }

        if ($data['direction'] !== AdjustmentDirection::Decrease->value) {
            throw ValidationException::withMessages([
                'stock_batch_id' => 'A batch is chosen only when stock goes down. Found stock comes in as a new batch at the cost you type.',
            ]);
        }

        $batch = StockBatch::query()->whereKey($data['stock_batch_id'])->first();

        if ($batch === null || (int) $batch->product_id !== (int) $product->id || (int) $batch->warehouse_id !== (int) $warehouse->id) {
            throw ValidationException::withMessages([
                'stock_batch_id' => "That batch is not {$product->name} in {$warehouse->name}. Choose one of the batches listed.",
            ]);
        }

        if (bccomp((string) $data['quantity'], (string) $batch->current_quantity, 4) > 0) {
            throw ValidationException::withMessages([
                'stock_batch_id' => "Batch {$batch->batch_number} holds only ".Product::plainQuantity((string) $batch->current_quantity).'. Enter that much or less, or choose another batch.',
            ]);
        }

        return $batch;
    }

    public function storeTransfer(Request $request, DispatchStockTransfer $action): RedirectResponse
    {
        $this->authorize('inventory.stock.adjust');

        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')->where('business_id', $businessId)],
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('business_id', $businessId)],
            'target_warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('business_id', $businessId), 'different:warehouse_id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reason_id' => ['required', Rule::exists('reasons', 'id')->where('business_id', $businessId)],
            'note' => ['nullable', 'string'],
        ], [
            'target_warehouse_id.different' => 'Stock has to move to a different warehouse.',
            'target_warehouse_id.required' => 'Choose where the stock goes.',
            'reason_id.required' => 'Choose why it is moving.',
            'product_id.required' => 'Choose the product.',
            'warehouse_id.required' => 'Choose the warehouse.',
            'quantity.required' => 'Enter how many.',
            'quantity.gt' => 'Enter a quantity above zero.',
        ]);

        // ⚠ Transferring consumes FIFO stock, so insufficient stock is the
        // ordinary refusal here and it was a blank 500.
        try {
            $action->handle(
                product: Product::findOrFail($data['product_id']),
                source: Warehouse::findOrFail($data['warehouse_id']),
                target: Warehouse::findOrFail($data['target_warehouse_id']),
                quantity: $data['quantity'],
                reason: Reason::findOrFail($data['reason_id']),
                performedBy: $request->user(),
                note: $data['note'] ?? null,
            );
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages(['stock' => $e->getMessage()]);
        }

        return back()->with('success', 'Stock transfer dispatched.');
    }

    /**
     * ⭐ Initial plan B7 (Joy, 24 Oct 2026): Move back to stock. Part of one
     * batch in a Not for sale (quarantine) warehouse goes back to a selling
     * warehouse, same batch and cost, with the reason it can be sold again.
     * The same hands that move stock (stock.adjust); a transfer posts nothing.
     */
    public function moveBack(Request $request, MoveStockBackToSale $action): RedirectResponse
    {
        $this->authorize('inventory.stock.adjust');

        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'effective_date' => ['required', 'date'],
            'stock_batch_id' => ['required', 'integer', Rule::exists('stock_batches', 'id')->where('business_id', $businessId)],
            'target_warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('business_id', $businessId)],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reason_text' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'stock_batch_id.required' => 'Choose the batch that goes back.',
            'target_warehouse_id.required' => 'Choose the warehouse it goes back to.',
            'quantity.required' => 'Enter how many.',
            'quantity.gt' => 'Enter a quantity above zero.',
            'reason_text.required' => 'Say why it can be sold again.',
            'reason_text.min' => 'Say why it can be sold again (at least 5 characters).',
        ]);

        try {
            $transfer = $action->handle(
                batch: StockBatch::findOrFail($data['stock_batch_id']),
                target: Warehouse::findOrFail($data['target_warehouse_id']),
                quantity: (string) $data['quantity'],
                why: $data['reason_text'],
                by: $request->user(),
                occurredAt: $data['effective_date'],
            );
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages(['stock' => $e->getMessage()]);
        }

        return back()->with('success', Product::plainQuantity((string) $transfer->quantity).' moved back to '.$transfer->targetWarehouse?->name.'.');
    }

    public function transfersIndex(Request $request): Response
    {
        $filters = ['status' => $request->string('status')->toString()];

        $transfers = StockTransfer::with([
            'product:id,name,sku',
            'sourceWarehouse:id,name',
            'targetWarehouse:id,name',
            'reason:id,name',
            'dispatchedBy:id,name',
            'receivedBy:id,name',
            'reversedBy:id,name',
        ])
            ->when($filters['status'], fn ($query, $status) => $query->where('status', $status))
            ->latest('dispatched_at')
            ->paginate(PerPage::from())
            ->withQueryString();

        // ⭐ Corrections wave 1 (20 Oct 2026): Cancel and Receive part on a
        // transfer still on the road, from its policy.
        $policy = app(StockTransferActionPolicy::class);
        $transfers->getCollection()->transform(fn (StockTransfer $transfer) => [
            ...$transfer->toArray(),
            'actions' => $policy->actionsFor($transfer, $request->user())->toArray()['actions'],
        ]);

        return Inertia::render('inventory/stock/transfers', [
            'transfers' => $transfers,
            'filters' => $filters,
            'can_receive' => $request->user()->can('inventory.stock.adjust'),
            'cancelReasons' => Reason::query()->active()->forContext('cancellation')->orderBy('id')->get(['id', 'name']),
            'correctionReasons' => Reason::query()->active()->forContext('correction')->orderBy('id')->get(['id', 'name']),
            // ⭐ B24 (25 Oct 2026): Reverse a received transfer (the engine's dialog).
            'reversalReasons' => Reason::query()->active()->forContext('reversal')->orderBy('id')->get(['id', 'name']),
            'lossReasons' => Reason::active()->forContext('inventory.stock_adjustment')->orderBy('name')->get()
                ->map(fn (Reason $reason) => AdjustmentCause::describe($reason))
                ->filter(fn (array $reason) => $reason['only_reduces'])->values(),
        ]);
    }

    public function receiveTransfer(Request $request, StockTransfer $stockTransfer, ReceiveStockTransfer $action): RedirectResponse
    {
        $this->authorize('inventory.stock.adjust');

        // ⚠ A bare button on the transfers screen. Receiving the same transfer
        // twice is what two people at one gate actually do.
        try {
            $action->handle($stockTransfer, $request->user());
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Transfer received into stock.');
    }

    public function approveAdjustment(Request $request, InventoryAdjustment $adjustment, ApproveInventoryAdjustment $action): RedirectResponse
    {
        $this->authorize('inventory.stock.approve');

        // ⚠⚠ Approval is what POSTS, so this one button carries the whole chain:
        // stock movements, insufficient stock, the period lock and the account
        // mappings. It is also a bare button, so it flashes.
        try {
            // The note carries the reason a self-approval needs (Rule #22).
            $action->handle($adjustment, $request->user(), $request->string('note')->trim()->toString() ?: null);
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Inventory adjustment approved.');
    }

    /**
     * ⚠ A rejected adjustment posts nothing. There is no "undo" to write,
     * because the movements were never created - approval is what posts, so
     * rejection is simply the absence of it.
     */
    public function rejectAdjustment(Request $request, InventoryAdjustment $adjustment, RejectApprovable $action): RedirectResponse
    {
        $this->authorize('inventory.stock.approve');

        try {
            $action->handle($adjustment, $request->user(), $request->input('review_note'));
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Inventory adjustment rejected.');
    }
}
