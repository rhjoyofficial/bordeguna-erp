<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordOpeningStock;
use App\Modules\Inventory\Corrections\OpeningStockActionPolicy;
use App\Modules\Inventory\Models\OpeningStock;
use App\Modules\Inventory\Models\OpeningStockLine;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * What the warehouse already held on the day the books start.
 *
 * ⚠ Gated on `inventory.stock.adjust`, not `stock.record`. Opening stock sets
 * the cost layers FIFO will consume for the rest of the business's life, so it
 * decides every margin reported until that stock is sold through. That is an
 * owner's decision, not a counter task.
 */
class OpeningStockController extends Controller
{
    public function index(Request $request, OpeningStockActionPolicy $policy): Response
    {
        // ⭐ Corrections wave 1 (20 Oct 2026): the live opening stock of each
        // warehouse by default; "Show corrections" adds the corrected ones,
        // marked, so the list never counts a warehouse twice.
        $showCorrections = $request->boolean('corrections');

        $recorded = OpeningStock::query()
            ->with(['warehouse:id,name', 'lines.product:id,name,sku', 'lines.stockMovement', 'reversedBy:id,name', 'correctionAsOriginal'])
            ->when(! $showCorrections, fn ($q) => $q->live())
            ->orderByDesc('as_at')
            ->orderByDesc('id')
            ->get();

        // ⚠ Once per warehouse, so the screen offers only the ones still
        // awaiting it rather than letting somebody discover the refusal after
        // typing fifty lines. Any opening stock counts, corrected or not.
        $done = OpeningStock::query()->pluck('warehouse_id')->unique()->all();

        $active = Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'is_default']);
        $awaiting = $active->whereNotIn('id', $done)->values();

        return Inertia::render('inventory/opening-stock/index', [
            'openingStocks' => $recorded->map(fn (OpeningStock $opening) => [
                ...$opening->toArray(),
                'number' => $policy->numberOf($opening),
                'total' => $opening->totalValue()->toTaka(),
                'status' => $opening->isReversed() ? 'corrected' : 'live',
                'corrected_by' => $opening->correctionAsOriginal?->successor_number,
                'correction_of' => $opening->corrects_id === null ? null : "Opening stock #{$opening->corrects_id}",
                'actions' => $policy->actionsFor($opening, $request->user())->toArray()['actions'],
            ])->values(),
            'showCorrections' => $showCorrections,
            'warehouses' => $awaiting->map->only(['id', 'name', 'code'])->values(),
            // ⚠ The default warehouse first, when it is still waiting: it is
            // where the count most likely happened, and the form used to open
            // on whichever warehouse sorted first alphabetically.
            'defaultWarehouseId' => $awaiting->firstWhere('is_default', true)?->id ?? $awaiting->first()?->id,
            // ⚠⚠ "Every active warehouse already has its opening stock" was
            // shown to a business with NO warehouses at all (21 Sep 2026): zero
            // left to do read as all done. The page needs to tell them apart.
            'hasWarehouses' => $active->isNotEmpty(),
            'canManageWarehouses' => request()->user()?->can('inventory.catalog.manage') ?? false,
            // The unit beside each quantity box: "40" is kilograms or sacks, and
            // the count sheet the numbers came from says which.
            'products' => Product::query()->where('is_active', true)->stocked()
                ->with('unitOfMeasure:id,abbreviation')
                ->orderBy('name')->get(['id', 'name', 'sku', 'unit_of_measure_id'])
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'unit' => $product->unitOfMeasure?->abbreviation,
                ]),
            // ⚠ ALL active warehouses, not just the ones still awaiting the
            // document. The count sheet is printed before the count and the
            // person printing it may well be checking one already recorded;
            // the sheet itself says so in red rather than hiding the warehouse.
            'countSheetWarehouses' => $active->map->only(['id', 'name', 'code'])->values(),
            'countSheetDefaultId' => $active->firstWhere('is_default', true)?->id ?? $active->first()?->id,
        ]);
    }

    /**
     * ⭐ Corrections wave 1, B4 (20 Oct 2026): the Correct form, pre-filled
     * with every line. Nothing is saved here: "Preview and correct" opens the
     * action dialog, which shows what moves and posts the difference to
     * Opening Balance Equity on the opening date, in one go.
     */
    public function correct(Request $request, OpeningStock $openingStock, OpeningStockActionPolicy $policy): Response|RedirectResponse
    {
        $availability = $policy->availability($openingStock, $request->user(), DocumentAction::Correct);

        if ($openingStock->isReversed()) {
            return redirect()->route('inventory.opening-stock.index')->with('error', $availability->blockers[0]->message ?? 'This opening stock was already corrected.');
        }

        $openingStock->load(['warehouse:id,name', 'lines.product.unitOfMeasure', 'lines.stockMovement.batch']);

        return Inertia::render('inventory/opening-stock/correct', [
            'opening' => [
                'id' => $openingStock->id,
                'number' => $policy->numberOf($openingStock),
                'warehouse' => $openingStock->warehouse?->name,
                'as_at' => Carbon::parse($openingStock->as_at)->toDateString(),
                'note' => $openingStock->note ?? '',
                'total' => $openingStock->totalValue()->toTaka(),
                'lines' => $openingStock->lines->map(fn (OpeningStockLine $line) => [
                    'line_id' => $line->id,
                    'product_id' => (string) $line->product_id,
                    'product' => $line->product?->name,
                    'unit' => $line->product?->unitOfMeasure?->abbreviation,
                    'quantity' => $this->trim((string) $line->quantity),
                    'unit_cost' => $this->trim((string) $line->unit_cost),
                    'batch_number' => $line->batch_number ?? $line->stockMovement?->batch?->batch_number ?? '',
                    'expiry_date' => $line->expiry_date?->toDateString() ?? '',
                    'on_hand' => $this->trim((string) ($line->stockMovement?->batch?->current_quantity ?? '0')),
                    'tracks_expiry' => (bool) $line->product?->tracks_expiry,
                ])->values()->all(),
            ],
            'availability' => $availability->toArray(),
            'type' => OpeningStockActionPolicy::TYPE,
            'correctionReasons' => Reason::query()->active()->forContext('correction')->orderBy('id')->get(['id', 'name'])
                ->map(fn (Reason $r) => ['id' => $r->id, 'name' => $r->name])->values()->all(),
            'products' => Product::query()->where('is_active', true)->stocked()
                ->with('unitOfMeasure:id,abbreviation')
                ->orderBy('name')->get(['id', 'name', 'sku', 'unit_of_measure_id', 'tracks_expiry'])
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'unit' => $product->unitOfMeasure?->abbreviation,
                    'tracks_expiry' => (bool) $product->tracks_expiry,
                ]),
        ]);
    }

    /** `20.0000` -> `20`, `20.5000` -> `20.5`. Trims, never rounds. */
    private function trim(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    public function store(Request $request, RecordOpeningStock $action): RedirectResponse
    {
        $data = $request->validate([
            // ⚠ Both lookups scoped to this business. Another tenant's product id
            // passed a bare `exists` and then fell out of the scoped product
            // list below as an undefined index - a blank 500.
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('business_id', app(BusinessContext::class)->id())],
            'as_at' => ['required', 'date'],
            'note' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('business_id', app(BusinessContext::class)->id())],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'lines.*.batch_number' => ['nullable', 'string', 'max:100'],
            'lines.*.expiry_date' => ['nullable', 'date'],
        ]);

        $products = Product::query()
            ->whereIn('id', collect($data['lines'])->pluck('product_id'))
            ->get()->keyBy('id');

        // ⚠⚠ C-INV-E1. The one write of the whole cutover. A closed month, a
        // future date, or a missing account mapping each refused it correctly
        // and each arrived blank. `as_at` carries them because the date is what
        // the person can actually change, and the mapping message names the key
        // that is missing.
        try {
            $action->handle(
                warehouse: Warehouse::findOrFail($data['warehouse_id']),
                lines: collect($data['lines'])->map(fn (array $line) => [
                    'product' => $products[$line['product_id']],
                    'quantity' => (string) $line['quantity'],
                    'unitCost' => (string) $line['unit_cost'],
                    'batchNumber' => $line['batch_number'] ?? null,
                    'expiryDate' => $line['expiry_date'] ?? null,
                ])->all(),
                createdBy: $request->user(),
                asAt: $data['as_at'],
                note: $data['note'] ?? null,
            );
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages(['as_at' => $e->getMessage()]);
        }

        return back()->with('success', 'Opening stock recorded.');
    }
}
