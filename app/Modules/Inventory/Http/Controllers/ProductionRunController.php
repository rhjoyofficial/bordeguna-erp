<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\VoucherAccounts;
use App\Modules\Foundation\Corrections\CorrectionChain;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\ValidatesActionReason;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\PostProductionRunJournal;
use App\Modules\Inventory\Actions\RecordProductionRun;
use App\Modules\Inventory\Corrections\ProductionRunActionPolicy;
use App\Modules\Inventory\Enums\ProductionCostKind;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\BillOfMaterial;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductionRun;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\MaxMakeable;
use App\Modules\Inventory\Support\ProductionRunForm;
use App\Modules\Inventory\Support\UnitConverter;
use App\Modules\Purchasing\Support\ReceivedBatchDates;
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
 * The production run's screens: list, entry, detail. Route + screen + nav link
 * ship together, or the engine action does not exist - the reachability rule
 * this project keeps re-learning.
 */
class ProductionRunController extends Controller
{
    use ValidatesActionReason;

    public function index(Request $request): Response
    {
        $filters = ['status' => $request->string('status')->toString()];

        $runs = ProductionRun::query()
            ->with(['warehouse:id,name', 'outputProduct:id,name,sku'])
            ->when($filters['status'], fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (ProductionRun $run) => [
                'id' => $run->id,
                'run_number' => $run->run_number,
                'status' => $run->status->value,
                'warehouse' => $run->warehouse?->name,
                'output_product' => $run->outputProduct?->name,
                'output_sku' => $run->outputProduct?->sku,
                'output_quantity' => $run->output_quantity,
                'consumed_value' => $run->consumed_value,
                'waste_value' => $run->waste_value,
                'expense_waste' => $run->expense_waste,
                'yield_flagged' => $run->yieldFlagged(),
                'occurred_at' => $run->occurred_at?->toDateTimeString(),
            ]);

        return Inertia::render('inventory/production-runs/index', [
            'runs' => $runs,
            'filters' => $filters,
        ]);
    }

    public function create(Request $request, UnitConverter $converter): Response
    {
        return $this->formPage($request, $converter);
    }

    /**
     * ⭐ Corrections wave 1, B4 (20 Oct 2026): Edit a DRAFT. The new-run form,
     * pre-filled; saving rewrites the draft (no reason: nothing has moved).
     */
    public function edit(Request $request, ProductionRun $run, UnitConverter $converter, ProductionRunActionPolicy $policy): Response|RedirectResponse
    {
        $availability = $policy->availability($run, $request->user(), DocumentAction::Edit);

        if (! $availability->allowed) {
            return redirect()->route('inventory.production-runs.show', $run)->with('error', $availability->blockers[0]->message);
        }

        return $this->formPage($request, $converter, [
            'mode' => 'edit',
            'run' => ['id' => $run->id, 'run_number' => $run->run_number, 'form' => ProductionRunForm::of($run)],
        ]);
    }

    /**
     * ⭐ Corrections wave 1, B4: Correct a CONFIRMED run. The same form,
     * pre-filled; nothing is saved here: "Preview and correct" opens the
     * action dialog, which reverses the run on its own date and records the
     * right one in one go.
     */
    public function correct(Request $request, ProductionRun $run, UnitConverter $converter, ProductionRunActionPolicy $policy): Response|RedirectResponse
    {
        $availability = $policy->availability($run, $request->user(), DocumentAction::Correct);

        if (! $run->isConfirmed()) {
            return redirect()->route('inventory.production-runs.show', $run)->with('error', $availability->blockers[0]->message ?? "{$run->run_number} cannot be corrected.");
        }

        return $this->formPage($request, $converter, [
            'mode' => 'correct',
            'run' => ['id' => $run->id, 'run_number' => $run->run_number, 'form' => ProductionRunForm::of($run)],
            'availability' => $availability->toArray(),
            'correctionType' => ProductionRunActionPolicy::TYPE,
            'correctionReasons' => $this->reasons('correction'),
        ]);
    }

