<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Actions\AuthoriseOwnerWithdrawal;
use App\Modules\Accounting\Actions\PostOwnerWithdrawal;
use App\Modules\Accounting\Actions\ReopenOwnerWithdrawal;
use App\Modules\Accounting\Actions\SaveOwnerWithdrawal;
use App\Modules\Accounting\Corrections\OwnerWithdrawalActionPolicy;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\OwnerWithdrawal;
use App\Modules\Accounting\Models\OwnerWithdrawalLine;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\OwnerWithdrawalPricing;
use App\Modules\Accounting\Support\OwnerWithdrawalsMonthlyReport;
use App\Modules\Accounting\Support\Proprietor;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Support\RefusalMessage;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * Accounts > Vouchers > Owner withdrawals (Phase 2, 17 Oct 2026).
 *
 * One list, a popup to add or edit a draft (house rule), the batch-cost
 * preview per line on every draft, the frozen batch and cost on an
 * authorised one, and the decisions as buttons: authorise (a second person,
 * or the proprietor with a note), send back, post. A monthly report of what
 * left, at cost, against the reference price (D2, D8). Reading sits behind
 * accounting.owner_withdrawal.view on the route; writing and posting behind
 * .manage; authorising behind the action's own rule.
 */
class OwnerWithdrawalController extends Controller
{
    public function index(Request $request, OwnerWithdrawalPricing $pricing): Response
    {
        $user = $request->user();
        $business = Business::withoutGlobalScopes()->findOrFail(app(BusinessContext::class)->id());
        $proprietor = Proprietor::userFor($business);
        $month = $request->string('month')->toString();
        $monthDetail = preg_match('/^\d{4}-\d{2}$/', $month) === 1 ? OwnerWithdrawalsMonthlyReport::month($month) : null;

        $withdrawals = OwnerWithdrawal::query()
            // ⚠ `mrp` and `type` must be in the select: the estimate's reference price reads them.
            ->with(['lines.product:id,name,sku,mrp,type,unit_of_measure_id', 'lines.product.unitOfMeasure:id,abbreviation', 'lines.batch:id,batch_number',
                'warehouse:id,name', 'proprietor:id,name', 'authorisedBy:id,name', 'postedBy:id,name', 'createdBy:id,name', 'journal:id,journal_number', 'reversedBy:id,name', 'reversalJournal:id,journal_number'])
            ->latest('withdrawn_on')->latest('id')
            ->limit(100)->get()
            ->map(fn (OwnerWithdrawal $w) => $this->present($w, $pricing) + [
                // ⭐ B24 (25 Oct 2026): Reverse a posted withdrawal, from its policy.
                'actions' => app(OwnerWithdrawalActionPolicy::class)->actionsFor($w, $user)->toArray()['actions'],
            ])
            ->values();

        $today = Carbon::today();

        return Inertia::render('accounting/owner-withdrawals/index', [
            'withdrawals' => $withdrawals,
            'filters' => ['new' => $request->boolean('new'), 'month' => $monthDetail === null ? null : $month],
            'months' => OwnerWithdrawalsMonthlyReport::months(),
            'monthDetail' => $monthDetail,
            'warehouses' => Warehouse::query()->operational()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default'])->all(),
            'products' => Product::query()
                ->where('is_active', true)->whereIn('type', ['raw_material', 'finished_good'])
                ->with('unitOfMeasure:id,abbreviation')->orderBy('name')
                ->get(['id', 'name', 'sku', 'mrp', 'unit_of_measure_id'])
                ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'unit' => $p->unitOfMeasure?->abbreviation, 'mrp' => $p->mrp === null ? null : (string) $p->mrp])
                ->values()->all(),
            'proprietor' => $proprietor === null ? null : ['id' => $proprietor->id, 'name' => $proprietor->name],
            'isProprietor' => $proprietor !== null && (int) $proprietor->id === (int) $user?->id,
            'drawingsMapped' => AccountMapping::forKeyOn('inventory.owner_withdrawal', $today) !== null,
            'today' => $today->toDateString(),
            'canManage' => (bool) $user?->can('accounting.owner_withdrawal.manage'),
            // ⚠ P21 (18 Oct 2026): the permission tables only, never the god-mode gate.
            'canAuthorise' => AuthoriseOwnerWithdrawal::holdsPermission($user),
            'canPrepareVoucher' => (bool) $user?->can('accounting.voucher.prepare'),
            'reversalReasons' => Reason::query()->where('context', 'reversal')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, SaveOwnerWithdrawal $save): RedirectResponse
    {
        $data = $this->validated($request);
        $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);

