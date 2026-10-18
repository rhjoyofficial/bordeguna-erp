<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\MoneyAccountBalances;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Actions\ApplySupplierAdvances;
use App\Modules\Purchasing\Actions\RecordSupplierAdvance;
use App\Modules\Purchasing\Actions\RefundSupplierAdvance;
use App\Modules\Purchasing\Actions\ReverseSupplierAdvance;
use App\Modules\Purchasing\Actions\ReverseSupplierAdvanceApplication;
use App\Modules\Purchasing\Actions\ReverseSupplierAdvanceRefund;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Http\Controllers\Concerns\ReversesDocuments;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierAdvance;
use App\Modules\Purchasing\Models\SupplierAdvanceApplication;
use App\Modules\Purchasing\Models\SupplierAdvanceRefund;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\WithholdingRate;
use App\Modules\Purchasing\Support\SupplierAdvanceBook;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Supplier advances on screen (ruling P2, 18 Oct 2026): pay one, apply it to a
 * bill, refund it, reverse any of the three.
 *
 * ⚠⚠ Like SupplierPaymentController, THIS CONTROLLER DECIDES NOTHING ABOUT
 * MONEY. Every rule (period lock, future date, the open balance re-read under
 * a lock, the dependency order of reversals) lives in the actions. Here: the
 * SHAPE of a form, ownership (every id resolved through the tenant scope), and
 * turning the engine's refusals into sentences on the right field.
 */
class SupplierAdvanceController extends Controller
{
    use ReversesDocuments;

    public function index(Request $request): Response
    {
        $filters = [
            'supplier_id' => $request->string('supplier_id')->toString(),
            'state' => $request->string('state')->toString(),
        ];

        $advances = SupplierAdvance::query()
            ->with(['supplier:id,name', 'purchaseOrder:id,po_number', 'moneyAccount:id,name'])
            ->when($filters['supplier_id'], fn ($query, $id) => $query->where('supplier_id', (int) $id))
            ->when($filters['state'] === 'reversed', fn ($query) => $query->whereNotNull('reversed_at'))
            ->when(in_array($filters['state'], ['open', 'posted'], true), fn ($query) => $query->whereNull('reversed_at'))
            ->orderByDesc('paid_on')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (SupplierAdvance $advance) => $this->row($advance));

