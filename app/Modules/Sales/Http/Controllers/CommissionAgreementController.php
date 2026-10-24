<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Support\CorrectionScreen;
use App\Modules\Sales\Actions\Commission\PayCommission;
use App\Modules\Sales\Actions\Commission\SaveCommissionAgreement;
use App\Modules\Sales\Actions\Commission\SettleCommission;
use App\Modules\Sales\Corrections\CommissionPaymentActionPolicy;
use App\Modules\Sales\Corrections\CommissionSettlementActionPolicy;
use App\Modules\Sales\Models\CommissionAgreement;
use App\Modules\Sales\Models\CommissionAgreementItem;
use App\Modules\Sales\Models\CommissionAgreementTier;
use App\Modules\Sales\Models\CommissionPayment;
use App\Modules\Sales\Models\CommissionSettlement;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Support\CommissionReport;
use App\Modules\Sales\Support\CommissionTracker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ⭐ Commission agreements (Joy's Q18, 25 Oct 2026): "no commission plan come
 * from authority ... someone can offer i can sell these amount or i will sell
 * but per product you give me these amount, we can agree about that."
 *
 * Sales > Sales setup > Commission agreements (it replaces the Targets tab;
 * old target rows stay readable at the bottom of the list). Every write is a
 * popup; the record page shows progress, settlements and payments, each
 * settlement and payment with its Reverse (the corrections engine).
 */
class CommissionAgreementController extends Controller
{
    public function index(CommissionTracker $tracker): Response
    {
        $agreements = CommissionAgreement::query()
            ->with(['items.product:id,name', 'items.category:id,name', 'tiers', 'employee', 'customer', 'supplier'])
            ->orderByDesc('agreed_on')->orderByDesc('id')
            ->get()
            ->map(fn (CommissionAgreement $a) => $this->row($a, $a->isActive() ? $tracker->progress($a) : null));

        return Inertia::render('sales/commission/index', [
            'agreements' => $agreements,
            'options' => $this->options(),
            'legacyTargets' => $this->legacyTargets(),
        ]);
    }

    public function show(Request $request, CommissionAgreement $agreement, CommissionTracker $tracker): Response
    {
        $agreement->load(['items.product:id,name', 'items.category:id,name', 'tiers', 'employee', 'customer', 'supplier', 'createdBy:id,name']);
        $user = $request->user();
        $settlementPolicy = app(CommissionSettlementActionPolicy::class);
        $paymentPolicy = app(CommissionPaymentActionPolicy::class);
        $allowToday = fn (Carbon $date) => ! AccountingPeriod::isClosedOn($date);

        $settlements = $agreement->settlements()->with(['moneyAccount', 'payrollAdjustment.period', 'payments.moneyAccount', 'createdBy:id,name'])
            ->orderByDesc('through_date')->orderByDesc('id')->get();

        return Inertia::render('sales/commission/show', [
            'agreement' => $this->row($agreement, $agreement->isActive() ? $tracker->progress($agreement) : null) + [
                'created_by' => $agreement->createdBy?->name,
                'decision_note' => $agreement->decision_note,
                'last_settled_through' => $tracker->lastSettledThrough($agreement)?->toDateString(),
            ],
            'settlements' => $settlements->map(fn (CommissionSettlement $s) => [
                'id' => $s->id,
                'number' => $s->number,
                'settled_on' => $s->settled_on->toDateString(),
                'from_date' => $s->from_date->toDateString(),
                'through_date' => $s->through_date->toDateString(),
                'sold_quantity' => $s->sold_quantity,
                'sold_value' => $s->sold_value,
                'amount' => $s->money()->toTaka(),
                'method' => $s->method,
                'method_label' => $s->methodLabel(),
                'money_account' => $s->moneyAccount?->label(),
                'payroll_month' => $s->payrollAdjustment?->period?->label(),
                'status' => $s->statusLabel(),
                'is_posted' => $s->isPosted(),
                'owed' => $s->owed()->toTaka(),
                'note' => $s->note,
                'reversal_reason' => $s->reversal_reason,
                'corrections' => $s->status === CommissionSettlement::POSTED ? CorrectionScreen::for($s, $settlementPolicy, $user) + ['allow_today' => $allowToday($s->settled_on)] : null,
                'payments' => $s->payments->sortByDesc('id')->values()->map(fn (CommissionPayment $p) => [
                    'id' => $p->id,
                    'number' => $p->number,
                    'paid_on' => $p->paid_on->toDateString(),
                    'amount' => $p->money()->toTaka(),
                    'money_account' => $p->moneyAccount?->label(),
                    'reversed' => $p->isReversed(),
                    'reversal_reason' => $p->reversal_reason,
                    'corrections' => CorrectionScreen::for($p, $paymentPolicy, $user) + ['allow_today' => $allowToday($p->paid_on)],
                ])->all(),
            ])->values(),
            'options' => $this->options(),
        ]);
    }

    public function store(Request $request, SaveCommissionAgreement $save): RedirectResponse
    {
        $agreement = $save->create($this->validated($request), $request->user());

        return redirect()->route('sales.commission.show', $agreement)->with('success', $agreement->isPending()
            ? "{$agreement->number} saved. It promises more than the approval limit, so it waits for a second person before sales count."
            : "{$agreement->number} saved. Sales credited to {$agreement->partyName()} now count towards it.");
    }

    public function update(Request $request, CommissionAgreement $agreement, SaveCommissionAgreement $save): RedirectResponse
    {
        $agreement = $save->update($agreement, $this->validated($request), $request->user());

        return back()->with('success', "{$agreement->number} saved.");
    }

    public function approve(Request $request, CommissionAgreement $agreement, SaveCommissionAgreement $save): RedirectResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;

        try {
            $final = $save->approve($agreement, $request->user(), $note);
        } catch (ValidationException $e) {
            return back()->with('error', 'Not approved: '.collect($e->errors())->flatten()->first());
        }

        return back()->with('success', $final ? "{$agreement->number} approved. Its sales now count." : 'Approved. Sent to the next stage.');
    }

    public function reject(Request $request, CommissionAgreement $agreement, SaveCommissionAgreement $save): RedirectResponse
    {
        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:1000'], 'note' => ['nullable', 'string', 'max:1000']]);
        $save->reject($agreement, $request->user(), $data['review_note'] ?? $data['note'] ?? null);

        return back()->with('success', "{$agreement->number} rejected.");
    }

    public function settle(Request $request, CommissionAgreement $agreement, SettleCommission $settle): RedirectResponse
    {
        $data = $request->validate([
            'settled_on' => ['required', 'date_format:Y-m-d'],
            'through_date' => ['required', 'date_format:Y-m-d'],
            'method' => ['required', Rule::in(CommissionSettlement::METHODS)],
            'money_account_id' => ['nullable', 'integer', Rule::exists('money_accounts', 'id')->where('business_id', app(BusinessContext::class)->id())],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $settlement = $settle->handle(
            $agreement,
            $data['settled_on'],
            $data['through_date'],
            $data['method'],
            isset($data['money_account_id']) ? MoneyAccount::query()->findOrFail($data['money_account_id']) : null,
            $data['note'] ?? null,
            $request->user(),
        );

        return back()->with('success', $settlement->isPending()
            ? "{$settlement->number} for Tk {$settlement->money()->format()} waits for a second person (above the approval limit)."
            : "{$settlement->number} settled: Tk {$settlement->money()->format()} ({$settlement->methodLabel()}).");
    }

    public function approveSettlement(Request $request, CommissionSettlement $settlement, SettleCommission $settle): RedirectResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;

        try {
            $final = $settle->approve($settlement, $request->user(), $note);
        } catch (ValidationException $e) {
            return back()->with('error', 'Not approved: '.collect($e->errors())->flatten()->first());
        }

        return back()->with('success', $final ? "{$settlement->number} approved and posted." : 'Approved. Sent to the next stage.');
    }

    public function rejectSettlement(Request $request, CommissionSettlement $settlement, SettleCommission $settle): RedirectResponse
    {
        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:1000'], 'note' => ['nullable', 'string', 'max:1000']]);
        $settle->reject($settlement, $request->user(), $data['review_note'] ?? $data['note'] ?? null);

        return back()->with('success', "{$settlement->number} rejected. Nothing was posted.");
    }

    public function pay(Request $request, CommissionSettlement $settlement, PayCommission $pay): RedirectResponse
    {
        $data = $request->validate([
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'money_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')->where('business_id', app(BusinessContext::class)->id())],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $payment = $pay->handle($settlement, $data['paid_on'], (string) $data['amount'], MoneyAccount::query()->findOrFail($data['money_account_id']), $data['note'] ?? null, $request->user());

        return back()->with('success', "{$payment->number}: Tk {$payment->money()->format()} paid.");
    }

    public function report(Request $request, CommissionReport $report): Response
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = Carbon::parse($data['from'] ?? Carbon::today()->startOfMonth()->toDateString())->startOfDay();
        $to = Carbon::parse($data['to'] ?? Carbon::today()->toDateString())->startOfDay();

        return Inertia::render('sales/commission/report', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rows' => $report->rows($from, $to),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $businessId = app(BusinessContext::class)->id();
        $data = $request->validate([
            'agreed_on' => ['required', 'date_format:Y-m-d'],
            'party_type' => ['required', Rule::in(CommissionAgreement::PARTY_TYPES)],
            'party_id' => ['required', 'integer'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d'],
            'deal_type' => ['required', Rule::in(CommissionAgreement::DEAL_TYPES)],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'target_basis' => ['nullable', Rule::in(['units', 'amount'])],
            'counts_credited_sales' => ['boolean'],
            'counts_own_purchases' => ['boolean'],
            'product_ids' => ['array'],
            'product_ids.*' => ['integer', Rule::exists('products', 'id')->where('business_id', $businessId)],
            'category_ids' => ['array'],
            'category_ids.*' => ['integer', Rule::exists('categories', 'id')->where('business_id', $businessId)],
            'tiers' => ['array', 'max:10'],
            'tiers.*.threshold' => ['required', 'numeric', 'gt:0'],
            'tiers.*.reward' => ['required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        // The person must be one this business keeps (the global scopes do
        // the business part).
        $exists = match ($data['party_type']) {
            'employee' => Employee::query()->whereKey($data['party_id'])->exists(),
            'customer' => Customer::query()->whereKey($data['party_id'])->exists(),
            default => Supplier::query()->whereKey($data['party_id'])->exists(),
        };

        if (! $exists) {
            throw ValidationException::withMessages(['party_id' => 'Choose the person.']);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function row(CommissionAgreement $a, ?array $progress): array
    {
        return [
            'id' => $a->id,
            'number' => $a->number,
            'agreed_on' => $a->agreed_on->toDateString(),
            'party_type' => $a->party_type,
            'party_id' => $a->partyId(),
            'party_name' => $a->partyName(),
            'valid_from' => $a->valid_from->toDateString(),
            'valid_to' => $a->valid_to?->toDateString(),
            'deal_type' => $a->deal_type,
            'rate' => $a->rate,
            'target_basis' => $a->target_basis,
            'counts_credited_sales' => $a->counts_credited_sales,
            'counts_own_purchases' => $a->counts_own_purchases,
            'deal_summary' => $a->dealSummary(),
            'products' => $a->items->filter(fn (CommissionAgreementItem $i) => $i->product_id !== null)->map(fn ($i) => ['id' => $i->product_id, 'name' => $i->product?->name])->values(),
            'categories' => $a->items->filter(fn (CommissionAgreementItem $i) => $i->category_id !== null)->map(fn ($i) => ['id' => $i->category_id, 'name' => $i->category?->name])->values(),
            'tiers' => $a->tiers->map(fn (CommissionAgreementTier $t) => ['threshold' => $t->threshold, 'reward' => $t->reward])->values(),
            'note' => $a->note,
            'status' => $a->status,
            'status_label' => $a->statusLabel(),
            'progress' => $progress,
        ];
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'employees' => Employee::query()->orderBy('first_name')->orderBy('last_name')->get()
                ->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->fullName()])->values(),
            'customers' => Customer::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'products' => Product::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'moneyAccounts' => MoneyAccount::query()->handled()->where('is_active', true)->whereNotNull('account_id')->orderBy('name')->get(['id', 'name', 'type']),
        ];
    }

    /**
     * The old Targets screen's rows, read only (Joy's Q18: no automatic
     * commission any more, so nothing new is set there).
     *
     * @return list<array<string, mixed>>
     */
    private function legacyTargets(): array
    {
        return DB::table('sales_targets')
            ->leftJoin('users', 'users.id', '=', 'sales_targets.user_id')
            ->where('sales_targets.business_id', app(BusinessContext::class)->id())
            ->orderByDesc('sales_targets.month')
            ->get(['sales_targets.id', 'sales_targets.month', 'sales_targets.target_amount', 'sales_targets.commission_percent', 'users.name as user'])
            ->map(fn ($t) => [
                'id' => (int) $t->id,
                'month' => substr((string) $t->month, 0, 7),
                'user' => $t->user,
                'target_amount' => number_format((float) $t->target_amount, 2, '.', ''),
                'commission_percent' => number_format((float) $t->commission_percent, 2, '.', ''),
            ])->all();
    }
}