        try {
            $withdrawal = $save->handle($warehouse, $data['withdrawn_on'], $data['lines'], $request->user(), $data['note'] ?? null);
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages(['lines' => RefusalMessage::of($e)]);
        }

        return redirect()->route('accounting.owner-withdrawals.index')
            ->with('success', "Owner personal withdrawal {$withdrawal->number} saved as a draft. Check the estimated cost, then authorise it.");
    }

    public function update(Request $request, OwnerWithdrawal $ownerWithdrawal, SaveOwnerWithdrawal $save): RedirectResponse
    {
        $data = $this->validated($request);
        $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);

        try {
            $save->handle($warehouse, $data['withdrawn_on'], $data['lines'], $request->user(), $data['note'] ?? null, $ownerWithdrawal);
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages(['lines' => RefusalMessage::of($e)]);
        }

        return back()->with('success', "Owner personal withdrawal {$ownerWithdrawal->number} saved.");
    }

    public function destroy(OwnerWithdrawal $ownerWithdrawal): RedirectResponse
    {
        if (! $ownerWithdrawal->isEditable()) {
            return back()->with('error', "Owner personal withdrawal {$ownerWithdrawal->number} has been authorised and cannot be deleted. Send it back to draft first.");
        }

        $ownerWithdrawal->delete();

        return back()->with('success', "Draft {$ownerWithdrawal->number} deleted.");
    }

    public function authorise(Request $request, OwnerWithdrawal $ownerWithdrawal, AuthoriseOwnerWithdrawal $authorise): RedirectResponse
    {
        $data = $request->validate(['authorisation_note' => ['nullable', 'string', 'max:500']]);

        try {
            $ownerWithdrawal = $authorise->handle($ownerWithdrawal, $request->user(), $data['authorisation_note'] ?? null);
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', RefusalMessage::of($e));
        }

        return back()->with('success', "Owner personal withdrawal {$ownerWithdrawal->number} authorised at ".Money::ofMinor((int) $ownerWithdrawal->cost_total_paisa)->format().' (batch cost). Post it when the goods are handed over.');
    }

    public function reopen(OwnerWithdrawal $ownerWithdrawal, ReopenOwnerWithdrawal $reopen): RedirectResponse
    {
        try {
            $reopen->handle($ownerWithdrawal);
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', RefusalMessage::of($e));
        }

        return back()->with('success', "Owner personal withdrawal {$ownerWithdrawal->number} is back in draft; its stock holds are released.");
    }

    public function post(Request $request, OwnerWithdrawal $ownerWithdrawal, PostOwnerWithdrawal $post): RedirectResponse
    {
        try {
            $ownerWithdrawal = $post->handle($ownerWithdrawal, $request->user());
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', RefusalMessage::of($e));
        }

        return back()->with('success', "Owner personal withdrawal {$ownerWithdrawal->number} posted: stock is out and Owner's Drawings carries ".$ownerWithdrawal->costTotal()->format().'.');
    }

    /** The batch-cost estimate of one line while it is typed, for the popup. */
    public function estimate(Request $request, OwnerWithdrawalPricing $pricing): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);
        $product = Product::query()->findOrFail($data['product_id']);
        $quantity = number_format((float) $data['quantity'], 4, '.', '');

        $estimate = $pricing->estimate($product, $warehouse, $quantity);
        $reference = $pricing->reference($product, $quantity, Carbon::today());

        return response()->json([
            'unit_cost' => $estimate['portions'][0]['unit_cost'] ?? null,
            'cost' => Money::ofMinor($estimate['cost_paisa'])->toTaka(),
            'short' => $estimate['short'],
            'portions' => $estimate['portions'],
            'reference_source' => $reference['source'],
            'reference_price' => $reference['price'],
            'reference' => $reference['price'] === null ? null : Money::ofProduct($quantity, $reference['price'])->toTaka(),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'withdrawn_on' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(OwnerWithdrawal $w, OwnerWithdrawalPricing $pricing): array
    {
        $lines = $w->lines->map(function (OwnerWithdrawalLine $line) use ($w, $pricing) {
            $estimate = null;

            if ($w->isDraft() && $line->product !== null && $w->warehouse !== null) {
                $raw = $pricing->estimate($line->product, $w->warehouse, (string) $line->quantity);
                $reference = $pricing->reference($line->product, (string) $line->quantity, Carbon::today());
                $estimate = [
                    'unit_cost' => $raw['portions'][0]['unit_cost'] ?? null,
                    'cost' => Money::ofMinor($raw['cost_paisa'])->toTaka(),
                    'short' => $raw['short'],
                    'batches' => count($raw['portions']),
                    'reference_source' => $reference['source'],
                    'reference_price' => $reference['price'],
                    // ⭐ P20 (practice 18 Oct 2026): the draft shows the LINE
                    // totals for reference and difference, as the authorised
                    // row does, not a bare unit figure ("890 MRP").
                    'reference' => $referenceTotal = $reference['price'] === null ? null : Money::ofProduct((string) $line->quantity, $reference['price'])->toTaka(),
                    'difference' => $referenceTotal === null ? null : Money::ofTaka($referenceTotal)->plus(Money::ofMinor($raw['cost_paisa'])->negated())->toTaka(),
                ];
            }

            return [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'product' => $line->product?->name,
                'sku' => $line->product?->sku,
                'unit' => $line->product?->unitOfMeasure?->abbreviation,
                'quantity' => (string) $line->quantity,
                'batch_id' => $line->stock_batch_id,
                'batch_number' => $line->batch?->batch_number,
                'unit_cost' => $line->unit_cost === null ? null : (string) $line->unit_cost,
                'cost' => $line->cost()->toTaka(),
                'reference_source' => $line->reference_source,
                'reference_price' => $line->reference_price === null ? null : (string) $line->reference_price,
                'reference' => $line->reference()?->toTaka(),
                'difference' => $line->difference()?->toTaka(),
                'note' => $line->note,
                'estimate' => $estimate,
            ];
        })->values()->all();

        return [
            'id' => $w->id,
            'number' => $w->number,
            'status' => $w->status->value,
            'editable' => $w->isEditable(),
            'withdrawn_on' => $w->withdrawn_on?->toDateString(),
            'note' => $w->note,
            'proprietor' => $w->proprietor?->name,
            'warehouse_id' => $w->warehouse_id,
            'warehouse' => $w->warehouse?->name,
            'authorised_by' => $w->authorisedBy?->name,
            'authorised_at' => $w->authorised_at?->toDateTimeString(),
            'authorisation_note' => $w->authorisation_note,
            'self_authorised' => (bool) $w->self_authorised,
            'reapproval_reason' => $w->reapproval_reason,
            'cost_total' => $w->costTotal()->toTaka(),
            'reference_total' => $w->referenceTotal()?->toTaka(),
            'difference_total' => $w->differenceTotal()?->toTaka(),
            'journal_number' => $w->journal?->journal_number,
            'posted_at' => $w->posted_at?->toDateString(),
            'posted_by' => $w->postedBy?->name,
            'created_by' => $w->createdBy?->name,
            'lines' => $lines,
            'reversal' => $w->isReversed() ? [
                'on' => $w->reversed_on?->toDateString(),
                'by' => $w->reversedBy?->name,
                'reason' => $w->reversal_reason,
                'journal' => $w->reversalJournal?->journal_number,
            ] : null,
        ];
    }
}
