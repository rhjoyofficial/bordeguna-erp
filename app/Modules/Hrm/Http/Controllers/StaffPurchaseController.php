<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\ReplaceStaffPurchaseDefect;
use App\Modules\Hrm\Actions\StaffPurchasePricing;
use App\Modules\Hrm\Enums\PayrollAdjustmentType;
use App\Modules\Hrm\Exceptions\StaffPurchaseNeedsReapproval;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\StaffPurchaseEvent;
use App\Modules\Hrm\Models\StaffPurchaseLine;
use App\Modules\Hrm\Models\StaffPurchaseReplacement;
use App\Modules\Hrm\Models\StaffPurchaseRequest;
use App\Modules\Hrm\Support\PayrollCycle;
use App\Modules\Hrm\Support\StaffPurchaseAuditDetails;
use App\Modules\Hrm\Support\StaffPurchaseLimits;
use App\Modules\Hrm\Support\StaffPurchasePolicy;
use App\Modules\Inventory\Actions\ReleaseReservation;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\RecordCounterSale;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\CustomerReceiptAllocation;
use App\Modules\Sales\Support\ProductPricing;
use App\Support\DocumentNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Staff Product Benefit (office order BDG/HR/09/01/2026, effective
 * 1 Sep 2026), rebuilt for Phase 2 on ChatGPT's ruling of 17 Oct 2026
 * (APPROVE WITH CHANGES) and decisions D1-D8:
 *
 * - ⭐ The staff price is the COST of the FIFO batch reserved at approval,
 *   frozen on the line (StaffPurchasePricing). Before approval the screen
 *   shows an ESTIMATE from the current FIFO head, labelled so. No hand-typed
 *   STAFF price row decides anything any more (the group stays for old rows).
 * - Each employee buys as a customer of their own (Customer::forEmployee);
 *   the handover is a real counter sale to THAT customer, flagged staff_sale,
 *   out of the retail KPIs, present in the ledger and the audit tab.
 * - Limits exactly as the office order, checked at request, approval AND
 *   handover under a per-employee-month lock (StaffPurchaseLimits): one unit
 *   per SKU a month at staff price (a further unit at the reference price),
 *   Tk 3,000 a month at the reference price, approved reservations count,
 *   cancelled and rejected never.
 * - Salary deduction becomes a generated payroll adjustment, and at payroll
 *   posting an invoice-linked NON-CASH offset (SettleStaffPurchaseFromPayroll).
 * - The cost basis behind a price is for `hrm.staff_purchase.cost_basis.view`
 *   holders only (D3). No returns; a defect is replaced at zero charge (D7).
 */
class StaffPurchaseController extends Controller
{
    /** Kept for the old screens and tests; the policy file is the source. */
    public const MONTHLY_MRP_CAP = StaffPurchasePolicy::MONTHLY_CAP_AT_REFERENCE;

    public function __construct(
        private readonly StaffPurchasePricing $pricing,
        private readonly ReleaseReservation $release,
    ) {}

    // ── The staff side ───────────────────────────────────────────────────

    public function mine(Request $request): Response
    {
        $employee = $this->employeeOf($request);
        $businessId = app(BusinessContext::class)->id();

        return Inertia::render('hrm/staff-purchase', [
            'products' => $this->benefitProducts($businessId, $employee),
            'allowance' => [
                'limit' => StaffPurchasePolicy::MONTHLY_CAP_AT_REFERENCE,
                'used' => StaffPurchaseLimits::referenceUsed($employee->id, Carbon::now()),
            ],
            'requests' => StaffPurchaseRequest::query()
                ->where('employee_id', $employee->id)
                ->with('invoice')->orderByDesc('id')->limit(20)->get()
                ->pipe(fn ($rows) => $this->presentAll($rows, seesCostBasis: false)),
        ]);
    }