    /** ⭐ Corrections wave 1, B4: save an edited draft. */
    public function update(Request $request, ProductionRun $run, RecordProductionRun $action, ProductionRunActionPolicy $policy): RedirectResponse
    {
        $availability = $policy->availability($run, $request->user(), DocumentAction::Edit);

        if (! $availability->allowed) {
            throw ValidationException::withMessages(['run' => $availability->blockers[0]->message]);
        }

        $data = $request->validate(ProductionRunForm::rules((int) app(BusinessContext::class)->id()));

        try {
            $action->updateDraft($run, ...ProductionRunForm::arguments($data), editedBy: $request->user());
        } catch (PeriodClosedException|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['occurred_at' => $e->getMessage()]);
        } catch (RuntimeException|\DomainException $e) {
            throw ValidationException::withMessages(['run' => $e->getMessage()]);
        }

        return redirect()->route('inventory.production-runs.show', $run)->with('success', "Draft {$run->run_number} saved.");
    }

    /** @return list<array{id: int, name: string}> */
    private function reasons(string $context): array
    {
        return Reason::query()->active()->forContext($context)->orderBy('id')->get(['id', 'name'])
            ->map(fn (Reason $r) => ['id' => $r->id, 'name' => $r->name])->values()->all();
    }

    /** @param array<string, mixed> $extra */
    private function formPage(Request $request, UnitConverter $converter, array $extra = []): Response
    {
        // Live stock per product per warehouse, so the form can show what a
        // line can actually consume before confirm refuses it.
        $stock = StockBatch::query()
            ->where('status', StockBatchStatus::Active)
            ->where('current_quantity', '>', 0)
            // Traceability M2 (17 Oct 2026): a batch on hold is not available
            // to a run, so the form does not offer it.
            ->whereNull('held_at')
            ->selectRaw('product_id, warehouse_id, SUM(current_quantity) as quantity')
            ->groupBy('product_id', 'warehouse_id')
            ->get()
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->mapWithKeys(fn ($row) => [
                // ⚠ Normalised: an aggregate over a decimal column stringifies
                // as '100' on SQLite and '100.0000' on MySQL, and a screen
                // value must not change meaning with the driver underneath.
                (int) $row->warehouse_id => $this->trimDecimal((string) $row->quantity),
            ]));

        $warehouses = Warehouse::query()->operational()->where('is_active', true)
            ->orderBy('name')->get(['id', 'name', 'code', 'is_default']);

        $boms = BillOfMaterial::query()
            ->where('is_active', true)
            ->with(['lines.componentProduct.unitOfMeasure', 'outputProduct.unitOfMeasure'])
            ->get();

        // ⭐ PR3 (18 Oct 2026): what a run could take, batch by batch, in the
        // order it would take it. The screen prices its live cost preview from
        // these layers and the "can make" figure comes from their sum.
        $layers = MaxMakeable::layers();
        $available = MaxMakeable::available($layers);

        $units = UnitOfMeasure::query()->orderBy('name')->get();
        $today = Carbon::today();

        $products = Product::query()->where('is_active', true)->stocked()
            ->with('unitOfMeasure')
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'unit_of_measure_id', 'tracks_expiry', 'allows_fractional_stock']);

        $expenseAccounts = Account::query()
            ->where('type', AccountType::Expense)->where('is_postable', true)->where('is_active', true)
            ->whereNotIn('id', VoucherAccounts::refusedIds($today))
            ->orderBy('code')->get(['id', 'code', 'name']);

        return Inertia::render('inventory/production-runs/create', [
            'warehouses' => $warehouses->map->only(['id', 'name', 'code'])->values(),
            // 17 Oct 2026: the business's default warehouse first, the same rule
            // Opening stock uses. The first by name was a guess the operator had
            // to notice and correct on every run.
            'defaultWarehouseId' => $warehouses->firstWhere('is_default', true)?->id ?? $warehouses->first()?->id,
            'products' => $products->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'unit' => $product->unitOfMeasure?->abbreviation,
                'stock_unit_id' => $product->unit_of_measure_id,
                // The form marks the expiry date required for these, the
                // same rule draft() refuses by.
                'tracks_expiry' => (bool) $product->tracks_expiry,
                // ⭐ PR3: counted in whole jars/pcs, so the recipe fills UP.
                'whole_only' => MaxMakeable::wholeOnly($product),
                // ⭐ PR3: the units a line may be typed in, with a factor for
                // the screen's PREVIEW only; the server converts again on save.
                'units' => $converter->convertibleUnits($product, $today, $units)
                    ->map(fn (array $option) => [
                        'id' => $option['unit']->id,
                        'abbreviation' => $option['unit']->abbreviation,
                        'factor' => $option['factor'],
                    ])->values()->all(),
            ]),
            // PR3: ?product= from the Recipes page's "Make now".
            'initialOutputProductId' => $request->integer('product') ?: null,
            'stockByProduct' => $stock,
            // C-BOM-3. Suggested, then editable. The form pre-fills the
            // consumption lines from the active recipe for whichever output
            // product is chosen, and every line stays editable, removable and
            // joinable by lines the recipe never mentioned.
            //
            // ⚠ The raw recipe is sent, not a pre-scaled one: the operator
            // changes the output quantity after choosing the product, and a
            // suggestion computed for a quantity nobody has typed yet is a
            // suggestion for the wrong run. The RUN's stored standard is
            // recomputed server-side either way (C-BOM-2), so this is a
            // convenience and never the record.
            'bomsByOutputProduct' => $boms
                ->keyBy('output_product_id')
                ->map(fn (BillOfMaterial $bom) => [
                    'id' => $bom->id,
                    'version' => $bom->version,
                    'name' => $bom->name,
                    'output_quantity' => $bom->output_quantity,
                    'lines' => $bom->lines->map(fn ($line) => [
                        'product_id' => $line->component_product_id,
                        'quantity_per_output' => $line->quantity_per_output,
                        'wastage_percent' => $line->wastage_percent,
                    ])->all(),
                ]),
            // ⭐ PR3: "Can make N, limited by X", per recipe per warehouse,
            // from MaxMakeable so the Recipes page and this form agree.
            'canMake' => $boms->keyBy('output_product_id')->map(fn (BillOfMaterial $bom) => $warehouses
                ->mapWithKeys(fn (Warehouse $warehouse) => [$warehouse->id => MaxMakeable::forRecipe($bom, $available[$warehouse->id] ?? [])])
                ->all()),
            'costLayers' => $layers,
            'costKinds' => array_map(fn (ProductionCostKind $kind) => [
                'value' => $kind->value,
                'label' => $kind->label(),
                'account_id' => $kind->suggestedAccountCode() === null ? null : $expenseAccounts->firstWhere('code', $kind->suggestedAccountCode())?->id,
            ], ProductionCostKind::cases()),
            'expenseAccounts' => $expenseAccounts->map(fn (Account $a) => ['id' => $a->id, 'label' => $a->label()])->values(),
            ...$extra,
        ]);
    }

    /** `20.0000` → `20`, `20.5000` → `20.5`. Trims, never rounds. */
    private function trimDecimal(string $value): string
    {
        if (! str_contains($value, '.')) {
            return $value;
        }

        $trimmed = rtrim(rtrim($value, '0'), '.');

        return ($trimmed === '' || $trimmed === '-') ? '0' : $trimmed;
    }

    public function store(Request $request, RecordProductionRun $action): RedirectResponse
    {
        // ⚠⚠ Every id scoped to this business. A bare exists accepted another
        // business's id; the scoped lookups below then missed it and the page
        // died with a 500 instead of a sentence, or a foreign recipe was stored
        // on the run as its provenance.
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('business_id', $businessId)],
            'output_product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('business_id', $businessId)],
            'output_quantity' => ['required', 'numeric', 'gt:0'],
            // C-BOM-2. Provenance. The run does not read the recipe back; the
            // action copies the suggestion onto each line at this moment.
            'bill_of_material_id' => ['nullable', 'integer', Rule::exists('bills_of_material', 'id')->where('business_id', $businessId)],
            'output_expiry_date' => ['nullable', 'date'],
            'output_mrp' => ['nullable', 'numeric', 'min:0'],
            'expense_waste' => ['boolean'],
            // ⚠ MFC-003 (audit 14 Oct 2026): a day after today crashed the
            // stock engine into a blank 500; it is a sentence under the date
            // now. End of today (Dhaka), so a run dated today at any hour passes.
            'occurred_at' => ['required', 'date', 'before_or_equal:'.Carbon::today()->endOfDay()->toDateTimeString()],
            'note' => ['nullable', 'string'],
            'confirm' => ['boolean'],
            // P8 (18 Oct 2026): the operator saw the warning below and means it.
            'record_again' => ['boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('business_id', $businessId)],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.waste' => ['nullable', 'numeric', 'min:0'],
            // ⭐ Phase 1 Part B (17 Oct 2026): the unit quantity and waste are
            // typed in; blank = the product's stock unit. The run freezes the factor.
            'lines.*.unit_id' => ['nullable', 'integer', Rule::exists('units_of_measure', 'id')->where('business_id', $businessId)],
            // ⭐ PR3: optional labour, overhead and other costs, in taka.
            'costs' => ['nullable', 'array'],
            'costs.*.kind' => ['required', Rule::enum(ProductionCostKind::class)],
            // The chart is one per installation (Account has no business), so
            // existence is the check here; the action refuses a non-expense head.
            'costs.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')],
            'costs.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'costs.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        // ⚠ Bug hunt 16 Oct 2026: the output's expiry date had no check, so a
        // typo (2025 for 2027) made a batch already out of date that the
        // nightly sweep then proposed to write off. Same label checks as a
        // goods receipt, with the run's own day as the day it was made.
        $dateProblems = ReceivedBatchDates::problems(Carbon::parse($data['occurred_at'])->toDateString(), $data['output_expiry_date'] ?? null);

        if (isset($dateProblems['expiry_date'])) {
            throw ValidationException::withMessages([
                'output_expiry_date' => str_contains($dateProblems['expiry_date'], 'before the manufacture date')
                    ? 'The expiry date is before the day of the run. Check the year.'
                    : 'That expiry date has already passed, so the output would be out of date the day it is made. Check the year.',
            ]);
        }

        $this->refuseRepeat($data, $request);

        $products = Product::query()
            ->with('unitOfMeasure')
            ->whereIn('id', collect($data['lines'])->pluck('product_id')->push($data['output_product_id']))
            ->get()->keyBy('id');

        $unitIds = collect($data['lines'])->pluck('unit_id')->filter()->unique();
        $units = $unitIds->isEmpty() ? collect() : UnitOfMeasure::query()->whereIn('id', $unitIds)->get()->keyBy('id');

        try {
            $run = $this->draft($action, $data, $products, $units, $request);
        } catch (PeriodClosedException|InvalidArgumentException $e) {
            // ⚠ MFC-003 / MFC-004 (audit 14 Oct 2026): a date the books or the
            // stock refuse (a closed month, a day not yet come) is said under
            // the date. A closed month used to reach the global safety net as
            // `errors.ledger`, a key this page never renders, so the button
            // seemed to do nothing.
            throw ValidationException::withMessages(['occurred_at' => $e->getMessage()]);
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['run' => $e->getMessage()]);
        }

        // D-MFG-4: one-step confirm by the authorised user, straight from the
        // form. The draft state exists for save-and-check, not as an approval.
        if ($data['confirm'] ?? false) {
            try {
                $run = $action->confirm($run, $request->user());
            } catch (RuntimeException|InvalidArgumentException|\DomainException $e) {
                // ⚠ The draft survives - the typing is not lost to a stock
                // shortfall - and the refusal reads as a sentence on its page.
                // ⚠⚠ This caught only InsufficientStockException, so a closed
                // month or a missing wastage mapping escaped as a blank 500 on
                // the one-step confirm while the identical refusal on the
                // separate confirm button below was already readable. Widened to
                // match: InsufficientStockException is itself a RuntimeException,
                // so this is a superset of what it caught before.
                return redirect()
                    ->route('inventory.production-runs.show', $run)
                    ->withErrors(['run' => $e->getMessage()]);
            }
        }

        return redirect()
            ->route('inventory.production-runs.show', $run)
            ->with('success', "Production run {$run->run_number} ".($run->isConfirmed() ? 'confirmed.' : 'saved as draft.'));
    }

    /**
     * ⚠⚠ P8 (18 Oct 2026, practice run): "Record and confirm run" confirmed the
     * run, the page then showed nothing (a browser history fault, fixed in
     * lib/history-state-guard.ts), and the operator pressed again: two drafts,
     * PRN-0003 and PRN-0004. The double-submit guard only holds a second press
     * while the first is in flight; this one came after it finished. So the
     * same run by the same person (output, quantity, warehouse, day) within two
     * minutes is refused with its number, unless they say "record it again".
     *
     * @param  array<string, mixed>  $data
     */
    private function refuseRepeat(array $data, Request $request): void
    {
        if ($data['record_again'] ?? false) {
            return;
        }

        $day = Carbon::parse($data['occurred_at'])->toDateString();

        $twin = ProductionRun::query()
            ->where('created_by', $request->user()->id)
            ->where('output_product_id', $data['output_product_id'])
            ->where('warehouse_id', $data['warehouse_id'])
            ->whereDate('occurred_at', $day)
            ->where('created_at', '>=', now()->subMinutes(2))
            ->latest('id')
            ->get(['id', 'run_number', 'output_quantity', 'status'])
            // Compared as numbers: '20' typed against '20.0000' stored.
            ->first(fn (ProductionRun $run) => bccomp((string) $run->output_quantity, (string) $data['output_quantity'], 4) === 0);

        if ($twin !== null) {
            throw ValidationException::withMessages([
                'repeat' => "You recorded this same run as {$twin->run_number} ({$twin->status->value}) less than two minutes ago. "
                    .'Open it to check, or record it again if this really is a second run.',
                'repeat_run_id' => (string) $twin->id,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function draft(RecordProductionRun $action, array $data, $products, $units, Request $request): ProductionRun
    {
        return $action->draft(
            warehouse: Warehouse::findOrFail($data['warehouse_id']),
            outputProduct: $products[$data['output_product_id']],
            outputQuantity: (string) $data['output_quantity'],
            lines: collect($data['lines'])->map(fn (array $line) => [
                'product' => $products[$line['product_id']],
                'quantity' => (string) $line['quantity'],
                'waste' => (string) ($line['waste'] ?? '0'),
                'unit' => empty($line['unit_id']) ? null : ($units[(int) $line['unit_id']] ?? null),
            ])->all(),
            createdBy: $request->user(),
            occurredAt: $data['occurred_at'],
            outputExpiryDate: $data['output_expiry_date'] ?? null,
            outputMrp: isset($data['output_mrp']) ? (string) $data['output_mrp'] : null,
            expenseWaste: (bool) ($data['expense_waste'] ?? false),
            note: $data['note'] ?? null,
            billOfMaterial: isset($data['bill_of_material_id'])
                ? BillOfMaterial::with('lines')->find($data['bill_of_material_id'])
                : null,
            costs: $this->costs($data['costs'] ?? []),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $costs
     * @return list<array{kind: string, account: ?Account, amount: string, note: ?string}>
     */
    private function costs(array $costs): array
    {
        $accounts = Account::query()->whereIn('id', collect($costs)->pluck('account_id'))->get()->keyBy('id');

        return collect($costs)->map(fn (array $cost) => [
            'kind' => (string) $cost['kind'],
            'account' => $accounts[(int) $cost['account_id']] ?? null,
            'amount' => (string) $cost['amount'],
            'note' => $cost['note'] ?? null,
        ])->values()->all();
    }

    public function show(ProductionRun $run, PostProductionRunJournal $posting): Response
    {
        $run->load([
            'lines.product.unitOfMeasure',
            'lines.enteredUnit:id,abbreviation',
            'billOfMaterial:id,version,name',
            'warehouse:id,name',
            'outputProduct.unitOfMeasure',
            'outputBatch',
            'movements.batch:id,batch_number,received_at',
            'movements.product:id,name,sku',
            'createdBy:id,name',
            'confirmedBy:id,name',
            'reversedBy:id,name',
            'costs.account:id,code,name',
        ]);

        $journal = $run->isDraft() ? null : $posting->existingJournal($run);

        return Inertia::render('inventory/production-runs/show', [
            'run' => [
                'id' => $run->id,
                'run_number' => $run->run_number,
                'status' => $run->status->value,
                'warehouse' => $run->warehouse?->name,
                'output_product' => $run->outputProduct?->name,
                'output_sku' => $run->outputProduct?->sku,
                'output_unit' => $run->outputProduct?->unitOfMeasure?->abbreviation,
                'output_quantity' => $run->output_quantity,
                'bill_of_material' => $run->billOfMaterial === null ? null : [
                    'id' => $run->billOfMaterial->id,
                    'version' => $run->bill_of_material_version ?? $run->billOfMaterial->version,
                    'name' => $run->billOfMaterial->name,
                ],
                'output_expiry_date' => $run->output_expiry_date?->toDateString(),
                'output_mrp' => $run->output_mrp,
                'expense_waste' => $run->expense_waste,
                'consumed_value' => $run->consumed_value,
                'waste_value' => $run->waste_value,
                // PR3: the cost breakdown the batch was valued at.
                'additional_cost_value' => $run->additional_cost_value,
                'output_value' => $run->output_value,
                'costs' => $run->costs->map(fn ($cost) => [
                    'id' => $cost->id,
                    'kind' => $cost->kind->value,
                    'kind_label' => $cost->kind->label(),
                    'account' => $cost->account?->label(),
                    'amount' => $cost->amount()->toTaka(),
                    'note' => $cost->note,
                ])->values(),
                'yield_check' => $run->yield_check,
                'occurred_at' => $run->occurred_at?->toDateTimeString(),
                'note' => $run->note,
                'created_by' => $run->createdBy?->name,
                'confirmed_by' => $run->confirmedBy?->name,
                'confirmed_at' => $run->confirmed_at?->toDateTimeString(),
                'reversed_by' => $run->reversedBy?->name,
                'reversed_at' => $run->reversed_at?->toDateTimeString(),
                'output_batch' => $run->outputBatch === null ? null : [
                    // Traceability (17 Oct 2026): the batch page and the label sheet.
                    'id' => $run->outputBatch->id,
                    'held' => $run->outputBatch->isHeld(),
                    'batch_number' => $run->outputBatch->batch_number,
                    'unit_cost' => $run->outputBatch->unit_cost,
                    'manufactured_date' => $run->outputBatch->manufactured_date?->toDateString(),
                    'expiry_date' => $run->outputBatch->expiry_date?->toDateString(),
                    'mrp' => $run->outputBatch->mrp,
                    'current_quantity' => $run->outputBatch->current_quantity,
                ],
                'lines' => $run->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'product' => $line->product?->name,
                    'sku' => $line->product?->sku,
                    'unit' => $line->product?->unitOfMeasure?->abbreviation,
                    'quantity_consumed' => $line->quantity_consumed,
                    // Phase 1 Part B: what was typed and the frozen factor.
                    'entered_quantity' => $line->entered_quantity,
                    'entered_unit' => $line->enteredUnit?->abbreviation,
                    'conversion_factor' => $line->conversion_factor,
                    // C-BOM-3. The whole point of having a standard: where the
                    // operator departed from it, and by how much. Null means
                    // the recipe never mentioned this component.
                    'standard_quantity' => $line->standard_quantity,
                    'waste_quantity' => $line->waste_quantity,
                    'consumed_value' => $line->consumed_value,
                    'waste_value' => $line->waste_value,
                ]),
                'movements' => $run->movements->map(fn ($movement) => [
                    'id' => $movement->id,
                    'product' => $movement->product?->name,
                    'direction' => $movement->direction->value,
                    'quantity' => $movement->quantity,
                    'unit_cost' => $movement->unit_cost,
                    'batch_number' => $movement->batch?->batch_number,
                    'batch_id' => $movement->batch_id,
                    'reverses_movement_id' => $movement->reverses_movement_id,
                    'occurred_at' => $movement->occurred_at?->toDateTimeString(),
                ]),
            ],
            'journal' => $journal === null ? null : [
                'id' => $journal->id,
                'journal_number' => $journal->journal_number,
            ],
            // ⭐ Corrections wave 1 (20 Oct 2026): Edit on a draft, Correct and
            // Reverse on a confirmed run, from its policy (with the reasons a
            // greyed button shows); Delete stays the draft's own button.
            'actions' => app(ProductionRunActionPolicy::class)->actionsFor($run, request()->user())->toArray()['actions'],
            'correctionType' => ProductionRunActionPolicy::TYPE,
            'reasons' => ['reversal' => $this->reasons('reversal'), 'deletion' => $this->reasons('deletion')],
            'correction' => [
                'reversal_reason' => $run->reversal_reason,
                'reversed_on' => $run->reversed_on?->toDateString(),
                'corrected_by' => CorrectionChain::linkAsOriginal($run)?->successor_number,
                'corrected_by_id' => CorrectionChain::linkAsOriginal($run)?->successor_id,
                'correction_of' => CorrectionChain::correctionOfLabel($run),
            ],
            // The reverse button is offered only when the reversal would not be
            // refused - the refusal still stands server-side either way.
            'canReverse' => $run->isConfirmed()
                && $run->outputBatch !== null
                && (float) $run->outputBatch->current_quantity === (float) $run->outputBatch->initial_quantity,
        ]);
    }

    public function confirm(ProductionRun $run, Request $request, RecordProductionRun $action): RedirectResponse
    {
        try {
            $action->confirm($run, $request->user());
        } catch (RuntimeException|InvalidArgumentException|\DomainException $e) {
            // ⚠ MFC-005 (audit 14 Oct 2026): the same catch as the one-step
            // confirm in store(). A run dated before its input batch arrived
            // (InvalidArgumentException) or a product turned into a service
            // since the draft (DomainException) was a blank 500 here while the
            // one-step path already said it. InsufficientStockException is a
            // RuntimeException, so nothing caught before is lost.
            throw ValidationException::withMessages(['run' => $e->getMessage()]);
        }

        return back()->with('success', "Production run {$run->run_number} confirmed.");
    }

    /**
     * ⛔ Corrections wave 1 (20 Oct 2026): retired. A run is reversed through
     * the corrections process now (the run page's Reverse), which asks for a
     * reason and lands on the run's own date; this bare button did neither.
     */
    public function reverse(ProductionRun $run): RedirectResponse
    {
        throw ValidationException::withMessages([
            'run' => "Use Reverse on the run page: it asks for a reason and lands on the run's own date.",
        ]);
    }

    public function destroy(Request $request, ProductionRun $run): RedirectResponse
    {
        // ⭐ Corrections wave 1 (20 Oct 2026): a reason, on the server, and
        // the Corrections report keeps it (FINAL-PLAN section 2: every action
        // but editing a draft says why). Number and reason outlive the row.
        $request->validate($this->actionReasonRules('deletion'), $this->actionReasonMessages());

        // ⚠ Drafts only. A confirmed run wrote movements and possibly a journal;
        // its correction path is the reversal, never deletion (C-MFG-8).
        if (! $run->isDraft()) {
            throw ValidationException::withMessages([
                'run' => "Production run {$run->run_number} is {$run->status->value} and can only be reversed, not deleted.",
            ]);
        }

        CorrectionLog::event('deleted', $request->user(), $run, [
            'number' => $run->run_number,
            'reason' => $this->actionReason($request)->display(),
        ]);

        $run->lines()->delete();
        $run->costs()->delete();
        $run->delete();

        return redirect()
            ->route('inventory.production-runs.index')
            ->with('success', 'Draft deleted.');
    }
}
