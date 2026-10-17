<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Models\StaffPurchaseRequest;
use App\Modules\Hrm\Payroll\Stages\ApplyStaffPurchaseRecovery;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ⭐⭐ THE 2140 FIX, AT THE TRANSACTION LEVEL (ChatGPT ruling Q2, Phase 2,
 * 17 Oct 2026). The payslip's `staff_purchase` recovery is not a deduction
 * held for anybody: it is the employee's own invoice being settled out of
 * their salary. So at payroll posting it becomes an INVOICE-LINKED, NON-CASH
 * customer receipt of kind `payroll_offset` on the employee's customer row,
 * allocated to the oldest open salary-deduction staff invoices, partial
 * allowed, and posts
 *
 *   Dr 2130 Salaries and Wages Payable (party: the employee)
 *       Cr 1140 Accounts Receivable      (party: the employee's customer)
 *
 * The accrual (PostPayrollAccrual) credits 2130 with the net BEFORE this
 * recovery and never maps `staff_purchase`, so 2140 is untouched and the two
 * entries together leave 2130 at the net actually paid. What the 10%-of-net
 * cap did not take stays outstanding on the invoice and on next month's
 * recovery (PayrollContextBuilder derives it; nothing is written to carry).
 *
 * ⚠ Never a mapping-row change (rejected list). Already-posted wrong 2140
 * lines are not touched by code: `staff-purchases:audit-2140` lists them for
 * Joy's corrections sign-off (D6).
 *
 * ⚠ Withdrawn with the run: a reopened or cancelled run reverses the offset
 * journal and removes the generated receipt, exactly as its accrual comes
 * out, so a re-approved version settles afresh.
 */
class SettleStaffPurchaseFromPayroll
{
    public const KIND = CustomerReceipt::KIND_PAYROLL_OFFSET;

    /** The payslip code this settles; the accrual never maps it. */
    public const OFFSET_CODE = ApplyStaffPurchaseRecovery::CODE;

    public function __construct(private readonly PostJournal $postJournal) {}

    /**
     * @return Collection<int, CustomerReceipt> the offsets written for this run
     */
    public function handle(PayrollRun $run, User $postedBy): Collection
    {
        $period = $run->period;
        $on = $period?->end_date ? Carbon::parse($period->end_date)->startOfDay() : Carbon::today();
        $written = collect();

        foreach ($run->payslips()->with(['lines', 'employee'])->get() as $payslip) {
            $recovery = $this->recoveredOn($payslip);

            if ($recovery->minor <= 0 || $payslip->employee === null) {
                continue;
            }

            // ⚠ One offset per run and employee: a retried approval or a
            // backfill must not clear the same invoice twice.
            $already = CustomerReceipt::query()
                ->where('kind', self::KIND)
                ->where('payroll_run_id', $run->id)
                ->whereHas('customer', fn ($q) => $q->where('employee_id', $payslip->employee_id))
                ->exists();

            if ($already) {
                continue;
            }

            $customer = Customer::ofEmployee((int) $payslip->employee_id);

            if ($customer === null) {
                // A recovery with no customer row: a hand-over from before Phase
                // 2 posted to the walk-in row. Nothing to clear here; the audit
                // command lists it.
                continue;
            }

            $receipt = DB::transaction(fn () => $this->settle($run, $payslip, $customer, $recovery, $on, $postedBy));

            if ($receipt !== null) {
                $written->push($receipt);
            }
        }

        return $written;
    }

    /**
     * Take the run's offsets back out: reverse each journal (dated on the
     * entry it removes, the payroll accrual's own exception) and remove the
     * generated receipts, so the invoices read as open again.
     */
    public function withdraw(PayrollRun $run, User $by): int
    {
        $receipts = CustomerReceipt::query()
            ->where('kind', self::KIND)
            ->where('payroll_run_id', $run->id)
            ->with('allocations')
            ->get();

        foreach ($receipts as $receipt) {
            DB::transaction(function () use ($receipt, $by, $run) {
                $journal = Journal::query()
                    ->where('source_type', $receipt->getMorphClass())
                    ->where('source_id', $receipt->id)
                    ->whereNull('reverses_journal_id')
                    ->first();

                if ($journal !== null && ! $journal->hasBeenReversed()) {
                    app(ReverseJournal::class)->handle($journal, $by, Carbon::parse($journal->journal_date)->startOfDay());
                }

                foreach ($receipt->allocations as $allocation) {
                    $request = StaffPurchaseRequest::query()->where('sales_invoice_id', $allocation->sales_invoice_id)->first();
                    $request?->record('payroll_offset_withdrawn', $by, [
                        'amount' => Money::ofTaka((string) $allocation->amount)->toTaka(),
                        'payroll_run_id' => $run->id,
                        'version' => $run->version,
                    ]);
                }

                $receipt->allocations()->delete();
                $receipt->delete();
            });
        }

        return $receipts->count();
    }

    private function settle(PayrollRun $run, Payslip $payslip, Customer $customer, Money $recovery, Carbon $on, User $postedBy): ?CustomerReceipt
    {
        // The open salary-deduction staff invoices of this employee, oldest first.
        $invoiceIds = StaffPurchaseRequest::query()
            ->where('employee_id', $payslip->employee_id)
            ->where('payment_mode', 'salary_deduction')
            ->where('status', StaffPurchaseRequest::FULFILLED)
            ->whereNotNull('sales_invoice_id')
            ->orderBy('fulfilled_at')->orderBy('id')
            ->pluck('sales_invoice_id', 'id');

        $remaining = $recovery->minor;
        $allocations = [];

        foreach ($invoiceIds as $requestId => $invoiceId) {
            if ($remaining <= 0) {
                break;
            }

            /** @var SalesInvoice|null $invoice */
            $invoice = SalesInvoice::query()->whereKey($invoiceId)->where('customer_id', $customer->id)->lockForUpdate()->first();

            if ($invoice === null) {
                continue;
            }

            $outstanding = $invoice->outstanding()->minor;

            if ($outstanding <= 0) {
                continue;
            }

            $take = min($remaining, $outstanding);
            $remaining -= $take;
            $allocations[] = ['invoice' => $invoice, 'request_id' => (int) $requestId, 'amount' => Money::ofMinor($take)];
        }

        if ($allocations === []) {
            return null;
        }

        $total = Money::sum(array_map(fn (array $a) => $a['amount'], $allocations));
        $label = ($run->period?->label() ?? '').' v'.$run->version;

        $receipt = CustomerReceipt::create([
            'business_id' => $customer->business_id,
            'customer_id' => $customer->id,
            'money_account_id' => null,
            'kind' => self::KIND,
            'payroll_run_id' => $run->id,
            'received_on' => $on,
            'amount' => $total->toTaka(),
            'reference' => 'Payroll '.$label,
            'note' => "Staff purchase settled from salary, payroll {$label} ({$payslip->employee->employee_code}).",
            'created_by' => $postedBy->id,
        ]);

        foreach ($allocations as $allocation) {
            $receipt->allocations()->create([
                'business_id' => $receipt->business_id,
                'sales_invoice_id' => $allocation['invoice']->id,
                'amount' => $allocation['amount']->toTaka(),
            ]);

            StaffPurchaseRequest::query()->whereKey($allocation['request_id'])->first()?->record('payroll_offset', $postedBy, [
                'amount' => $allocation['amount']->toTaka(),
                'invoice_id' => $allocation['invoice']->id,
                'payroll_run_id' => $run->id,
                'payroll' => $label,
            ]);
        }

        $this->post($receipt, $payslip, $customer, $total, $on, $label, $postedBy);

        return $receipt->fresh(['allocations']);
    }

    /** Dr 2130 (employee) / Cr 1140 (customer), only where payroll accounting is switched on. */
    private function post(CustomerReceipt $receipt, Payslip $payslip, Customer $customer, Money $total, Carbon $on, string $label, User $postedBy): ?Journal
    {
        if (AccountMapping::forKeyOn('payroll.net_payable', $on) === null || AccountMapping::forKeyOn('sales.accounts_receivable', $on) === null) {
            return null;
        }

        $payable = AccountMapping::resolve('payroll.net_payable', $on);
        $receivable = AccountMapping::resolve('sales.accounts_receivable', $on);

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: [
                JournalLineDraft::debit($payable->id, $total, 'Staff purchase offset '.$payslip->employee->employee_code, $payslip->employee),
                JournalLineDraft::credit($receivable->id, $total, $receipt->reference, $customer),
            ],
            memo: "Staff purchase settled from salary, payroll {$label} ({$payslip->employee->employee_code})",
            source: $receipt,
        ), $postedBy);
    }

    private function recoveredOn(Payslip $payslip): Money
    {
        return Money::ofTaka(number_format((float) $payslip->lines
            ->where('code', ApplyStaffPurchaseRecovery::CODE)
            ->sum(fn ($line) => (float) $line->amount), 2, '.', ''));
    }
}
