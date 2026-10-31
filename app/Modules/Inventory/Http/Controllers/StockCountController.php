<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\CompleteStockCount;
use App\Modules\Inventory\Actions\StartStockCount;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Enums\StockCountStatus;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockCountLine;
use App\Modules\Inventory\Models\Warehouse;
use App\Support\PerPage;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * The cycle-count screens (C-CNT). Route + screen + nav link ship together -
 * the reachability rule.
 *
 * Reading rides `inventory.reports.view`; every write rides the SAME dual rule
 * as the manual adjustment: either `inventory.stock.request` or
 * `inventory.stock.adjust` may RAISE, and whether the variances post
 * immediately is the threshold's decision inside the action, never this
 * controller's.
 */
class StockCountController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('inventory/stock-counts/index', [
            'counts' => StockCount::query()
                ->with(['warehouse:id,name', 'category:id,name', 'brand:id,name'])
                ->withCount(['lines', 'lines as counted_lines_count' => fn ($query) => $query->whereNotNull('counted_quantity')])
                ->orderByDesc('id')
                ->paginate(PerPage::from())
                ->withQueryString()
                ->through(fn (StockCount $count) => [
                    'id' => $count->id,
                    'count_number' => $count->count_number,
                    'status' => $count->status->value,
                    'warehouse' => $count->warehouse?->name,
                    'category' => $count->category?->name,
                    'brand' => $count->brand?->name,
                    'lines_count' => $count->lines_count,
                    'counted_lines_count' => $count->counted_lines_count,
                    'created_at' => $count->created_at?->toDateTimeString(),
                    'completed_at' => $count->completed_at?->toDateTimeString(),
                ]),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            // Preselected when starting a count, like every other stock screen.
            'defaultWarehouseId' => Warehouse::query()->where('is_active', true)->where('is_default', true)->value('id'),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'brands' => Brand::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canWrite' => $this->mayWrite(),
        ]);
    }

    public function store(Request $request, StartStockCount $action): RedirectResponse
    {
        $this->authorizeWrite();

        // ⚠ Scoped to this business: a bare `exists` passes another tenant's
        // id and findOrFail then answers with a blank 404, not a field error.
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', $this->ownRow('warehouses')],
            'category_id' => ['nullable', 'integer', $this->ownRow('categories')],
            'brand_id' => ['nullable', 'integer', $this->ownRow('brands')],
            'note' => ['nullable', 'string'],
        ], [
            'warehouse_id.required' => 'Choose the warehouse to count.',
        ]);

        $count = $action->handle(
            warehouse: Warehouse::findOrFail($data['warehouse_id']),
            createdBy: $request->user(),
            category: isset($data['category_id']) ? Category::find($data['category_id']) : null,
            brand: isset($data['brand_id']) ? Brand::find($data['brand_id']) : null,
            note: $data['note'] ?? null,
        );

        return redirect()
            ->route('inventory.stock-counts.show', $count)
            ->with('success', "Stock count {$count->count_number} started with {$count->lines->count()} lines.");
    }

    public function show(StockCount $stockCount): Response
    {
        $stockCount->load([
            'warehouse:id,name,code', 'category:id,name', 'brand:id,name', 'reason:id,name',
            'lines.product:id,name,sku,unit_of_measure_id',
            'lines.product.unitOfMeasure:id,abbreviation',
            'lines.adjustment.approvalRequest',
            'createdBy:id,name', 'completedBy:id,name',
        ]);

        $live = $this->liveByProduct($stockCount);
        $suggested = $this->suggestedCosts($stockCount);

        return Inertia::render('inventory/stock-counts/show', [
            'count' => [
                'id' => $stockCount->id,
                'count_number' => $stockCount->count_number,
                'status' => $stockCount->status->value,
                'warehouse' => $stockCount->warehouse?->name,
                'category' => $stockCount->category?->name,
                'brand' => $stockCount->brand?->name,
                'reason' => $stockCount->reason?->name,
                'note' => $stockCount->note,
                'created_by' => $stockCount->createdBy?->name,
                'created_at' => $stockCount->created_at?->toDateTimeString(),
                'completed_by' => $stockCount->completedBy?->name,
                'completed_at' => $stockCount->completed_at?->toDateTimeString(),
            ],
            'lines' => $stockCount->lines->map(fn (StockCountLine $line) => [
                'id' => $line->id,
                'product' => $line->product?->name,
                'sku' => $line->product?->sku,
                'unit' => $line->product?->unitOfMeasure?->abbreviation,
                'expected_quantity' => $line->expected_quantity,
                'counted_quantity' => $line->counted_quantity,
                // Drafts show the live book so drift is visible while it can
                // still be recounted (C-CNT-2); completed counts show the live
                // figure the variance was actually sized against.
                'live_quantity' => $stockCount->isDraft()
                    ? ($live[$line->product_id] ?? '0')
                    : $line->live_quantity,
                'unit_cost' => $line->unit_cost,
                'suggested_unit_cost' => $suggested[$line->product_id] ?? null,
                'adjustment' => $line->adjustment === null ? null : [
                    'id' => $line->adjustment->id,
                    'direction' => $line->adjustment->direction->value,
                    'quantity' => $line->adjustment->quantity,
                    'status' => $line->adjustment->approvalRequest?->status->value,
                ],
            ]),
            'reasons' => Reason::active()->forContext('inventory.stock_adjustment')->orderBy('name')->get(['id', 'name']),
            'addableProducts' => $stockCount->isDraft() ? Product::query()
                ->stocked()
                ->where('is_active', true)
                ->whereNotIn('id', $stockCount->lines->pluck('product_id'))
                ->orderBy('name')
                ->get(['id', 'name', 'sku'])
                ->map(fn (Product $product) => [
                    'id' => $product->id, 'name' => $product->name, 'sku' => $product->sku,
                ]) : [],
            'canWrite' => $this->mayWrite(),
            'awaitingApproval' => session('awaitingApproval'),
        ]);
    }

    /**
     * C-CNT-7. The blind sheet: product identity and a blank column, never the
     * expected quantity - a counter who can see the book number writes the
     * book number.
     */
    public function sheet(StockCount $stockCount): Response
    {
        $stockCount->load([
            'warehouse:id,name,code',
            'lines.product:id,name,sku,unit_of_measure_id',
            'lines.product.unitOfMeasure:id,abbreviation',
        ]);

        return Inertia::render('inventory/stock-counts/sheet', [
            'count' => [
                'id' => $stockCount->id,
                'count_number' => $stockCount->count_number,
                'warehouse' => $stockCount->warehouse?->name,
                'created_at' => $stockCount->created_at?->toDateString(),
            ],
            'lines' => $stockCount->lines->map(fn (StockCountLine $line) => [
                'id' => $line->id,
                'product' => $line->product?->name,
                'sku' => $line->product?->sku,
                'unit' => $line->product?->unitOfMeasure?->abbreviation,
            ]),
        ]);
    }

    public function updateLine(Request $request, StockCount $stockCount, StockCountLine $line): RedirectResponse
    {
        $this->authorizeWrite();
        $this->assertDraft($stockCount);

        $data = $request->validate([
            'counted_quantity' => ['nullable', 'numeric', 'min:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $line->update([
            'counted_quantity' => $data['counted_quantity'] ?? null,
            'unit_cost' => $data['unit_cost'] ?? null,
        ]);

        return back()->with('success', 'Line saved.');
    }

    /**
     * "Save all": every row typed since the last save, in one request.
     *
     * ⚠⚠ WHY (21 Sep 2026). Each row had its own Save button and Complete
     * reads only what was SAVED, so a counter who typed forty numbers and
     * pressed Complete had every unsaved one treated as "nobody looked" -
     * skipped, silently, with the count closed behind it.
     *
     * ⚠ Keyed by LINE ID (`lines.{id}.counted_quantity`), so a refusal lands on
     * the row it belongs to rather than on "line 7 of whatever was sent".
     */
    public function updateLines(Request $request, StockCount $stockCount): RedirectResponse
    {
        $this->authorizeWrite();
        $this->assertDraft($stockCount);

        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => ['array'],
            'lines.*.counted_quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ], [
            'lines.*.counted_quantity.numeric' => 'Enter a number.',
            'lines.*.counted_quantity.min' => 'A count cannot be below zero.',
            'lines.*.unit_cost.numeric' => 'Enter a number.',
            'lines.*.unit_cost.min' => 'A cost cannot be below zero.',
        ]);

        $lines = $stockCount->lines()->whereIn('id', array_keys($data['lines']))->get()->keyBy('id');

        // ⚠ Only this count's own lines. An id from another count (or another
        // business) is refused whole rather than half-saved.
        if ($lines->count() !== count($data['lines'])) {
            throw ValidationException::withMessages(['count' => 'Some of these lines are not on this count. Reload the page and try again.']);
        }

        DB::transaction(function () use ($data, $lines): void {
            foreach ($data['lines'] as $id => $values) {
                $lines[$id]->update([
                    'counted_quantity' => $values['counted_quantity'] ?? null,
                    'unit_cost' => $values['unit_cost'] ?? null,
                ]);
            }
        });

        return back()->with('success', count($data['lines']) === 1 ? 'Line saved.' : count($data['lines']).' lines saved.');
    }

    /** C-CNT-8: found stock - the book says absent, the counter says here. */
    public function addLine(Request $request, StockCount $stockCount): RedirectResponse
    {
        $this->authorizeWrite();
        $this->assertDraft($stockCount);

        $data = $request->validate([
            'product_id' => ['required', 'integer', $this->ownRow('products')],
        ]);

        $product = Product::findOrFail($data['product_id']);

        if (! $product->type->isStocked()) {
            throw ValidationException::withMessages(['product_id' => "{$product->name} is a service and cannot be counted."]);
        }

        if ($stockCount->lines()->where('product_id', $product->id)->exists()) {
            throw ValidationException::withMessages(['product_id' => "{$product->name} is already on this count."]);
        }

        $stockCount->lines()->create([
            'product_id' => $product->id,
            'expected_quantity' => (string) (StockBatch::query()
                ->where('warehouse_id', $stockCount->warehouse_id)
                ->where('product_id', $product->id)
                ->where('status', StockBatchStatus::Active)
                ->sum('current_quantity') ?? '0'),
        ]);

        return back()->with('success', "{$product->name} added to the count.");
    }

    public function complete(Request $request, StockCount $stockCount, CompleteStockCount $action): RedirectResponse
    {
        $this->authorizeWrite();

        $data = $request->validate([
            'reason_id' => ['required', 'integer', $this->ownRow('reasons')],
        ], [
            'reason_id.required' => 'Choose a reason for the corrections.',
        ]);

        // ⚠ The whole action surfaces as a form error, never a blank 500 - the
        // C-INV-E1 lesson. The period lock and the adjustment engine's own
        // refusals ride in on the same key.
        try {
            $count = $action->handle(
                count: $stockCount,
                reason: Reason::findOrFail($data['reason_id']),
                completedBy: $request->user(),
            );
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->withErrors(['count' => $e->getMessage()]);
        }

        $count->load('lines.adjustment.approvalRequest');
        $raised = $count->lines->filter(fn (StockCountLine $line) => $line->adjustment !== null);
        $waiting = $raised->filter(fn (StockCountLine $line) => $line->adjustment->approvalRequest?->isPending())->count();

        $response = back()->with('success', "Stock count {$count->count_number} completed. "
            .($raised->isEmpty() ? 'The shelf agrees with the book: no corrections needed.' : "{$raised->count()} variance correction(s) raised."));

        // ⚠ Each variance is its own correction and, under the threshold rule,
        // may be waiting for an approver. Say so, and where (the page links it).
        return $waiting === 0 ? $response : $response->with(
            'awaitingApproval',
            "{$waiting} correction(s) from this count are waiting for approval. Stock does not change until they are approved.",
        );
    }

    public function cancel(StockCount $stockCount): RedirectResponse
    {
        $this->authorizeWrite();
        $this->assertDraft($stockCount);

        $stockCount->update(['status' => StockCountStatus::Cancelled]);

        return back()->with('success', "Stock count {$stockCount->count_number} cancelled. Nothing was changed.");
    }

    /** The live book per product, for showing drift on a draft (C-CNT-2). */
    private function liveByProduct(StockCount $count): array
    {
        return StockBatch::query()
            ->where('warehouse_id', $count->warehouse_id)
            ->whereIn('product_id', $count->lines->pluck('product_id'))
            ->where('status', StockBatchStatus::Active)
            ->selectRaw('product_id, SUM(current_quantity) as quantity')
            ->groupBy('product_id')
            ->pluck('quantity', 'product_id')
            ->map(fn ($quantity) => (string) $quantity)
            ->all();
    }

    /**
     * C-CNT-4 via C-SC-1: the newest batch's cost as the SUGGESTION for a
     * found-stock increase - the human accepts or overrides on the line.
     */
    private function suggestedCosts(StockCount $count): array
    {
        return StockBatch::query()
            ->whereIn('product_id', $count->lines->pluck('product_id'))
            ->orderByDesc('received_at')->orderByDesc('id')
            ->get(['product_id', 'unit_cost'])
            ->unique('product_id')
            ->pluck('unit_cost', 'product_id')
            ->map(fn ($cost) => (string) $cost)
            ->all();
    }

    private function ownRow(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('business_id', app(BusinessContext::class)->id());
    }

    private function mayWrite(): bool
    {
        $user = request()->user();

        return $user !== null && ($user->can('inventory.stock.adjust') || $user->can('inventory.stock.request'));
    }

    private function authorizeWrite(): void
    {
        abort_unless($this->mayWrite(), 403);
    }

    private function assertDraft(StockCount $count): void
    {
        if (! $count->isDraft()) {
            throw ValidationException::withMessages([
                'count' => "Stock count {$count->count_number} is {$count->status->value} and can no longer be changed.",
            ]);
        }
    }
}