        return Inertia::render('purchasing/advances/index', [
            'advances' => $advances,
            'filters' => $filters,
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'can_record' => $request->user()->can('purchasing.advance.record'),
        ]);
    }

    /** Purchases > Payments, "Advance (no bill yet)". */
    public function create(Request $request): Response
    {
        $order = $request->integer('purchase_order_id') ? PurchaseOrder::query()->find($request->integer('purchase_order_id')) : null;
        $supplier = $order?->supplier ?? ($request->integer('supplier_id') ? Supplier::query()->find($request->integer('supplier_id')) : null);
        $date = Carbon::today();

        return Inertia::render('purchasing/advances/create', [
            // ⚠ Every supplier, not only those owed: an advance is paid BEFORE
            // anything is owed.
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'selectedSupplierId' => $supplier?->id,
            'supplierType' => $supplier?->supplier_type,
            'purchaseOrders' => $supplier === null ? [] : PurchaseOrder::query()
                ->where('supplier_id', $supplier->id)
                ->where('status', '!=', PurchaseOrderStatus::Cancelled)
                ->orderByDesc('id')
                ->limit(100)
                ->get(['id', 'po_number', 'order_date'])
                ->map(fn (PurchaseOrder $po) => ['id' => $po->id, 'po_number' => $po->po_number, 'order_date' => $po->order_date?->toDateString()]),
            'selectedPurchaseOrderId' => $order?->id,
            'moneyAccounts' => $this->moneyAccounts(),
            // ⚠ TDS only: VDS is not held back on an advance while VAT is off.
            'withholdingRates' => WithholdingRate::query()->orderBy('kind')->orderBy('name')->get()
                ->filter(fn (WithholdingRate $r) => $r->kind === 'tds' || $this->vatApplies($date))
                ->map(fn (WithholdingRate $r) => [
                    'id' => $r->id, 'kind' => $r->kind, 'label' => $r->label(), 'rate' => (float) $r->rate,
                    'supplier_type' => $r->supplier_type,
                    'from' => $r->effective_from?->toDateString(), 'to' => $r->effective_to?->toDateString(),
                ])->values(),
            'today' => $date->toDateString(),
        ]);
    }

    public function store(Request $request, RecordSupplierAdvance $action): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('business_id', $businessId)],
            'money_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')->where('business_id', $businessId)],
            'purchase_order_id' => ['nullable', 'integer', Rule::exists('purchase_orders', 'id')->where('business_id', $businessId)],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
            'tds_rate_id' => ['nullable', 'integer', Rule::exists('withholding_rates', 'id')->where('business_id', $businessId)->where('kind', 'tds')],
            'vds_rate_id' => ['nullable', 'integer', Rule::exists('withholding_rates', 'id')->where('business_id', $businessId)->where('kind', 'vds')],
        ], [
            'paid_on.before_or_equal' => 'An advance cannot be dated in the future.',
        ]);

        try {
            $advance = $action->pay(
                supplier: Supplier::findOrFail($data['supplier_id']),
                moneyAccount: MoneyAccount::findOrFail($data['money_account_id']),
                paidOn: $data['paid_on'],
                amount: (string) $data['amount'],
                createdBy: $request->user(),
                purchaseOrder: empty($data['purchase_order_id']) ? null : PurchaseOrder::findOrFail($data['purchase_order_id']),
                reference: $data['reference'] ?? null,
                note: $data['note'] ?? null,
                tdsRate: empty($data['tds_rate_id']) ? null : WithholdingRate::find($data['tds_rate_id']),
                vdsRate: empty($data['vds_rate_id']) ? null : WithholdingRate::find($data['vds_rate_id']),
            );
        } catch (PeriodClosedException|MappingNotFound $e) {
            throw ValidationException::withMessages(['paid_on' => $e->getMessage()]);
        }

        return redirect()->route('purchasing.advances.show', $advance->id)
            ->with('success', "Advance {$advance->number} recorded.");
    }

    public function show(Request $request, SupplierAdvance $advance): Response
    {
        $advance->load(['supplier:id,name,code', 'purchaseOrder:id,po_number', 'moneyAccount:id,name', 'createdBy:id,name', 'reversedBy:id,name', 'reversalJournal:id,journal_number']);
        $canRecord = $request->user()->can('purchasing.advance.record');

        // ⚠ An overpayment advance's 1160 line sits on its payment's journal.
        $source = $advance->kind === 'overpayment' && $advance->payment !== null ? $advance->payment : $advance;

        return Inertia::render('purchasing/advances/show', [
            'advance' => [
                ...$this->row($advance),
                'note' => $advance->note,
                'reference' => $advance->reference,
                'tds_amount' => Money::ofTaka((string) $advance->tds_amount)->toTaka(),
                'net_paid' => $advance->netPaid()->toTaka(),
                'applied' => $advance->appliedTotal()->toTaka(),
                'refunded' => $advance->refundedTotal()->toTaka(),
                'supplier_payment_id' => $advance->supplier_payment_id,
                'created_by' => $advance->createdBy?->name,
                'reversal' => $advance->reversalSummary(),
            ],
            'applications' => $advance->applications()->with('bill:id,bill_number')->orderBy('applied_on')->orderBy('id')->get()
                ->map(fn (SupplierAdvanceApplication $a) => $this->applicationRow($a)),
            'refunds' => $advance->refunds()->with('moneyAccount:id,name')->orderBy('refunded_on')->orderBy('id')->get()
                ->map(fn (SupplierAdvanceRefund $r) => $this->refundRow($r)),
            'journal' => $this->journalOf($source),
            'moneyAccounts' => $canRecord ? $this->moneyAccounts() : [],
            'can_record' => $canRecord,
            'can_reverse' => $canRecord && ! $advance->isReversed() && $advance->kind === 'payment'
                && $advance->applications()->live()->doesntExist() && $advance->refunds()->live()->doesntExist(),
            'today' => Carbon::today()->toDateString(),
        ]);
    }

    public function reverse(Request $request, SupplierAdvance $advance, ReverseSupplierAdvance $action): RedirectResponse
    {
        return $this->reverseFromForm(
            $request,
            fn (string $reason, bool $onOriginalDate) => $action->handle($advance, $request->user(), $reason, $onOriginalDate),
            "Advance {$advance->number} reversed.",
        );
    }

    /**
     * The supplier's advances in one place (ruling P2: "apply later from the
     * supplier page"): what is open, what each open bill would take by
     * default, and the applications and refunds so far.
     */
    public function supplier(Request $request, Supplier $supplier): Response
    {
        $available = SupplierAdvanceBook::available($supplier);
        $pool = SupplierAdvanceBook::onAccountLots($supplier);
        $bills = SupplierBill::query()->where('supplier_id', $supplier->id)->owingWithOutstanding()
            ->orderBy('bill_date')->orderBy('id')->get();
        $canRecord = $request->user()->can('purchasing.advance.record');

        return Inertia::render('purchasing/advances/supplier', [
            'supplier' => ['id' => $supplier->id, 'name' => $supplier->name],
            'available' => $available->map(fn (array $row) => $this->availableRow($row))->values(),
            'on_account' => [
                'balance' => $pool['balance']->toTaka(),
                'lots' => array_map(fn (array $lot) => ['date' => $lot['date'], 'reference' => $lot['reference'], 'open' => $lot['open']->toTaka()], $pool['lots']),
            ],
            'bills' => $bills->map(fn (SupplierBill $bill) => [
                'id' => $bill->id,
                'bill_number' => $bill->bill_number,
                'bill_date' => $bill->bill_date?->toDateString(),
                'purchase_order_id' => $bill->purchase_order_id,
                'outstanding' => Money::ofMinor((int) $bill->outstanding_paisa)->toTaka(),
                'proposal' => array_map(fn (array $p) => ['key' => $p['key'], 'amount' => $p['amount']->toTaka()],
                    SupplierAdvanceBook::proposalFor($bill, Money::ofMinor((int) $bill->outstanding_paisa))),
            ])->values(),
            'applications' => SupplierAdvanceApplication::query()->where('supplier_id', $supplier->id)
                ->with(['bill:id,bill_number', 'advance:id,number'])->orderByDesc('applied_on')->orderByDesc('id')->limit(100)->get()
                ->map(fn (SupplierAdvanceApplication $a) => $this->applicationRow($a)),
            'refunds' => SupplierAdvanceRefund::query()->where('supplier_id', $supplier->id)
                ->with(['moneyAccount:id,name', 'advance:id,number'])->orderByDesc('refunded_on')->orderByDesc('id')->limit(100)->get()
                ->map(fn (SupplierAdvanceRefund $r) => $this->refundRow($r)),
            'moneyAccounts' => $canRecord ? $this->moneyAccounts() : [],
            'can_record' => $canRecord,
            'today' => Carbon::today()->toDateString(),
        ]);
    }

    /** Apply advances to one bill, from the bill page or the supplier page. */
    public function apply(Request $request, SupplierBill $bill, ApplySupplierAdvances $action): RedirectResponse
    {
        $data = $request->validate([
            'applied_on' => ['required', 'date', 'before_or_equal:today'],
            'advances' => ['required', 'array', 'min:1'],
            'advances.*.key' => ['required', 'string', 'distinct', 'regex:/^(on_account|advance:\d+)$/'],
            'advances.*.amount' => ['required', 'numeric', 'gte:0'],
            'note' => ['nullable', 'string'],
        ], [
            'applied_on.before_or_equal' => 'An advance cannot be applied on a future date.',
        ]);

        $rows = self::resolveRows($data['advances']);

        try {
            $applications = $action->handle($bill, $rows, $data['applied_on'], $request->user(), $data['note'] ?? null);
        } catch (PeriodClosedException|MappingNotFound $e) {
            throw ValidationException::withMessages(['applied_on' => $e->getMessage()]);
        }

        return back()->with('success', 'Applied '.Money::sum($applications->map(fn ($a) => $a->money()))->format()." to bill {$bill->bill_number}.");
    }

    public function reverseApplication(Request $request, SupplierAdvanceApplication $application, ReverseSupplierAdvanceApplication $action): RedirectResponse
    {
        return $this->reverseFromForm(
            $request,
            fn (string $reason, bool $onOriginalDate) => $action->handle($application, $request->user(), $reason, $onOriginalDate),
            "Advance application {$application->number} reversed. The bill owes that amount again.",
        );
    }

    public function refund(Request $request, RefundSupplierAdvance $action): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('business_id', $businessId)],
            'advance_key' => ['required', 'string', 'regex:/^(on_account|advance:\d+)$/'],
            'money_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')->where('business_id', $businessId)],
            'refunded_on' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
        ], [
            'refunded_on.before_or_equal' => 'A refund cannot be dated in the future.',
        ]);

        $advance = self::resolveRows([['key' => $data['advance_key'], 'amount' => '0']])[0]['advance'];

        try {
            $refund = $action->handle(
                supplier: Supplier::findOrFail($data['supplier_id']),
                advance: $advance,
                moneyAccount: MoneyAccount::findOrFail($data['money_account_id']),
                refundedOn: $data['refunded_on'],
                amount: (string) $data['amount'],
                by: $request->user(),
                reference: $data['reference'] ?? null,
                note: $data['note'] ?? null,
            );
        } catch (PeriodClosedException|MappingNotFound $e) {
            throw ValidationException::withMessages(['refunded_on' => $e->getMessage()]);
        }

        return back()->with('success', "Refund {$refund->number} recorded.");
    }

    public function reverseRefund(Request $request, SupplierAdvanceRefund $refund, ReverseSupplierAdvanceRefund $action): RedirectResponse
    {
        return $this->reverseFromForm(
            $request,
            fn (string $reason, bool $onOriginalDate) => $action->handle($refund, $request->user(), $reason, $onOriginalDate),
            "Refund {$refund->number} reversed.",
        );
    }

    /**
     * "advance:12" or "on_account" to the advance it names, through the tenant
     * scope: another business's advance is simply not found.
     *
     * @param  array<int, array{key: string, amount: mixed}>  $rows
     * @return array<int, array{advance: SupplierAdvance|null, amount: string}>
     */
    public static function resolveRows(array $rows): array
    {
        return array_map(function (array $row) {
            if ($row['key'] === 'on_account') {
                return ['advance' => null, 'amount' => (string) $row['amount']];
            }

            $advance = SupplierAdvance::query()->find((int) substr($row['key'], strlen('advance:')))
                ?? throw ValidationException::withMessages(['advances' => 'That advance is not available in this business.']);

            return ['advance' => $advance, 'amount' => (string) $row['amount']];
        }, array_values($rows));
    }

    /** @return array<string, mixed> */
    private function row(SupplierAdvance $advance): array
    {
        return [
            'id' => $advance->id,
            'number' => $advance->number,
            'kind' => $advance->kind,
            'kind_label' => $advance->kindLabel(),
            'supplier' => $advance->supplier ? ['id' => $advance->supplier->id, 'name' => $advance->supplier->name] : null,
            'purchase_order' => $advance->purchaseOrder ? ['id' => $advance->purchaseOrder->id, 'po_number' => $advance->purchaseOrder->po_number] : null,
            'money_account' => $advance->moneyAccount?->name,
            // ⚠ toDateString(): the date cast serialises in UTC (Dhaka is +6).
            'paid_on' => $advance->paid_on?->toDateString(),
            'amount' => $advance->money()->toTaka(),
            'open' => $advance->open()->toTaka(),
            'is_reversed' => $advance->isReversed(),
        ];
    }

    /** @return array<string, mixed> */
    private function applicationRow(SupplierAdvanceApplication $application): array
    {
        return [
            'id' => $application->id,
            'number' => $application->number,
            'applied_on' => $application->applied_on?->toDateString(),
            'amount' => $application->money()->toTaka(),
            'tds_covered' => Money::ofTaka((string) $application->tds_covered)->toTaka(),
            'bill' => $application->bill ? ['id' => $application->bill->id, 'bill_number' => $application->bill->bill_number] : null,
            'advance' => $application->advance ? ['id' => $application->advance->id, 'number' => $application->advance->number] : null,
            'source_note' => $application->source_note,
            'is_reversed' => $application->isReversed(),
            'reversal' => $application->reversalSummary(),
        ];
    }

    /** @return array<string, mixed> */
    private function refundRow(SupplierAdvanceRefund $refund): array
    {
        return [
            'id' => $refund->id,
            'number' => $refund->number,
            'refunded_on' => $refund->refunded_on?->toDateString(),
            'amount' => $refund->money()->toTaka(),
            'money_account' => $refund->moneyAccount?->name,
            'advance' => $refund->advance ? ['id' => $refund->advance->id, 'number' => $refund->advance->number] : null,
            'reference' => $refund->reference,
            'is_reversed' => $refund->isReversed(),
            'reversal' => $refund->reversalSummary(),
        ];
    }

    /**
     * @param  array{advance: SupplierAdvance|null, open: Money, date: string, number: string, purchase_order_id: int|null, po_number: string|null}  $row
     * @return array<string, mixed>
     */
    public static function availableRow(array $row): array
    {
        return [
            'key' => SupplierAdvanceBook::keyOf($row['advance']),
            'id' => $row['advance']?->id,
            'number' => $row['number'],
            'date' => $row['date'],
            'open' => $row['open']->toTaka(),
            'purchase_order_id' => $row['purchase_order_id'],
            'po_number' => $row['po_number'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function journalOf(Model $source): ?array
    {
        $journal = Journal::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->whereNull('reverses_journal_id')
            ->with('lines.account:id,code,name')
            ->first();

        if ($journal === null) {
            return null;
        }

        return $journal->only(['id', 'journal_number', 'memo']) + [
            'lines' => $journal->lines->map(fn ($line) => [
                'id' => $line->id,
                'account' => $line->account ? "{$line->account->code} {$line->account->name}" : null,
                'memo' => $line->memo,
                'amount_minor' => (int) $line->amount_minor,
            ])->values()->all(),
        ];
    }

    private function moneyAccounts(): mixed
    {
        // ⭐ F20: each with what is in it now, as on the payment form.
        return tap(MoneyAccount::query()->handled()->withLedger()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'business_id', 'name', 'type', 'account_id']), function ($accounts) {
                $balances = MoneyAccountBalances::for($accounts);
                $accounts->each(fn (MoneyAccount $account) => $account->setAttribute('balance', $balances[$account->id] ?? null)
                    ->makeHidden(['business_id', 'account_id']));
            });
    }

    private function vatApplies(Carbon $on): bool
    {
        return Business::query()->find(app(BusinessContext::class)->id())?->vatAppliesOn($on) ?? false;
    }
}
