<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Enums\PayrollAdjustmentType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Support\PayrollCycle;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\RecordCounterSale;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use App\Support\DocumentNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Staff Product Benefit (office order BDG/HR/09/01/2026, effective
 * 1 Sep 2026), implemented exactly as signed:
 *
 * - A confidential STAFF price list (the STAFF price group). Staff see
 *   ONLY the staff price and the MRP - never the trade prices.
 * - Monthly cap: 3,000 taka COUNTED AT MRP, per calendar month, no
 *   rollover. One unit per SKU per month at staff price.
 * - ERP-only: apply here, the designated authority approves, the store
 *   hands over against the approved application - which becomes a REAL
 *   counter sale at the staff price (a normal sale, per the accountant).
 * - Payment: cash/MFS/bank at the till, or salary deduction - which
 *   becomes a GENERATED payroll adjustment: the human decision already
 *   happened at approval, exactly like a generated arrear.
 * - No returns through this path; a defect is exchanged at the counter.
 */
class StaffPurchaseController extends Controller
{
    public const MONTHLY_MRP_CAP = 3000.0;

    // ── The staff side ───────────────────────────────────────────────────

    public function mine(Request $request): Response
    {
        $employee = $this->employeeOf($request);
        $businessId = app(BusinessContext::class)->id();

        return Inertia::render('hrm/staff-purchase', [
            'products' => $this->benefitProducts($businessId),
            'allowance' => [
                'limit' => self::MONTHLY_MRP_CAP,
                'used' => $this->mrpUsedThisMonth($employee->id),
            ],
            'requests' => DB::table('staff_purchase_requests')
                ->where('employee_id', $employee->id)
                ->orderByDesc('id')->limit(20)->get()
                ->map(fn ($row) => $this->present($row)),
        ]);
    }

