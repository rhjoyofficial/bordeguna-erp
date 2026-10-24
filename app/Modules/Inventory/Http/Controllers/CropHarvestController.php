<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Actions\RejectApprovable;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\ApproveCropWriteOff;
use App\Modules\Inventory\Actions\RaiseCropWriteOff;
use App\Modules\Inventory\Actions\RecordCropHarvest;
use App\Modules\Inventory\Corrections\CropHarvestActionPolicy;
use App\Modules\Inventory\Corrections\CropWriteOffActionPolicy;
use App\Modules\Inventory\Models\CropHarvest;
use App\Modules\Inventory\Models\CropWriteOff;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\CropHeads;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * ⭐ Farm harvest (B9, 24 Oct 2026): Inventory > Production > Farm harvest.
 *
 * One page: what each Crops in Progress head holds, the harvests and the
 * crop failure write-offs, with "Record harvest" and "Crop failure write-off"
 * as popups (Joy's rule: add in a popup, never a form above the list).
 *
 * Reverse on either goes through the corrections engine's own routes
 * (/records/{type}/{id}/reverse); approving a write-off is the Owner's, in
 * Approvals or here.
 */
class CropHarvestController extends Controller
{
    public function __construct(
        private readonly CropHarvestActionPolicy $harvestPolicy,
        private readonly CropWriteOffActionPolicy $writeOffPolicy,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $harvests = CropHarvest::query()
            ->with(['lines.product.unitOfMeasure', 'cropAccount', 'warehouse:id,name', 'createdBy:id,name', 'reversedBy:id,name', 'journal:id,journal_number'])
            ->latest('harvested_on')->latest('id')->limit(100)->get()
            ->map(fn (CropHarvest $h) => [
                'id' => $h->id,
                'number' => $h->number,
                'harvested_on' => $h->harvested_on?->toDateString(),
                'head' => $h->cropAccount?->label(),
                'warehouse' => $h->warehouse?->name,
                'amount' => $h->amount()->toTaka(),
                'journal' => $h->journal?->journal_number,
                'note' => $h->note,
                'by' => $h->createdBy?->name,
                'lines' => $h->lines->map(fn ($line) => [
                    'product' => $line->product?->name,
                    'main' => $line->is_main,
                    'quantity' => (string) $line->quantity,
                    'unit' => $line->product?->unitOfMeasure?->abbreviation,
                    'cost' => $line->cost()->toTaka(),
                    'unit_cost' => (string) $line->unit_cost,
                    'batch' => $line->batch_number,
                ])->values(),
                'reversal' => $h->reversalSummary(),
                'actions' => $this->harvestPolicy->actionsFor($h, $user)->toArray()['actions'],
            ]);

        $writeOffs = CropWriteOff::query()
            ->with(['cropAccount', 'lossAccount', 'createdBy:id,name', 'approvedBy:id,name', 'reversedBy:id,name', 'journal:id,journal_number', 'approvalRequest'])
            ->latest('written_off_on')->latest('id')->limit(100)->get()
            ->map(fn (CropWriteOff $w) => [
                'id' => $w->id,
                'number' => $w->number,
                'written_off_on' => $w->written_off_on?->toDateString(),
                'head' => $w->cropAccount?->label(),
                'loss_head' => $w->lossAccount?->label(),
                'amount' => $w->amount()->toTaka(),
                'reason' => $w->reason_text,
                'status' => $w->isReversed() ? 'reversed' : $w->status,
                'journal' => $w->journal?->journal_number,
                'by' => $w->createdBy?->name,
                'approved_by' => $w->approvedBy?->name,
                'can_decide' => $w->isWaiting() && $w->approvalRequest !== null && $w->approvalRequest->canBeActionedBy($user),
                'self_approval' => $w->isWaiting() && $w->approvalRequest !== null && $w->approvalRequest->isSelfApprovalBy($user),
                'reversal' => $w->reversalSummary(),
                'actions' => $this->writeOffPolicy->actionsFor($w, $user)->toArray()['actions'],
            ]);

        $lossHeads = Account::query()->postable()->where('type', AccountType::Expense->value)->inChartOrder()->get(['id', 'code', 'name']);

        return Inertia::render('inventory/harvests/index', [
            'harvests' => $harvests,
            'writeOffs' => $writeOffs,
            'focus' => $request->integer('write_off') ?: null,
            'heads' => CropHeads::query()->get()->map(fn (Account $a) => [
                'id' => $a->id,
                'label' => $a->label(),
                'balance' => Money::ofMinor(CropHeads::balanceMinor($a))->toTaka(),
            ])->values(),
            'lossHeads' => $lossHeads->map(fn (Account $a) => ['id' => $a->id, 'label' => $a->label()])->values(),
            // The farm guide's head, chosen for the person when it exists.
            'defaultLossHeadId' => $lossHeads->firstWhere('code', '5210')?->id,
            'warehouses' => Warehouse::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name']),
            'products' => Product::query()->with('unitOfMeasure:id,abbreviation')->orderBy('name')->get()
                ->filter(fn (Product $p) => $p->type->isStocked())
                ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'unit_id' => $p->unit_of_measure_id, 'unit' => $p->unitOfMeasure?->abbreviation, 'tracks_expiry' => (bool) $p->tracks_expiry])
                ->values(),
            'units' => UnitOfMeasure::query()->orderBy('name')->get(['id', 'name', 'abbreviation']),
            'reasons' => Reason::query()->active()->forContext('reversal')->orderBy('id')->get(['id', 'name'])
                ->map(fn (Reason $r) => ['id' => $r->id, 'name' => $r->name])->values(),
            'types' => ['harvest' => CropHarvestActionPolicy::TYPE, 'writeOff' => CropWriteOffActionPolicy::TYPE],
            'headCode' => CropHeads::CODE,
        ]);
    }

    public function store(Request $request, RecordCropHarvest $record): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'harvested_on' => ['required', 'date'],
            'crop_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('business_id', $businessId)],
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'lines' => ['required', 'array', 'min:1', 'max:10'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('business_id', $businessId)],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_id' => ['nullable', 'integer', Rule::exists('units_of_measure', 'id')],
            'lines.*.cost' => ['nullable', 'numeric'],
            'lines.*.batch_number' => ['nullable', 'string', 'max:100'],
            'lines.*.expiry_date' => ['nullable', 'date'],
        ], [
            'harvested_on.required' => 'Type the harvest date.',
            'crop_account_id.required' => 'Choose the Crops in Progress head.',
            'lines.*.product_id.required' => 'Choose the product.',
            'lines.*.quantity.gt' => 'The quantity must be greater than zero.',
        ]);

        $products = Product::query()->whereIn('id', collect($data['lines'])->pluck('product_id'))->get()->keyBy('id');
        $units = UnitOfMeasure::query()->whereIn('id', collect($data['lines'])->pluck('unit_id')->filter())->get()->keyBy('id');

        try {
            $harvest = $record->handle(
                harvestedOn: $data['harvested_on'],
                cropAccount: Account::query()->findOrFail($data['crop_account_id']),
                warehouse: Warehouse::query()->findOrFail($data['warehouse_id']),
                lines: collect($data['lines'])->map(fn (array $line) => [
                    'product' => $products[$line['product_id']],
                    'quantity' => (string) $line['quantity'],
                    'unit' => isset($line['unit_id']) ? $units[$line['unit_id']] ?? null : null,
                    'cost' => isset($line['cost']) ? (string) $line['cost'] : null,
                    'batchNumber' => $line['batch_number'] ?? null,
                    'expiryDate' => $line['expiry_date'] ?? null,
                ])->values()->all(),
                createdBy: $request->user(),
                amount: isset($data['amount']) ? (string) $data['amount'] : null,
                note: $data['note'] ?? null,
                idempotencyKey: $data['idempotency_key'] ?? null,
            );
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages(['harvest' => $e->getMessage()]);
        }

        return back()->with('success', "Harvest {$harvest->number} recorded: Tk {$harvest->amount()->format()} moved into stock.");
    }

    public function storeWriteOff(Request $request, RaiseCropWriteOff $raise): RedirectResponse
    {
        $data = $request->validate([
            'written_off_on' => ['required', 'date'],
            'crop_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')],
            'loss_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason_text' => ['required', 'string', 'min:5', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ], [
            'written_off_on.required' => 'Type the date of the loss.',
            'reason_text.required' => 'Say why the crop failed.',
            'reason_text.min' => 'Say why in at least 5 characters.',
        ]);

        try {
            $writeOff = $raise->handle(
                writtenOffOn: $data['written_off_on'],
                cropAccount: Account::query()->findOrFail($data['crop_account_id']),
                lossAccount: Account::query()->findOrFail($data['loss_account_id']),
                amount: (string) $data['amount'],
                reason: $data['reason_text'],
                raisedBy: $request->user(),
                idempotencyKey: $data['idempotency_key'] ?? null,
            );
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages(['write_off' => $e->getMessage()]);
        }

        return back()->with('success', "Crop write-off {$writeOff->number} sent to the Owner for approval.");
    }

    public function approve(Request $request, CropWriteOff $writeOff, ApproveCropWriteOff $approve): RedirectResponse
    {
        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:1000']]);

        try {
            $writeOff = $approve->handle($writeOff, $request->user(), $data['review_note'] ?? null);
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $writeOff->isApproved()
            ? "Crop write-off {$writeOff->number} approved and posted."
            : "Crop write-off {$writeOff->number} approved at this stage.");
    }

    public function reject(Request $request, CropWriteOff $writeOff, ApproveCropWriteOff $approve, RejectApprovable $reject): RedirectResponse
    {
        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:1000']]);

        try {
            $writeOff = $approve->reject($writeOff, $request->user(), $data['review_note'] ?? null, $reject);
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Crop write-off {$writeOff->number} rejected. Nothing was posted.");
    }
}