    public function apply(Request $request): RedirectResponse
    {
        $employee = $this->employeeOf($request);
        $businessId = app(BusinessContext::class)->id();

        // HRM-005 (14 Oct 2026): a benefit of employment, so only where the
        // person is employed.
        if (! $employee->isEmployedIn($businessId)) {
            return back()->with('error', Employee::notEmployedHere());
        }

        $data = $request->validate([
            'payment_mode' => ['required', Rule::in(StaffPurchaseRequest::PAYMENT_MODES)],
            // ⭐ D-D (Joy, 18 Oct 2026): "paid it also instant or credit for some
            // days". Pay later: 7, 15 or 30 days, 15 when not chosen.
            'credit_days' => ['nullable', 'integer', Rule::in(StaffPurchaseRequest::CREDIT_DAY_CHOICES)],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            // ⚠ distinct (bug hunt 16 Oct 2026): one unit per product a month
            // is checked against SAVED requests only, so one request naming a
            // product twice got two units at the staff price.
            'lines.*.product_id' => ['required', 'integer', 'distinct'],
        ], [
            'lines.*.product_id.distinct' => 'Each product once per request: one unit per product a month.',
        ]);

        $products = $this->benefitProducts($businessId, $employee)->keyBy('id');
        $warehouse = $this->warehouse();
        $month = Carbon::now();

        $number = DB::transaction(function () use ($employee, $businessId, $data, $products, $warehouse, $month, $request) {
            // ⚠⚠ The month lock first, then every check (StaffPurchaseLimits):
            // two requests for the last of an allowance are serialised here.
            StaffPurchaseLimits::lockMonth($employee, $month);

            $lines = [];

            foreach ($data['lines'] as $line) {
                $product = $products[$line['product_id']] ?? null;

                if ($product === null) {
                    throw ValidationException::withMessages([
                        'lines' => 'That product is not part of the staff benefit (no reference price, or no stock to issue).',
                    ]);
                }

                // Rule 3: the month's first unit of a SKU is at the staff price;
                // a further unit is priced at the reference price automatically.
                $atStaffPrice = StaffPurchaseLimits::unitsAtStaffPrice($employee->id, $product['id'], $month)
                    < StaffPurchasePolicy::UNITS_PER_SKU_PER_MONTH_AT_STAFF_PRICE;

                $lines[] = [
                    'product_id' => $product['id'],
                    'product_name' => $product['name'],
                    'quantity' => '1',
                    'cap_reference_price' => $product['reference_price'],
                    'cap_reference_label' => $product['reference_label'],
                    'priced_at' => $atStaffPrice ? StaffPurchaseLine::PRICED_AT_STAFF : StaffPurchaseLine::PRICED_AT_REFERENCE,
                    'estimate' => $atStaffPrice ? $product['staff_price'] : $product['reference_price'],
                ];
            }

            StaffPurchaseLimits::assert($employee, $businessId, $month, $lines, null, 'request');

            $row = StaffPurchaseRequest::create([
                'business_id' => $businessId,
                'number' => $this->nextNumber($businessId),
                'employee_id' => $employee->id,
                'warehouse_id' => $warehouse->id,
                'payment_mode' => $data['payment_mode'],
                'credit_days' => $data['payment_mode'] === StaffPurchaseRequest::CREDIT
                    ? (int) ($data['credit_days'] ?? StaffPurchaseRequest::DEFAULT_CREDIT_DAYS)
                    : null,
                'status' => StaffPurchaseRequest::PENDING,
                'note' => $data['note'] ?? null,
            ]);

            foreach ($lines as $line) {
                $row->lines()->create([
                    'business_id' => $businessId,
                    'product_id' => $line['product_id'],
                    'quantity' => $line['quantity'],
                    // An ESTIMATE until approval freezes the reserved batch cost
                    // (price_frozen_at stays null until then).
                    'staff_price' => $line['estimate'],
                    'mrp' => $line['cap_reference_price'],
                    'cap_reference_price' => $line['cap_reference_price'],
                    'cap_reference_label' => $line['cap_reference_label'],
                    'priced_at' => $line['priced_at'],
                ]);
            }

            $row->record('applied', $request->user(), [
                'payment_mode' => $data['payment_mode'],
                'credit_days' => $row->credit_days,
                'lines' => array_map(fn (array $l) => [
                    'product_id' => $l['product_id'], 'priced_at' => $l['priced_at'],
                    'estimate' => $l['estimate'], 'reference_price' => $l['cap_reference_price'],
                ], $lines),
            ]);

            return $row->number;
        });

        return back()->with('success', "Application {$number} submitted. The price is an estimate until it is approved; the store hands over once it is.");
    }

    // ── The authority's side ─────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $this->authorize('hrm.employees.manage');

        $businessId = app(BusinessContext::class)->id();
        $seesCostBasis = $request->user()->can(StaffPurchasePolicy::COST_BASIS_PERMISSION);
        $month = $this->monthOf($request);