    public function apply(Request $request): RedirectResponse
    {
        $employee = $this->employeeOf($request);
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'payment_mode' => ['required', Rule::in(['cash', 'mfs', 'bank', 'salary_deduction'])],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
        ]);

        $products = $this->benefitProducts($businessId)->keyBy('id');

        $mrpTotal = 0.0;
        $lines = [];
        foreach ($data['lines'] as $line) {
            $product = $products[$line['product_id']] ?? null;
            if ($product === null) {
                throw ValidationException::withMessages([
                    'lines' => 'That product is not part of the staff benefit.',
                ]);
            }

            // One unit per SKU per calendar month at staff price - the policy's
            // rule 3. A second unit is bought at MRP, at the counter, normally.
            if ($this->alreadyTookThisMonth($employee->id, $product['id'])) {
                throw ValidationException::withMessages([
                    'lines' => "You already took {$product['name']} this month at the staff price. A second unit is bought at MRP, at the counter.",
                ]);
            }

            $mrpTotal += (float) $product['mrp'];
            $lines[] = $product;
        }

        // The monthly cap counts MRP, never the staff price - rule 2, exactly.
        $used = $this->mrpUsedThisMonth($employee->id);
        if ($used + $mrpTotal > self::MONTHLY_MRP_CAP) {
            throw ValidationException::withMessages([
                'lines' => sprintf(
                    'This would use %s of your %s monthly allowance (counted at MRP); %s is already used. The allowance does not roll over.',
                    number_format($mrpTotal, 2),
                    number_format(self::MONTHLY_MRP_CAP, 2),
                    number_format($used, 2),
                ),
            ]);
        }

        DB::transaction(function () use ($employee, $businessId, $data, $lines) {
            $id = DB::table('staff_purchase_requests')->insertGetId([
                'business_id' => $businessId,
                'number' => $this->nextNumber($businessId),
                'employee_id' => $employee->id,
                'payment_mode' => $data['payment_mode'],
                'status' => 'pending',
                'note' => $data['note'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach ($lines as $product) {
                DB::table('staff_purchase_lines')->insert([
                    'business_id' => $businessId,
                    'request_id' => $id,
                    'product_id' => $product['id'],
                    'quantity' => 1,
                    'staff_price' => $product['staff_price'],
                    'mrp' => $product['mrp'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        return back()->with('success', 'Application submitted. The store hands over once it is approved.');
    }

    // ── The authority's side ─────────────────────────────────────────────

    public function index(): Response
    {
        $this->authorize('hrm.employees.manage');

        $businessId = app(BusinessContext::class)->id();

        return Inertia::render('hrm/staff-purchases', [
            'requests' => DB::table('staff_purchase_requests')
                ->where('business_id', $businessId)
                ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")
                ->orderByDesc('id')->limit(100)->get()
                ->map(fn ($row) => $this->present($row)),
            'moneyAccounts' => MoneyAccount::query()->handled()->where('is_active', true)
                ->orderBy('name')->get(['id', 'name', 'type']),
        ]);
    }

    public function approve(Request $request, int $id): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');
        $row = $this->requestRow($id, 'pending');

        DB::table('staff_purchase_requests')->where('id', $row->id)->update([
            'status' => 'approved', 'decided_by' => $request->user()->id,
            'decided_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('success', 'Approved. The store can hand over now.');
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');
        $row = $this->requestRow($id, 'pending');

        $data = $request->validate(['decision_note' => ['required', 'string', 'max:500']]);

        DB::table('staff_purchase_requests')->where('id', $row->id)->update([
            'status' => 'rejected', 'decided_by' => $request->user()->id,
            'decided_at' => now(), 'decision_note' => $data['decision_note'], 'updated_at' => now(),
        ]);

        return back()->with('success', 'Rejected, reason kept.');
    }

    /**
     * ⚠ THE handover. Only an APPROVED application; it becomes a real counter
     * sale at the snapshotted staff prices. Cash/MFS/bank collects into the
     * chosen account; salary deduction leaves the invoice on account and
     * writes a GENERATED payroll adjustment for the current period.
     */
    public function fulfill(Request $request, int $id, RecordCounterSale $action): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');
        $row = $this->requestRow($id, 'approved');

        $data = $request->validate([
            'money_account_id' => [
                Rule::requiredIf($row->payment_mode !== 'salary_deduction'),
                'nullable', 'integer', Rule::exists('money_accounts', 'id')->where('business_id', app(BusinessContext::class)->id()),
            ],
        ]);

        $lines = DB::table('staff_purchase_lines')->where('request_id', $row->id)->get();
        $employee = Employee::findOrFail($row->employee_id);
        $staffTotal = (float) $lines->sum('staff_price');

        DB::transaction(function () use ($row, $lines, $employee, $data, $action, $request, $staffTotal) {
            $sale = $action->handle(
                customer: Customer::walkIn(),
                warehouse: Warehouse::query()->orderByDesc('is_default')->firstOrFail(),
                lines: $lines->map(fn ($line) => [
                    'product' => Product::findOrFail($line->product_id),
                    'quantity' => (string) $line->quantity,
                    // The snapshot from apply time - the price that was approved.
                    'unitPrice' => (string) $line->staff_price,
                ])->all(),
                createdBy: $request->user(),
                moneyAccount: $row->payment_mode === 'salary_deduction'
                    ? null
                    : MoneyAccount::findOrFail($data['money_account_id']),
                reference: $row->number,
                note: "Staff purchase {$row->number} - {$employee->fullName()} ({$employee->employee_code})",
            );

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

            DB::table('staff_purchase_requests')->where('id', $row->id)->update([
                'status' => 'fulfilled',
                'fulfilled_by' => $request->user()->id,
                'fulfilled_at' => now(),
                'sales_invoice_id' => $sale->invoice->id,
                'updated_at' => now(),
            ]);
        });

        return back()->with('success', "Handed over as sale {$row->number} at the staff price.");
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

    // ── The shared arithmetic ────────────────────────────────────────────

    /** Products in the benefit = those with a STAFF price in force, with MRP. */
    private function benefitProducts(int $businessId)
    {
        $staffGroup = PriceGroup::query()->where('code', 'STAFF')->first();
        if ($staffGroup === null) {
            return collect();
        }

        $today = Carbon::today()->toDateString();
        $inForce = fn ($query) => $query
            ->whereDate('effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today));

        $staffPrices = ProductPrice::query()
            ->where('price_group_id', $staffGroup->id)
            ->tap($inForce)
            ->orderBy('effective_from')
            ->get()
            ->keyBy('product_id');

        // ⚠⚠ MRP is the product's own printed MRP (21 Sep 2026). Before that it
        // was read from a price for nobody in particular, which the catalogue
        // import never wrote, so the cap fell back to the STAFF price and let a
        // person take more than the rule allows. The Retail price, then that
        // old kind of price, are kept only as fallbacks for a product with no MRP.
        $retailGroupId = PriceGroup::query()->where('code', PriceGroup::DEFAULT_CODE)->value('id');
        $retailPrices = ProductPrice::query()
            ->where(fn ($query) => $query->where('price_group_id', $retailGroupId)->orWhereNull('price_group_id'))
            ->whereNull('customer_id')
            ->where('min_quantity', 0)
            ->where('is_discount', false)
            ->tap($inForce)
            ->orderByRaw('CASE WHEN price_group_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('effective_from')
            ->get()
            ->keyBy('product_id');

        return Product::query()->where('is_active', true)
            ->whereIn('id', $staffPrices->keys())
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'mrp'])
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'staff_price' => (float) $staffPrices[$product->id]->unit_price,
                'mrp' => (float) ($product->mrp ?? $retailPrices[$product->id]->unit_price ?? $staffPrices[$product->id]->unit_price),
            ])
            ->values();
    }

    /** MRP consumed this calendar month by requests still standing. */
    private function mrpUsedThisMonth(int $employeeId): float
    {
        return (float) DB::table('staff_purchase_lines')
            ->join('staff_purchase_requests', 'staff_purchase_requests.id', '=', 'staff_purchase_lines.request_id')
            ->where('staff_purchase_requests.employee_id', $employeeId)
            ->whereNotIn('staff_purchase_requests.status', ['rejected', 'cancelled'])
            ->whereBetween('staff_purchase_requests.created_at', [
                Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth(),
            ])
            ->sum('staff_purchase_lines.mrp');
    }

    private function alreadyTookThisMonth(int $employeeId, int $productId): bool
    {
        return DB::table('staff_purchase_lines')
            ->join('staff_purchase_requests', 'staff_purchase_requests.id', '=', 'staff_purchase_lines.request_id')
            ->where('staff_purchase_requests.employee_id', $employeeId)
            ->where('staff_purchase_lines.product_id', $productId)
            ->whereNotIn('staff_purchase_requests.status', ['rejected', 'cancelled'])
            ->whereBetween('staff_purchase_requests.created_at', [
                Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth(),
            ])
            ->exists();
    }

    private function employeeOf(Request $request): Employee
    {
        $employee = Employee::query()->where('user_id', $request->user()->id)->first();
        abort_if($employee === null, 403, 'Only staff with an employee record can use the benefit.');

        return $employee;
    }

    private function requestRow(int $id, string $status): object
    {
        $row = DB::table('staff_purchase_requests')
            ->where('business_id', app(BusinessContext::class)->id())
            ->where('id', $id)->first();
        abort_if($row === null, 404);

        if ($row->status !== $status) {
            throw ValidationException::withMessages([
                'request' => "Application {$row->number} is {$row->status}, not {$status}.",
            ]);
        }

        return $row;
    }

    private function present(object $row): array
    {
        return [
            'id' => $row->id,
            'number' => $row->number,
            'employee' => Employee::find($row->employee_id)?->fullName(),
            'employee_code' => Employee::find($row->employee_id)?->employee_code,
            'payment_mode' => $row->payment_mode,
            'status' => $row->status,
            'decision_note' => $row->decision_note,
            'applied_at' => $row->created_at,
            'lines' => DB::table('staff_purchase_lines')->where('request_id', $row->id)->get()
                ->map(fn ($line) => [
                    'product' => Product::withoutGlobalScopes()->find($line->product_id)?->name,
                    'staff_price' => (float) $line->staff_price,
                    'mrp' => (float) $line->mrp,
                ]),
            'total_staff_price' => (float) DB::table('staff_purchase_lines')->where('request_id', $row->id)->sum('staff_price'),
            'sales_invoice_id' => $row->sales_invoice_id,
        ];
    }

    private function nextNumber(int $businessId): string
    {
        $business = Business::withoutGlobalScopes()->findOrFail($businessId);
        $prefix = 'SPB-'.FiscalYear::containing(Carbon::now(), $business)->label().'-';

        // ⚠ Numeric, not text order (14 Oct 2026, SAL-001): see DocumentNumber.
        return DocumentNumber::next(DB::table('staff_purchase_requests'), 'number', $prefix);
    }
}
