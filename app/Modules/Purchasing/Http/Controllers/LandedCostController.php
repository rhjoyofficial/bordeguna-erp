<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\VoucherAccounts;
use App\Modules\Foundation\Actions\RejectApprovable;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Purchasing\Actions\ApproveLandedCost;
use App\Modules\Purchasing\Actions\PlanLandedCostAllocation;
use App\Modules\Purchasing\Actions\PostLandedCost;
use App\Modules\Purchasing\Actions\SaveLandedCost;
use App\Modules\Purchasing\Actions\SubmitLandedCost;
use App\Modules\Purchasing\Corrections\LandedCostActionPolicy;
use App\Modules\Purchasing\Enums\LandedCostAllocationMethod;
use App\Modules\Purchasing\Enums\LandedCostTreatment;
use App\Modules\Purchasing\Enums\LandedCostType;
use App\Modules\Purchasing\Exceptions\PurchaseApprovalRequired;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\LandedCostLine;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Support\LandedCostDoubleCount;
use App\Modules\Purchasing\Support\PurchasingApprovalGate;
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
 * Purchases > Purchase orders > Landed costs (Phase 1, 17 Oct 2026).
 *
 * One list, a popup to add or edit a draft (house rule), the allocation
 * preview per batch on every open document, and the three decisions as
 * buttons: submit, approve or reject, post. Reading sits behind
 * purchasing.landed_cost.view on the route; writing a draft behind .manage;
 * approving and posting behind .post (also the workflow stage's permission).
 */
class LandedCostController extends Controller
{
    public function index(Request $request, PlanLandedCostAllocation $planner, LandedCostDoubleCount $doubleCount): Response
    {
        $user = $request->user();
        $receiptFilter = $request->integer('receipt') ?: null;

        $query = LandedCost::query()
            ->with(['lines.supplier:id,name', 'lines.moneyAccount:id,name', 'lines.expenseAccount:id,code,name', 'lines.allocations.batch:id,batch_number',
                'goodsReceipt.purchaseOrder:id,po_number,supplier_id', 'goodsReceipt.purchaseOrder.supplier:id,name',
                'approvalRequest.requestedBy:id,name', 'approvalRequest.reviewedBy:id,name', 'journal:id,journal_number', 'createdBy:id,name', 'bills.supplier:id,name',
                'reversedBy:id,name', 'reversalJournal:id,journal_number'])
            ->latest('id');

        if ($receiptFilter !== null) {
            $query->where('goods_receipt_id', $receiptFilter);
        }

        // ⭐ B24 (25 Oct 2026): Reverse a posted landed cost, from its policy.
        $policy = app(LandedCostActionPolicy::class);
        $landedCosts = $query->limit(100)->get()->map(fn (LandedCost $lc) => $this->present($lc, $planner, $doubleCount) + [
            'actions' => $policy->actionsFor($lc, $user)->toArray()['actions'],
        ])->values();

        return Inertia::render('purchasing/landed-costs/index', [
            'landedCosts' => $landedCosts,
            'filters' => ['receipt' => $receiptFilter, 'new' => $request->boolean('new')],
            'receipts' => $this->receipts(),
            'costTypes' => LandedCostType::options(),
            'treatments' => array_map(fn (LandedCostTreatment $t) => ['value' => $t->value, 'label' => $t->label()], LandedCostTreatment::cases()),
            'methods' => array_map(fn (LandedCostAllocationMethod $m) => ['value' => $m->value, 'label' => $m->label()], LandedCostAllocationMethod::cases()),
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all(),
            // Active accounts WITH a ledger mapping, as the expense voucher offers them.
            'moneyAccounts' => MoneyAccount::query()->handled()->where('is_active', true)->whereNotNull('account_id')->orderBy('name')->get(['id', 'name', 'type'])->all(),
            'expenseAccounts' => Account::query()
                ->where('type', AccountType::Expense)->where('is_postable', true)->where('is_active', true)
                ->whereNotIn('id', VoucherAccounts::refusedIds(Carbon::today()))
                ->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn (Account $a) => ['id' => $a->id, 'label' => $a->label()])->all(),
            'today' => now()->toDateString(),
            'canManage' => (bool) $user?->can('purchasing.landed_cost.manage'),
            'canPost' => (bool) $user?->can('purchasing.landed_cost.post'),
            'reversalReasons' => Reason::query()->where('context', 'reversal')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, SaveLandedCost $save): RedirectResponse
    {
        $data = $this->validated($request);
        $receipt = GoodsReceipt::query()->findOrFail($data['goods_receipt_id']);

        try {
            $landedCost = $save->handle($receipt, $data['incurred_on'], $data['lines'], $request->user(), $data['note'] ?? null);
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages(['lines' => RefusalMessage::of($e)]);
        }

        return redirect()->route('purchasing.landed-costs.index', ['receipt' => $receipt->id])
            ->with('success', "Landed cost {$landedCost->number} saved as a draft. Check the allocation, then submit it.");
    }

    public function update(Request $request, LandedCost $landedCost, SaveLandedCost $save): RedirectResponse
    {
        $data = $this->validated($request);
        $receipt = GoodsReceipt::query()->findOrFail($data['goods_receipt_id']);

        try {
            $save->handle($receipt, $data['incurred_on'], $data['lines'], $request->user(), $data['note'] ?? null, $landedCost);
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages(['lines' => RefusalMessage::of($e)]);
        }

        return back()->with('success', "Landed cost {$landedCost->number} saved.");
    }

    public function destroy(LandedCost $landedCost): RedirectResponse
    {
        if (! $landedCost->isEditable()) {
            return back()->with('error', "Landed cost {$landedCost->number} has been sent on and cannot be deleted.");
        }

        $landedCost->approvalRequest?->delete();
        $landedCost->delete();

        return back()->with('success', "Draft {$landedCost->number} deleted.");
    }

    public function submit(Request $request, LandedCost $landedCost, SubmitLandedCost $submit): RedirectResponse
    {
        try {
            $landedCost = $submit->handle($landedCost, $request->user());
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', RefusalMessage::of($e));
        }

        return back()->with('success', $landedCost->status->value === 'approved'
            ? "Landed cost {$landedCost->number} approved. Post it when ready."
            : "Landed cost {$landedCost->number} sent for approval.");
    }

    public function approve(Request $request, LandedCost $landedCost, ApproveLandedCost $approve): RedirectResponse
    {
        $data = $request->validate(['review_note' => ['nullable', 'string']]);

        try {
            $approve->handle($landedCost, $request->user(), $data['review_note'] ?? null);
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', RefusalMessage::of($e));
        }

        return back()->with('success', "Landed cost {$landedCost->number} approved.");
    }

    public function reject(Request $request, LandedCost $landedCost, RejectApprovable $reject): RedirectResponse
    {
        $data = $request->validate(['review_note' => ['nullable', 'string']]);

        try {
            $reject->handle($landedCost, $request->user(), $data['review_note'] ?? null);
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', RefusalMessage::of($e));
        }

        return back()->with('success', "Landed cost {$landedCost->number} rejected. It is back in draft for correction.");
    }

    public function post(Request $request, LandedCost $landedCost, PostLandedCost $post, PurchasingApprovalGate $gate): RedirectResponse
    {
        try {
            $landedCost = $post->handle($landedCost, $request->user());
        } catch (PurchaseApprovalRequired $e) {
            // ⭐ 24 Oct 2026: what it owes a supplier needs a second person
            // (ruling P7). Saved as a PA- row here, outside the posting's
            // transaction; approving it posts the landed cost.
            try {
                $approval = $gate->hold($e, $request->user());
            } catch (ValidationException $refused) {
                return back()->with('error', collect($refused->errors())->flatten()->first());
            }

            return back()->with('success', "Landed cost {$landedCost->number} needs a second person's approval (".implode('; ', array_column($e->reasons, 'label'))."). It waits as {$approval->number()} and posts when they approve.");
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', RefusalMessage::of($e));
        }

        return back()->with('success', "Landed cost {$landedCost->number} posted: stock values and the ledger are updated.");
    }

    /**
     * Expenses already booked around one receipt that could be one of its
     * costs (18 Oct 2026, decision D-A): the popup matches them against each
     * line's type and amount as it is typed, and warns before anything is
     * saved. A warning only; nothing is reversed from here.
     */
    public function expenseCheck(GoodsReceipt $goodsReceipt, LandedCostDoubleCount $doubleCount): JsonResponse
    {
        return response()->json([
            'similar_percent' => LandedCostDoubleCount::SIMILAR_PERCENT,
            'candidates' => $doubleCount->candidatesFor($goodsReceipt),
        ]);
    }

    /** The allocation preview of one document, for a refresh without reloading the list. */
    public function preview(LandedCost $landedCost, PlanLandedCostAllocation $planner): JsonResponse
    {
        return response()->json($planner->handle($landedCost)->toArray());
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'goods_receipt_id' => ['required', 'integer', 'exists:goods_receipts,id'],
            'incurred_on' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.cost_type' => ['required', 'string'],
            'lines.*.treatment' => ['nullable', 'string'],
            'lines.*.override_reason' => ['nullable', 'string', 'max:500'],
            'lines.*.allocation_method' => ['nullable', 'string'],
            'lines.*.goods_receipt_line_id' => ['nullable', 'integer'],
            'lines.*.amount' => ['required', 'numeric', 'gt:0'],
            'lines.*.supplier_id' => ['nullable', 'integer'],
            'lines.*.money_account_id' => ['nullable', 'integer'],
            'lines.*.expense_account_id' => ['nullable', 'integer'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            // ⭐ Initial plan B1 (R49): a one-off payee's name on a cost paid from an account.
            'lines.*.payee_name' => ['nullable', 'string', 'max:120'],
        ]);
    }

    /**
     * Receipts a cost can be added to: the latest 200 of this business, each
     * with the lines a direct allocation can name.
     *
     * @return list<array<string, mixed>>
     */
    private function receipts(): array
    {
        return GoodsReceipt::query()
            ->with(['purchaseOrder:id,po_number,supplier_id', 'purchaseOrder.supplier:id,name',
                'lines.purchaseOrderLine:id,product_id', 'lines.purchaseOrderLine.product:id,name,sku', 'lines.stockMovement:id,batch_id', 'lines.stockMovement.batch:id,batch_number'])
            ->latest('received_at')->latest('id')->limit(200)->get()
            ->map(fn (GoodsReceipt $receipt) => [
                'id' => $receipt->id,
                'received_at' => $receipt->received_at?->toDateString(),
                'po_number' => $receipt->purchaseOrder?->po_number,
                'supplier' => $receipt->purchaseOrder?->supplier?->name,
                'supplier_id' => $receipt->purchaseOrder?->supplier_id,
                'challan' => $receipt->supplier_challan_number,
                'lines' => $receipt->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'product' => $line->purchaseOrderLine?->product?->name,
                    'sku' => $line->purchaseOrderLine?->product?->sku,
                    'quantity_received' => (string) $line->quantity_received,
                    'batch_number' => $line->stockMovement?->batch?->batch_number,
                    'stocked' => $line->stockMovement?->batch_id !== null,
                ])->values()->all(),
            ])->values()->all();
    }

    /** @return array<string, mixed> */
    private function present(LandedCost $lc, PlanLandedCostAllocation $planner, LandedCostDoubleCount $doubleCount): array
    {
        $request = $lc->approvalRequest;
        $plan = $lc->isPosted() ? null : $planner->handle($lc)->toArray();
        // ⭐ 18 Oct 2026: possible double counting, for a document still open.
        $warnings = $lc->isPosted() ? [] : $doubleCount->warningsFor($lc);

        return [
            'id' => $lc->id,
            'number' => $lc->number,
            'status' => $lc->status->value,
            'status_label' => $lc->isAwaitingApproval() ? 'Waiting for approval' : $lc->status->label(),
            'awaiting_approval' => $lc->isAwaitingApproval(),
            'editable' => $lc->isEditable(),
            'incurred_on' => $lc->incurred_on?->toDateString(),
            'note' => $lc->note,
            'total' => Money::ofMinor((int) $lc->total_paisa)->toTaka(),
            'goods_receipt_id' => $lc->goods_receipt_id,
            'po_number' => $lc->goodsReceipt?->purchaseOrder?->po_number,
            'purchase_order_id' => $lc->goodsReceipt?->purchase_order_id,
            'supplier' => $lc->goodsReceipt?->purchaseOrder?->supplier?->name,
            'received_at' => $lc->goodsReceipt?->received_at?->toDateString(),
            'created_by' => $lc->createdBy?->name,
            'requested_by' => $request?->requestedBy?->name,
            'reviewed_by' => $request?->reviewedBy?->name,
            'review_note' => $request?->review_note,
            'rejected' => $request !== null && $request->status->value === 'rejected',
            'journal_number' => $lc->journal?->journal_number,
            'posted_at' => $lc->posted_at?->toDateString(),
            'reversal' => $lc->isReversed() ? [
                'on' => $lc->reversed_on?->toDateString(),
                'by' => $lc->reversedBy?->name,
                'reason' => $lc->reversal_reason,
                'journal' => $lc->reversalJournal?->journal_number,
            ] : null,
            // ⭐ 18 Oct 2026 (finding P3): the bills raised for what is owed.
            'bills' => $lc->bills->map(fn ($bill) => [
                'id' => $bill->id,
                'supplier' => $bill->supplier?->name,
                'total' => Money::ofTaka((string) $bill->total)->toTaka(),
                'outstanding' => $bill->outstanding()->toTaka(),
            ])->values()->all(),
            'lines' => $lc->lines->map(fn (LandedCostLine $line) => [
                'id' => $line->id,
                'cost_type' => $line->cost_type->value,
                'cost_type_label' => $line->cost_type->label(),
                'treatment' => $line->treatment->value,
                'treatment_label' => $line->treatment->label(),
                // ⭐ 18 Oct 2026: the suggested treatment, shown beside the chosen one.
                'default_treatment' => $line->defaultTreatment()->value,
                'default_treatment_label' => $line->defaultTreatment()->label(),
                'overrides_default' => $line->overridesDefault(),
                'override_reason' => $line->override_reason,
                'allocation_method' => $line->allocation_method->value,
                'allocation_method_label' => $line->allocation_method->label(),
                'goods_receipt_line_id' => $line->goods_receipt_line_id,
                'amount' => $line->amount()->toTaka(),
                'supplier_id' => $line->supplier_id,
                'supplier' => $line->supplier?->name,
                'money_account_id' => $line->money_account_id,
                'money_account' => $line->moneyAccount?->name,
                'payee_name' => $line->payee_name,
                'expense_account_id' => $line->expense_account_id,
                'expense_account' => $line->expenseAccount?->label(),
                'description' => $line->description,
                'double_count' => $warnings[$line->id] ?? [],
                // Written at posting: the evidence, frozen.
                'allocations' => $line->allocations->map(fn ($a) => [
                    'receipt_line_id' => $a->goods_receipt_line_id,
                    'batch_id' => $a->stock_batch_id,
                    'batch_number' => $a->batch?->batch_number,
                    'quantity_received' => (string) $a->quantity_received,
                    'quantity_on_hand' => (string) $a->quantity_on_hand,
                    'quantity_consumed' => (string) $a->quantity_consumed,
                    'basis_quantity' => $a->basis_quantity === null ? null : (string) $a->basis_quantity,
                    'basis_unit' => $a->basis_unit,
                    'share' => Money::ofMinor((int) $a->share_paisa)->toTaka(),
                    'on_hand_share' => Money::ofMinor((int) $a->on_hand_share_paisa)->toTaka(),
                    'consumed_share' => Money::ofMinor((int) $a->consumed_share_paisa)->toTaka(),
                    'new_unit_cost' => $a->new_unit_cost === null ? null : (string) $a->new_unit_cost,
                ])->values()->all(),
            ])->values()->all(),
            // What posting WOULD do, for an open document; null once posted.
            'plan' => $plan === null ? null : [
                'capitalised' => $plan['capitalised'],
                'on_hand' => $plan['on_hand'],
                'consumed' => $plan['consumed'],
                'expensed' => $plan['expensed'],
                'refusals' => $plan['refusals'],
                'lines' => array_map(fn (array $line) => [
                    'line_id' => $line['line_id'],
                    'basis' => $line['basis'],
                    'basis_label' => $line['basis_label'],
                    'expense_account' => $line['expense_account'],
                    'allocations' => array_map(fn (array $a) => [
                        'receipt_line_id' => $a['receipt_line_id'],
                        'batch_id' => $a['batch_id'],
                        'batch_number' => $a['batch_number'],
                        'product' => $a['product'],
                        'quantity_received' => $a['quantity_received'],
                        'quantity_on_hand' => $a['quantity_on_hand'],
                        'quantity_consumed' => $a['quantity_consumed'],
                        'basis_quantity' => $a['basis_quantity'],
                        'basis_unit' => $a['basis_unit'],
                        'share' => Money::ofMinor($a['share_paisa'])->toTaka(),
                        'on_hand_share' => Money::ofMinor($a['on_hand_share_paisa'])->toTaka(),
                        'consumed_share' => Money::ofMinor($a['consumed_share_paisa'])->toTaka(),
                        'old_unit_cost' => $a['old_unit_cost'],
                        'new_unit_cost' => $a['new_unit_cost'],
                    ], $line['allocations']),
                ], $plan['lines']),
            ],
        ];
    }
}
