<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\ValidatesActionReason;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\EditInventoryAdjustment;
use App\Modules\Inventory\Actions\WithdrawInventoryAdjustment;
use App\Modules\Inventory\Corrections\InventoryAdjustmentActionPolicy;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Support\AdjustmentCause;
use App\Modules\Inventory\Support\StockValue;
use App\Support\PerPage;
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
 * ⭐ Corrections wave 1, B4 (20 Oct 2026): the stock corrections list, and the
 * raiser's Edit and Withdraw (FINAL-PLAN 3.3).
 *
 * Until now an adjustment could only be seen in the approvals inbox while it
 * waited, and nowhere once decided, so a wrong one could be neither pulled
 * back nor reversed. This page lists them with the buttons the policy gives:
 * Edit and Withdraw while waiting (the raiser), Reverse once approved (the
 * stock approver, through the corrections engine's own routes).
 *
 * ⚠ The policy decides; this controller asks it again on every write.
 */
class StockAdjustmentController extends Controller
{
    use ValidatesActionReason;

    public function __construct(private readonly InventoryAdjustmentActionPolicy $policy) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = [
            'status' => $request->string('status')->toString(),
            'focus' => $request->integer('focus') ?: null,
            // ⭐ B6: the period of the losses-by-reason report ('' = this month).
            'loss_from' => $request->string('loss_from')->toString(),
            'loss_to' => $request->string('loss_to')->toString(),
        ];

        $adjustments = InventoryAdjustment::query()
            ->with(['product:id,name,sku,unit_of_measure_id', 'product.unitOfMeasure:id,abbreviation', 'warehouse:id,name', 'batch:id,batch_number', 'reason:id,name', 'createdBy:id,name', 'reversedBy:id,name'])
            ->when($filters['status'] === 'waiting', fn ($q) => $q->whereHas('approvalRequest', fn ($r) => $r->where('status', ApprovalStatus::Pending)))
            ->when($filters['status'] === 'withdrawn', fn ($q) => $q->whereHas('approvalRequest', fn ($r) => $r->where('status', ApprovalStatus::Returned)))
            ->when($filters['status'] === 'approved', fn ($q) => $q->live()->whereHas('approvalRequest', fn ($r) => $r->where('status', ApprovalStatus::Approved)))
            ->when($filters['status'] === 'reversed', fn ($q) => $q->whereNotNull('reversed_at'))
            ->when($filters['focus'], fn ($q, $id) => $q->whereKey($id))
            ->latest('id')
            ->paginate(PerPage::from())
            ->withQueryString();

        $adjustments->getCollection()->transform(fn (InventoryAdjustment $a) => [
            'id' => $a->id,
            'number' => $this->policy->numberOf($a),
            'effective_date' => $a->effective_date?->toDateString(),
            'type' => $a->type->label(),
            'direction' => $a->direction->value,
            'product' => $a->product?->name,
            'product_id' => $a->product_id,
            'unit' => $a->product?->unitOfMeasure?->abbreviation,
            'warehouse' => $a->warehouse?->name,
            'batch' => $a->batch?->batch_number,
            'quantity' => (string) $a->quantity,
            'unit_cost' => $a->unit_cost === null ? null : (string) $a->unit_cost,
            'estimated_value' => (string) $a->estimated_value,
            'reason_id' => $a->reason_id,
            'reason' => $a->reason?->name,
            'note' => $a->note,
            'raised_by' => $a->createdBy?->name,
            'status' => $this->status($a),
            'reversal' => $a->reversalSummary(),
            'cost_change' => $a->changesValueOnly(),
            'actions' => $this->policy->actionsFor($a, $user)->toArray()['actions'],
        ]);

        return Inertia::render('inventory/stock/adjustments', [
            'adjustments' => $adjustments,
            'filters' => $filters,
            'losses' => $this->lossesByReason($request),
            'type' => InventoryAdjustmentActionPolicy::TYPE,
            'adjustmentReasons' => Reason::active()->forContext('inventory.stock_adjustment')->orderBy('name')->get()
                ->map(fn (Reason $reason) => AdjustmentCause::describe($reason))->values(),
            'reasons' => [
                'correction' => $this->reasons('correction'),
                'withdrawal' => $this->reasons('withdrawal'),
                'reversal' => $this->reasons('reversal'),
            ],
        ]);
    }

    /** Edit while waiting (a reason) or after withdrawing (none: a draft). */
    public function update(Request $request, InventoryAdjustment $adjustment, EditInventoryAdjustment $action): RedirectResponse
    {
        $availability = $this->policy->availability($adjustment, $request->user(), DocumentAction::Edit);

        abort_unless($request->user()->can($availability->permission), 403);

        if (! $availability->allowed) {
            throw ValidationException::withMessages(['stock' => $availability->blockers[0]->message]);
        }

        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'effective_date' => ['required', 'date'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            // ⚠ `what_happened`, not `reason_id`: that name belongs to the
            // reason for the CHANGE (ValidatesActionReason), a different list.
            'what_happened' => ['required', Rule::exists('reasons', 'id')->where('business_id', $businessId)->where('context', 'inventory.stock_adjustment')],
            'note' => ['nullable', 'string', 'max:1000'],
            ...($availability->requiresReason ? $this->actionReasonRules('correction') : []),
        ], [
            'what_happened.required' => 'Choose what happened.',
            'quantity.gt' => 'Enter a quantity above zero.',
            ...$this->actionReasonMessages(),
        ]);

        try {
            $action->handle($adjustment, ['reason_id' => (int) $data['what_happened']] + $data, $request->user(), $availability->requiresReason ? $this->actionReason($request) : null);
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages(['stock' => $e->getMessage()]);
        }

        return back()->with('success', $this->policy->numberOf($adjustment).' changed.');
    }

    public function withdraw(Request $request, InventoryAdjustment $adjustment, WithdrawInventoryAdjustment $action): RedirectResponse
    {
        $availability = $this->policy->availability($adjustment, $request->user(), DocumentAction::Withdraw);

        abort_unless($request->user()->can($availability->permission), 403);

        $request->validate($this->actionReasonRules('withdrawal'), $this->actionReasonMessages());

        if (! $availability->allowed) {
            throw ValidationException::withMessages(['reason_text' => $availability->blockers[0]->message]);
        }

        try {
            $action->handle($adjustment, $request->user(), $this->actionReason($request));
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }

        return back()->with('success', $this->policy->numberOf($adjustment).' withdrawn. It changes nothing until you send it again.');
    }

    /**
     * ⭐ Initial plan B6 (Joy, 24 Oct 2026; Q14 = A, R72): the inventory
     * losses report, by reason, for a period (this month unless asked).
     * Approved, not reversed corrections only, valued from their own stock
     * movements (each row rounded once, StockValue), so the figure is what
     * the ledger was given, at each batch's own cost. A positive amount is a
     * loss; stock that turned up shows as a gain on its own reason.
     *
     * @return array{from: string, to: string, rows: list<array{type: string, label: string, head: ?string, count: int, amount: string}>, total: string}
     */
    private function lossesByReason(Request $request): array
    {
        $from = $this->dateOr($request->string('loss_from')->toString(), Carbon::today()->startOfMonth());
        $to = $this->dateOr($request->string('loss_to')->toString(), Carbon::today());

        $adjustments = InventoryAdjustment::query()
            ->live()
            ->whereHas('approvalRequest', fn ($r) => $r->where('status', ApprovalStatus::Approved))
            ->whereDate('effective_date', '>=', $from->toDateString())
            ->whereDate('effective_date', '<=', $to->toDateString())
            ->with(['movements' => fn ($q) => $q->select(['id', 'adjustment_id', ...StockValue::columns()])])
            ->get(['id', 'type']);

        $rows = [];
        $total = Money::zero();

        foreach ($adjustments->groupBy(fn (InventoryAdjustment $a) => $a->type->value) as $type => $group) {
            $loss = StockValue::sum($group->flatMap->movements)->negated();
            $total = $total->plus($loss);
            $mapping = AccountMapping::forKeyOn("inventory.adjustment.{$type}", $to) ?? AccountMapping::forKeyOn('inventory.adjustment.default', $to);

            $rows[] = [
                'type' => (string) $type,
                'label' => $group->first()->type->label(),
                'head' => $mapping?->account?->label(),
                'count' => $group->count(),
                'amount' => $loss->toTaka(),
            ];
        }

        usort($rows, fn (array $a, array $b) => strcmp($a['label'], $b['label']));

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => $rows, 'total' => $total->toTaka()];
    }

    private function dateOr(string $value, Carbon $default): Carbon
    {
        if ($value === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $default;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return $default;
        }
    }

    private function status(InventoryAdjustment $adjustment): string
    {
        if ($adjustment->isReversed()) {
            return 'reversed';
        }

        return match ($adjustment->approvalRequest?->status) {
            ApprovalStatus::Pending => 'waiting',
            ApprovalStatus::Returned => 'withdrawn',
            ApprovalStatus::Approved => 'approved',
            default => 'rejected',
        };
    }

    /** @return list<array{id: int, name: string}> */
    private function reasons(string $context): array
    {
        return Reason::query()->active()->forContext($context)->orderBy('id')->get(['id', 'name'])
            ->map(fn (Reason $r) => ['id' => $r->id, 'name' => $r->name])->values()->all();
    }
}