        return Inertia::render('hrm/staff-purchases', [
            'tab' => in_array($request->string('tab')->toString(), ['requests', 'statement', 'audit'], true) ? $request->string('tab')->toString() : 'requests',
            'month' => $month->format('Y-m'),
            'requests' => StaffPurchaseRequest::query()
                ->where('business_id', $businessId)
                ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'needs_reapproval' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")
                ->with('invoice')->orderByDesc('id')->limit(100)->get()
                ->pipe(fn ($rows) => $this->presentAll($rows, $seesCostBasis)),
            'moneyAccounts' => MoneyAccount::query()->handled()->where('is_active', true)
                ->orderBy('name')->get(['id', 'name', 'type']),
            'canSeeCostBasis' => $seesCostBasis,
            'statement' => fn () => $this->statement($businessId, $month),
            'audit' => fn () => $this->audit($businessId, $month),
        ]);
    }

    /**
     * ⭐ APPROVAL = the moment the price becomes real. The FIFO batch is
     * reserved in the approved warehouse and its cost frozen on the line.
     * A request sent back for re-approval comes through here again and gets
     * the new price (the old one stays in the audit).
     */
    public function approve(Request $request, int $id): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');
        $row = $this->requestRow($id, [StaffPurchaseRequest::PENDING, StaffPurchaseRequest::NEEDS_REAPPROVAL]);

        $data = $request->validate([
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('business_id', $row->business_id)],
        ]);

        try {
            DB::transaction(function () use ($row, $data, $request) {
                $row = $this->requestRow($row->id, [StaffPurchaseRequest::PENDING, StaffPurchaseRequest::NEEDS_REAPPROVAL], lock: true);
                $employee = Employee::findOrFail($row->employee_id);
                $month = Carbon::parse($row->created_at);
                $warehouse = isset($data['warehouse_id']) ? Warehouse::findOrFail($data['warehouse_id']) : $this->warehouse($row->warehouse_id);
                $before = $row->lines->mapWithKeys(fn (StaffPurchaseLine $l) => [$l->id => (string) $l->staff_price])->all();

                StaffPurchaseLimits::lockMonth($employee, $month);
                StaffPurchaseLimits::assert($employee, $row->business_id, $month, $this->limitLines($row), $row->id, 'approval');

                // A re-approval: whatever was still held is let go and re-reserved.
                $this->release->handle($row);
                $this->pricing->reserveAndFreeze($row, $warehouse, $request->user());

                $row->forceFill([
                    'status' => StaffPurchaseRequest::APPROVED,
                    'decided_by' => $request->user()->id,
                    'decided_at' => now(),
                    'reapproval_reason' => null,
                ])->save();

                $row->record('approved', $request->user(), [
                    'warehouse_id' => $warehouse->id,
                    'lines' => $row->lines()->get()->map(fn (StaffPurchaseLine $l) => [
                        'line_id' => $l->id, 'product_id' => $l->product_id, 'priced_at' => $l->priced_at,
                        'staff_price' => (string) $l->staff_price, 'was' => $before[$l->id] ?? null,
                        'reference_price' => (string) $l->cap_reference_price,
                    ])->all(),
                ]);
            });
        } catch (InsufficientStockException $e) {
            throw ValidationException::withMessages(['request' => 'Not enough stock free of other reservations to approve this: '.$e->getMessage()]);
        }

        return back()->with('success', 'Approved at the reserved batch cost. The store can hand over now.');
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');
        $row = $this->requestRow($id, [StaffPurchaseRequest::PENDING, StaffPurchaseRequest::NEEDS_REAPPROVAL]);

        $data = $request->validate(['decision_note' => ['required', 'string', 'max:500']]);

        DB::transaction(function () use ($row, $data, $request) {
            $this->release->handle($row);
            $row->forceFill([
                'status' => StaffPurchaseRequest::REJECTED, 'decided_by' => $request->user()->id,
                'decided_at' => now(), 'decision_note' => $data['decision_note'],
            ])->save();
            $row->record('rejected', $request->user(), ['reason' => $data['decision_note']]);
        });

        return back()->with('success', 'Rejected, reason kept.');
    }

    /** An approved application withdrawn before the handover: the reservation is released and nothing counts. */
    public function cancel(Request $request, int $id): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');
        $row = $this->requestRow($id, [StaffPurchaseRequest::APPROVED, StaffPurchaseRequest::NEEDS_REAPPROVAL]);

        $data = $request->validate(['decision_note' => ['required', 'string', 'max:500']]);

        DB::transaction(function () use ($row, $data, $request) {
            $this->release->handle($row);
            $row->forceFill([
                'status' => StaffPurchaseRequest::CANCELLED, 'decided_by' => $request->user()->id,
                'decided_at' => now(), 'decision_note' => $data['decision_note'],
            ])->save();
            $row->record('cancelled', $request->user(), ['reason' => $data['decision_note']]);
        });

        return back()->with('success', 'Cancelled; the reserved stock is free again.');
    }

    /**
     * ⚠ THE handover. Only an APPROVED application; the reserved slices are
     * checked once more, then it becomes a real counter sale to the employee's
     * own customer at the FROZEN prices, issued from the reserved batches.
     * Cash/MFS/bank collects into the chosen account; salary deduction leaves
     * the invoice on account and writes a GENERATED payroll adjustment.
     */
    public function fulfill(Request $request, int $id, RecordCounterSale $action): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');
        $row = $this->requestRow($id, [StaffPurchaseRequest::APPROVED]);
        // ⚠ HRM-003: the lines are read BEFORE the locked re-read inside the
        // transaction, so a status flipped by another worker in between is seen.
        $row->load('lines');

        $data = $request->validate([
            'money_account_id' => [
                // ⭐ D-D: no money at the handover for a salary deduction or a credit purchase.
                Rule::requiredIf($row->isPaidAtHandover()),
                'nullable', 'integer', Rule::exists('money_accounts', 'id')->where('business_id', app(BusinessContext::class)->id()),
            ],
        ]);

        try {
            DB::transaction(function () use ($row, $data, $action, $request) {
                // ⚠⚠ HRM-003 (audit 14 Oct 2026). Locked, then checked again, so
                // a double press or two clerks make one sale, not two.
                $row = $this->requestRow($row->id, [StaffPurchaseRequest::APPROVED], lock: true);
                $employee = Employee::findOrFail($row->employee_id);
                $month = Carbon::parse($row->created_at);
                $warehouse = $this->warehouse($row->warehouse_id);

                // The limits once more (ruling Q3: never let a stale approval
                // bypass them), then the reserved slices.
                StaffPurchaseLimits::lockMonth($employee, $month);
                StaffPurchaseLimits::assert($employee, $row->business_id, $month, $this->limitLines($row), $row->id, 'handover');
                $this->pricing->assertReservationsHold($row);

                $customer = Customer::forEmployee($employee);
                $approver = User::query()->find($row->decided_by) ?? $request->user();
                $lines = $row->lines()->with('product')->get();
                $staffTotal = (float) $lines->sum(fn (StaffPurchaseLine $l) => (float) $l->staff_price * (float) $l->quantity);

                $sale = $action->handle(
                    customer: $customer,
                    warehouse: $warehouse,
                    lines: $lines->map(fn (StaffPurchaseLine $line) => [
                        'product' => $line->product,
                        'quantity' => (string) $line->quantity,
                        // The FROZEN price from approval, never re-resolved.
                        'unitPrice' => (string) $line->staff_price,
                        // The trail: list = the cap reference price, so
                        // "reference minus price" is one query (plan E2).
                        'listPrice' => (string) $line->cap_reference_price,
                        'overrideReason' => "Staff purchase {$row->number}: ".($line->isAtStaffPrice() ? 'reserved batch cost' : 'reference price').' (office order BDG/HR/09/01/2026)',
                        'overriddenBy' => $approver,
                        'isStaffSale' => true,
                        'reservedFor' => $row,
                    ])->all(),
                    createdBy: $request->user(),
                    moneyAccount: $row->isPaidAtHandover()
                        ? MoneyAccount::findOrFail($data['money_account_id'])
                        : null,
                    reference: $row->number,
                    note: "Staff purchase {$row->number} - {$employee->fullName()} ({$employee->employee_code})",
                    // ⭐ D-D (18 Oct 2026): on credit, the invoice stays open on
                    // the employee's own account, due the chosen days from today,
                    // and ages like any receivable.
                    dueDate: $row->isCredit() ? Carbon::today()->addDays($row->credit_days ?? StaffPurchaseRequest::DEFAULT_CREDIT_DAYS) : null,
                );

                // The hard guard: what left must be what was priced.
                $issued = $sale->delivery->lines->flatMap(fn ($line) => $line->movements->pluck('batch_id'))->all();
                $this->pricing->assertIssuedFromReserved($row, $issued);
                $this->release->handle($row);

                if ($row->payment_mode === 'salary_deduction') {
                    // Generated, not re-approved: the decision happened at approve.
                    PayrollAdjustment::create([
                        'business_id' => $row->business_id,
                        'employee_id' => $employee->id,
                        'type' => PayrollAdjustmentType::Deduction,
                        'amount' => $staffTotal,
                        'payroll_period_id' => $this->deductionPeriod()->id,
                        'reason' => "Staff purchase {$row->number}",
                        'source_key' => "staff_purchase:{$row->id}",
                        'requested_by' => $request->user()->id,
                    ]);
                }

                $row->forceFill([
                    'status' => StaffPurchaseRequest::FULFILLED,
                    'customer_id' => $customer->id,
                    'fulfilled_by' => $request->user()->id,
                    'fulfilled_at' => now(),
                    'sales_invoice_id' => $sale->invoice->id,
                ])->save();

                $row->record('handed_over', $request->user(), [
                    'invoice_id' => $sale->invoice->id,
                    'invoice_number' => $sale->invoice->invoice_number,
                    'total' => number_format($staffTotal, 2, '.', ''),
                    'payment_mode' => $row->payment_mode,
                    'due_date' => $row->isCredit() ? $sale->invoice->due_date?->toDateString() : null,
                    'batches' => array_values(array_unique(array_map('intval', $issued))),
                ]);
            });
        } catch (StaffPurchaseNeedsReapproval $e) {
            // ⭐ Never a silent recompute (ruling Q1): the transaction rolled
            // back; the request goes back to the approver with the reason and
            // the new estimate on the screen.
            $this->sendBackForReapproval($row, $e->getMessage(), $request->user());

            throw ValidationException::withMessages(['request' => "Not handed over. {$e->getMessage()} Application {$row->number} needs re-approval at the current batch cost."]);
        } catch (InsufficientStockException $e) {
            $this->sendBackForReapproval($row, $e->getMessage(), $request->user());

            throw ValidationException::withMessages(['request' => "Not handed over: {$e->getMessage()} Application {$row->number} needs re-approval."]);
        }

        return back()->with('success', "Handed over as sale {$row->number} at the approved price.");
    }

    /**
     * ⭐ D-D (18 Oct 2026): an open credit purchase settled by salary
     * deduction instead of a receipt. Writes the same generated payroll
     * adjustment a salary-deduction handover writes, for what is still
     * outstanding on the invoice; the payroll then offsets it (Dr 2130 /
     * Cr 1140), capped and carried forward as always. Once only.
     */
    public function toSalary(Request $request, int $id): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');

        $number = DB::transaction(function () use ($id, $request) {
            $row = $this->requestRow($id, [StaffPurchaseRequest::FULFILLED], lock: true);

            if (! $row->isCredit() || $row->credit_to_salary_at !== null) {
                throw ValidationException::withMessages(['request' => "Application {$row->number} is not an open credit purchase."]);
            }

            $outstanding = $row->invoice?->outstanding();

            if ($outstanding === null || $outstanding->minor <= 0) {
                throw ValidationException::withMessages(['request' => "Nothing is outstanding on application {$row->number}."]);
            }

            PayrollAdjustment::create([
                'business_id' => $row->business_id,
                'employee_id' => $row->employee_id,
                'type' => PayrollAdjustmentType::Deduction,
                'amount' => $outstanding->toTaka(),
                'payroll_period_id' => $this->deductionPeriod()->id,
                'reason' => "Staff purchase {$row->number} (credit moved to salary)",
                'source_key' => "staff_purchase:{$row->id}",
                'requested_by' => $request->user()->id,
            ]);

            $row->forceFill(['credit_to_salary_at' => now()])->save();
            $row->record('credit_to_salary', $request->user(), ['amount' => $outstanding->toTaka()]);

            return $row->number;
        });

        return back()->with('success', "What is still owed on {$number} will be deducted from the next salary.");
    }

    /** ⭐ D7: a proven defect is replaced at zero charge, linked to the original request. */
    public function replace(Request $request, int $id, ReplaceStaffPurchaseDefect $action): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');
        $row = $this->requestRow($id, [StaffPurchaseRequest::FULFILLED]);

        $data = $request->validate([
            'line_id' => ['required', 'integer', Rule::exists('staff_purchase_lines', 'id')->where('request_id', $row->id)],
            'reason' => ['required', 'string', 'max:1000'],
            'evidence_note' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $action->handle($row, StaffPurchaseLine::findOrFail($data['line_id']), $data['reason'], $data['evidence_note'], $request->user());
        } catch (InsufficientStockException $e) {
            throw ValidationException::withMessages(['line' => 'No stock to replace from: '.$e->getMessage()]);
        }

        return back()->with('success', 'Replaced at no charge; the defective unit is written off and the replacement is linked to the application.');
    }

    // ── Reports (plan E7) ────────────────────────────────────────────────

    /**
     * Monthly statement: per employee, every line handed over in the month
     * with its reference price, staff price, difference, what was settled
     * through payroll and at the till, and what is still outstanding (D8:
     * the tax evidence, no tax logic).
     */
    private function statement(int $businessId, Carbon $month): array
    {
        $requests = StaffPurchaseRequest::query()
            ->where('business_id', $businessId)
            ->where('status', StaffPurchaseRequest::FULFILLED)
            ->whereBetween('fulfilled_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->with(['lines', 'employee', 'invoice'])
            ->orderBy('fulfilled_at')->get();

        $products = Product::withoutGlobalScopes()->whereIn('id', $requests->flatMap->lines->pluck('product_id')->unique())->pluck('name', 'id');
        $invoiceIds = $requests->pluck('sales_invoice_id')->filter()->all();

        $settled = CustomerReceiptAllocation::query()
            ->whereIn('sales_invoice_id', $invoiceIds)
            ->join('customer_receipts', 'customer_receipts.id', '=', 'customer_receipt_allocations.customer_receipt_id')
            ->groupBy('customer_receipt_allocations.sales_invoice_id', 'customer_receipts.kind')
            ->selectRaw('customer_receipt_allocations.sales_invoice_id as invoice_id, customer_receipts.kind, SUM(customer_receipt_allocations.amount) as amount')
            ->get()
            ->groupBy('invoice_id');

        $byEmployee = [];

        foreach ($requests as $row) {
            $key = $row->employee_id;
            $byEmployee[$key] ??= [
                'employee' => $row->employee?->fullName(), 'employee_code' => $row->employee?->employee_code,
                'lines' => [], 'reference_total' => 0.0, 'staff_total' => 0.0, 'difference' => 0.0,
                'settled_payroll' => 0.0, 'settled_cash' => 0.0, 'outstanding' => 0.0,
                // ⭐ D-D (18 Oct 2026): what is still open on credit, and when it falls due.
                'credit_outstanding' => 0.0, 'credit_due' => [],
            ];

            $payroll = (float) ($settled->get($row->sales_invoice_id)?->firstWhere('kind', CustomerReceipt::KIND_PAYROLL_OFFSET)?->amount ?? 0);
            $cash = (float) ($settled->get($row->sales_invoice_id)?->firstWhere('kind', CustomerReceipt::KIND_CASH)?->amount ?? 0);
            $invoiceTotal = (float) ($row->invoice?->total ?? 0);

            foreach ($row->lines as $line) {
                $reference = (float) $line->cap_reference_price * (float) $line->quantity;
                $price = (float) $line->staff_price * (float) $line->quantity;
                $byEmployee[$key]['lines'][] = [
                    'number' => $row->number, 'date' => $row->fulfilled_at?->toDateString(),
                    'product' => $products[$line->product_id] ?? null, 'quantity' => (float) $line->quantity,
                    'priced_at' => $line->priced_at, 'reference_label' => $line->cap_reference_label,
                    'reference_price' => $reference, 'staff_price' => $price, 'difference' => $reference - $price,
                    'payment_mode' => $row->payment_mode,
                    'due_date' => $row->isCredit() ? $row->invoice?->due_date?->toDateString() : null,
                ];
                $byEmployee[$key]['reference_total'] += $reference;
                $byEmployee[$key]['staff_total'] += $price;
                $byEmployee[$key]['difference'] += $reference - $price;
            }

            $byEmployee[$key]['settled_payroll'] += $payroll;
            $byEmployee[$key]['settled_cash'] += $cash;
            $open = max(0.0, $invoiceTotal - $payroll - $cash);
            $byEmployee[$key]['outstanding'] += $open;

            if ($row->isCredit() && $open > 0) {
                $byEmployee[$key]['credit_outstanding'] += $open;
                $byEmployee[$key]['credit_due'][] = [
                    'number' => $row->number,
                    'amount' => $open,
                    'due_date' => $row->invoice?->due_date?->toDateString(),
                    'overdue' => $row->invoice?->due_date !== null && $row->invoice->due_date->lt(Carbon::today()),
                    'to_salary' => $row->credit_to_salary_at !== null,
                ];
            }
        }

        return array_values($byEmployee);
    }

    /** Audit: every step in the month, newest first, with who and the price. */
    private function audit(int $businessId, Carbon $month): array
    {
        $events = StaffPurchaseEvent::query()
            ->where('business_id', $businessId)
            ->whereBetween('created_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->with(['request.employee', 'actor:id,name'])
            ->orderByDesc('id')->limit(500)->get();

        // P17 (18 Oct 2026): the details as labelled words, not the stored JSON.
        $summaries = StaffPurchaseAuditDetails::for($events);

        return $events->map(fn (StaffPurchaseEvent $event) => [
            'id' => $event->id,
            'at' => $event->created_at?->toDateTimeString(),
            'action' => $event->action,
            'actor' => $event->actor?->name,
            'number' => $event->request?->number,
            'employee' => $event->request?->employee?->fullName(),
            'summary' => $summaries[$event->id] ?? [],
        ])->values()->all();
    }

    // ── The shared arithmetic ────────────────────────────────────────────

    /**
     * Products in the benefit: active, stocked, with a cap reference price
     * and stock to issue in the default warehouse. The staff price shown is
     * an ESTIMATE from the FIFO head not held for somebody else.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function benefitProducts(int $businessId, Employee $employee): Collection
    {
        // ⚠ A business with no warehouse yet has nothing to issue from: an empty
        // list, not a 404 on a self-service page.
        $warehouse = Warehouse::query()->operational()->orderByDesc('is_default')->first();

        if ($warehouse === null) {
            return collect();
        }

        $month = Carbon::now();

        $products = Product::query()->where('is_active', true)->stocked()
            ->orderBy('name')->get(['id', 'name', 'sku', 'mrp']);

        $estimates = $this->pricing->estimateMany($products->pluck('id')->all(), $warehouse);

        return $products
            ->filter(fn (Product $product) => isset($estimates[$product->id]))
            ->map(function (Product $product) use ($estimates, $employee, $month) {
                $reference = ProductPricing::capReference($product);

                if ($reference === null) {
                    return null;
                }

                $takenAtStaffPrice = StaffPurchaseLimits::unitsAtStaffPrice($employee->id, $product->id, $month)
                    >= StaffPurchasePolicy::UNITS_PER_SKU_PER_MONTH_AT_STAFF_PRICE;

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'staff_price' => (float) $estimates[$product->id],
                    'is_estimate' => true,
                    'mrp' => (float) $reference['amount'],
                    'reference_price' => $reference['amount'],
                    'reference_label' => $reference['label'],
                    // The month's unit at the staff price is used: a further
                    // unit is priced at the reference price (rule 3).
                    'next_unit_at_reference' => $takenAtStaffPrice,
                ];
            })
            ->filter()
            ->values();
    }

    /** @return array<int, array{product_id: int, product_name: string, quantity: string, cap_reference_price: string|null, priced_at: string}> */
    private function limitLines(StaffPurchaseRequest $row): array
    {
        $names = Product::withoutGlobalScopes()->whereIn('id', $row->lines->pluck('product_id'))->pluck('name', 'id');

        return $row->lines->map(fn (StaffPurchaseLine $line) => [
            'product_id' => (int) $line->product_id,
            'product_name' => (string) ($names[$line->product_id] ?? "product #{$line->product_id}"),
            'quantity' => (string) $line->quantity,
            'cap_reference_price' => $line->cap_reference_price === null ? null : (string) $line->cap_reference_price,
            'priced_at' => $line->priced_at,
        ])->all();
    }

    private function sendBackForReapproval(StaffPurchaseRequest $row, string $reason, User $by): void
    {
        DB::transaction(function () use ($row, $reason, $by) {
            $this->release->handle($row);
            $row->forceFill(['status' => StaffPurchaseRequest::NEEDS_REAPPROVAL, 'reapproval_reason' => $reason])->save();
            $row->record('needs_reapproval', $by, [
                'reason' => $reason,
                'old_prices' => $row->lines()->get()->mapWithKeys(fn (StaffPurchaseLine $l) => [$l->id => (string) $l->staff_price])->all(),
            ]);
        });
    }

    /** The warehouse goods are issued from: the request's own, else the default. */
    private function warehouse(?int $id = null): Warehouse
    {
        $warehouse = ($id === null ? null : Warehouse::query()->whereKey($id)->first())
            ?? Warehouse::query()->operational()->orderByDesc('is_default')->first();

        if ($warehouse === null) {
            throw ValidationException::withMessages(['lines' => 'No warehouse is set up to issue from yet.']);
        }

        return $warehouse;
    }

    private function monthOf(Request $request): Carbon
    {
        $month = $request->string('month')->toString();

        return preg_match('/^\d{4}-\d{2}$/', $month) === 1 ? Carbon::createFromFormat('Y-m', $month)->startOfMonth() : Carbon::now()->startOfMonth();
    }

    /**
     * The payroll month a salary deduction belongs to.
     *
     * ⚠⚠ Found 3 Oct 2026: this picked the CALENDAR month, so a hand-over on
     * 24-31 October landed in the October period, which ended on the 23rd and
     * may already be paid. The cycle that contains today is the right one; if
     * that run has already been paid out, the next month's.
     */
    private function deductionPeriod(): PayrollPeriod
    {
        $cycle = PayrollCycle::containing(now());
        $period = PayrollPeriod::forMonth($cycle['year'], $cycle['month']);

        if ($period->latestRun()?->status?->isPaidOut() === true) {
            $next = Carbon::create($cycle['year'], $cycle['month'], 1)->addMonth();
            $period = PayrollPeriod::forMonth((int) $next->year, (int) $next->month);
        }

        return $period;
    }

    private function employeeOf(Request $request): Employee
    {
        $employee = Employee::query()->where('user_id', $request->user()->id)->first();
        abort_if($employee === null, 403, 'Only staff with an employee record can use the benefit.');

        return $employee;
    }

    /** @param  array<int, string>  $statuses */
    private function requestRow(int $id, array $statuses, bool $lock = false): StaffPurchaseRequest
    {
        $row = StaffPurchaseRequest::query()
            ->where('business_id', app(BusinessContext::class)->id())
            ->whereKey($id)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
        abort_if($row === null, 404);

        if (! in_array($row->status, $statuses, true)) {
            throw ValidationException::withMessages([
                'request' => "Application {$row->number} is {$row->status}, not ".implode(' or ', $statuses).'.',
            ]);
        }

        return $row;
    }

    /**
     * The requests as the screens show them.
     *
     * ⚠ Bug hunt 16 Oct 2026: this asked, per row of up to 100, for the
     * employee twice, the lines, each line's product and a sum (400 queries and
     * more). The employees, lines, products, batches and replacements are read
     * once for all of them.
     *
     * ⭐ D3: the cost basis (batch, unit cost, snapshot) only for holders of
     * `hrm.staff_purchase.cost_basis.view`; everybody else sees the price.
     *
     * @param  Collection<int, StaffPurchaseRequest>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function presentAll(Collection $rows, bool $seesCostBasis): Collection
    {
        $employees = Employee::query()->whereIn('id', $rows->pluck('employee_id')->unique())->get()->keyBy('id');
        $lines = StaffPurchaseLine::query()->whereIn('request_id', $rows->pluck('id'))->get()->groupBy('request_id');
        $products = Product::withoutGlobalScopes()->whereIn('id', $lines->flatten()->pluck('product_id')->unique())->pluck('name', 'id');
        $batches = $seesCostBasis
            ? StockBatch::query()->whereIn('id', $lines->flatten()->pluck('cost_basis_batch_id')->filter()->unique())->pluck('batch_number', 'id')
            : collect();
        $replaced = StaffPurchaseReplacement::query()->whereIn('request_id', $rows->pluck('id'))->get()->groupBy('line_id');

        return $rows->map(function (StaffPurchaseRequest $row) use ($employees, $lines, $products, $batches, $replaced, $seesCostBasis) {
            $employee = $employees->get($row->employee_id);
            $own = $lines->get($row->id, collect());

            return [
                'id' => $row->id,
                'number' => $row->number,
                'employee' => $employee?->fullName(),
                'employee_code' => $employee?->employee_code,
                'payment_mode' => $row->payment_mode,
                // ⭐ D-D (18 Oct 2026): the credit terms, the due date once handed
                // over, and whether the authority can still move it to salary.
                'credit_days' => $row->credit_days,
                'due_date' => $row->isCredit() ? $row->invoice?->due_date?->toDateString() : null,
                'credit_outstanding' => $row->isCredit() && $row->status === StaffPurchaseRequest::FULFILLED ? (float) ($row->invoice?->outstanding()->toTaka() ?? 0) : null,
                'credit_to_salary' => $row->credit_to_salary_at !== null,
                'status' => $row->status,
                'decision_note' => $row->decision_note,
                'reapproval_reason' => $row->reapproval_reason,
                'applied_at' => $row->created_at,
                'warehouse_id' => $row->warehouse_id,
                'lines' => $own->map(fn (StaffPurchaseLine $line) => [
                    'id' => $line->id,
                    'product' => $products[$line->product_id] ?? null,
                    'staff_price' => (float) $line->staff_price,
                    'is_estimate' => ! $line->isFrozen(),
                    'priced_at' => $line->priced_at,
                    'mrp' => (float) ($line->cap_reference_price ?? $line->mrp),
                    'reference_price' => (float) ($line->cap_reference_price ?? $line->mrp),
                    'reference_label' => $line->cap_reference_label ?? 'mrp',
                    'replaced' => $replaced->has($line->id),
                    'cost_basis' => $seesCostBasis && $line->isFrozen() ? [
                        'batch' => $batches[$line->cost_basis_batch_id] ?? ($line->cost_basis_batch_id ? "#{$line->cost_basis_batch_id}" : null),
                        'unit_cost' => $line->cost_snapshot['unit_cost'] ?? null,
                        'basis' => $line->cost_snapshot['basis'] ?? null,
                        'frozen_at' => $line->cost_snapshot['frozen_at'] ?? null,
                        'batches' => $line->cost_snapshot['batches'] ?? [],
                    ] : null,
                ])->values(),
                'total_staff_price' => (float) $own->sum(fn (StaffPurchaseLine $line) => (float) $line->staff_price * (float) $line->quantity),
                'sales_invoice_id' => $row->sales_invoice_id,
            ];
        })->values();
    }

    private function nextNumber(int $businessId): string
    {
        $business = Business::withoutGlobalScopes()->findOrFail($businessId);
        $prefix = 'SPB-'.FiscalYear::containing(Carbon::now(), $business)->label().'-';

        // ⚠ Numeric, not text order (14 Oct 2026, SAL-001): see DocumentNumber.
        return DocumentNumber::next(DB::table('staff_purchase_requests'), 'number', $prefix);
    }
}
