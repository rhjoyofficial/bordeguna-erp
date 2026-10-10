<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\ConfirmDisbursementBatch;
use App\Modules\Hrm\Actions\CreateDisbursementBatches;
use App\Modules\Hrm\Actions\RecordPayslipPayment;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Enums\SalaryPaymentMethod;
use App\Modules\Hrm\Models\DisbursementBatch;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Models\PayslipPayment;
use App\Modules\Hrm\Payroll\Disbursement\AccountingExport;
use App\Modules\Hrm\Payroll\Disbursement\DisbursementFormatRegistry;
use App\Modules\Hrm\Payroll\Disbursement\DisbursementRegister;
use App\Modules\Hrm\Support\PayslipAddress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum as EnumRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Disbursement: batches, payment confirmation and the exports (DIS-01/02/05/06).
 *
 * Everything here sits *after* the WFL-02 Disbursed transition, which is what
 * releases a payroll for payment and posts the loan and PAY-11 ledgers. This
 * screen is about the money actually moving, and about what happens when it
 * does not.
 */
class DisbursementController extends Controller
{
    public function index(
        Request $request,
        PayrollRun $run,
        DisbursementFormatRegistry $formats,
    ): Response {
        $this->authorize('hrm.payroll.manage');

        $run->load(['period', 'payslips.employee', 'payslips.payments', 'payslips.batches']);

        return Inertia::render('hrm/payroll/disbursement', [
            'run' => [
                'id' => $run->id,
                'version' => $run->version,
                'status' => $run->status->value,
                'status_label' => $run->status->label(),
                'period' => $run->period?->label(),
                'total_net' => (float) $run->total_net,
                'employee_count' => $run->employee_count,
                'is_disbursed' => in_array($run->status, [PayrollStatus::Disbursed, PayrollStatus::Locked], true),
            ],
            'batches' => DisbursementBatch::with(['payslips', 'confirmer:id,name'])
                ->where('payroll_run_id', $run->id)
                ->orderBy('id')
                ->get()
                ->map(fn (DisbursementBatch $batch) => [
                    'id' => $batch->id,
                    'reference' => $batch->reference,
                    'method' => $batch->method->value,
                    'method_label' => $batch->method->label(),
                    'payslip_count' => $batch->payslips->count(),
                    'expected_total' => round($batch->payslips->sum(fn (Payslip $p) => (float) $p->net), 2),
                    'paid_total' => $batch->paidTotal(),
                    'advice_reference' => $batch->advice_reference,
                    'advice_file' => $batch->advice()?->original_name,
                    'confirmed_at' => $batch->confirmed_at?->format('d M Y, H:i'),
                    'confirmed_by' => $batch->confirmer?->name,
                ]),
            'payslips' => $run->payslips
                ->sortBy(fn (Payslip $p) => $p->employee?->employee_code ?? '')
                ->values()
                ->map(fn (Payslip $payslip) => [
                    'id' => $payslip->id,
                    'employee' => $payslip->employee?->fullName(),
                    'employee_code' => $payslip->employee?->employee_code,
                    'method' => ($payslip->employee?->salary_payment_method ?? SalaryPaymentMethod::Cash)->label(),
                    'net' => (float) $payslip->net,
                    'paid_total' => $payslip->paidTotal(),
                    'outstanding' => round((float) $payslip->net - $payslip->paidTotal(), 2),
                    'payment_status' => $payslip->paymentStatus()->value,
                    'payment_status_label' => $payslip->paymentStatus()->label(),
                    'failure_reason' => $payslip->currentFailure()?->failure_reason,
                    'is_blocked' => $payslip->is_blocked,
                    // ⚠⚠ The job has always written these three and NOTHING read
                    // them here. A payslip that failed to send was discovered by
                    // the employee complaining, which is the opposite of what
                    // recording an outcome is for.
                    'email' => [
                        'address' => $payslip->emailed_to ?? PayslipAddress::for($payslip),
                        'sent_at' => $payslip->emailed_at?->format('j M Y, g:i a'),
                        'error' => $payslip->email_error,
                        'reachable' => PayslipAddress::for($payslip) !== null,
                    ],
                    'batch' => $payslip->batches->first()?->reference,
                    'payments' => $payslip->payments->map(fn (PayslipPayment $p) => [
                        'id' => $p->id,
                        'type' => $p->event_type->value,
                        'type_label' => $p->event_type->label(),
                        'amount' => (float) $p->amount,
                        'method' => $p->method->label(),
                        'paid_on' => $p->paid_on->toDateString(),
                        'reference' => $p->reference,
                        'failure_reason' => $p->failure_reason,
                        'is_reversed' => $p->reverses_id !== null,
                    ]),
                ]),
            'methods' => SalaryPaymentMethod::options(),
            // ⚠⚠ Recording a payment means saying which account it left, and
            // this screen never had the list to ask with. Both routes out -
            // confirming a batch and recording one payslip - died on that: the
            // batch route validated `money_account_id` as required and the
            // screen never sent it, and the per-payslip route posted to the
            // ledger without one and threw a blank 500.
            'moneyAccounts' => MoneyAccount::query()->handled()->where('is_active', true)
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn (MoneyAccount $a) => ['id' => $a->id, 'name' => $a->name]),
            'formats' => $formats->options(),
            // Counted before anybody presses send, because "8 queued" tells HR
            // nothing about the ninth person.
            'email_summary' => [
                'sendable' => $run->payslips->reject->is_blocked
                    ->filter(fn (Payslip $p) => PayslipAddress::for($p) !== null)->count(),
                'unreachable' => $run->payslips->reject->is_blocked
                    ->filter(fn (Payslip $p) => PayslipAddress::for($p) === null)
                    ->map(fn (Payslip $p) => $p->employee?->fullName())
                    ->values(),
                'sent' => $run->payslips->filter(fn (Payslip $p) => $p->emailed_at !== null)->count(),
                'failed' => $run->payslips->filter(fn (Payslip $p) => filled($p->email_error))->count(),
            ],
        ]);
    }

    public function storeBatches(Request $request, PayrollRun $run, CreateDisbursementBatches $action): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');

        $batches = $action->handle($run, $request->user());

        return back()->with('success', sprintf(
            '%d batch(es) created, one per payment method.',
            $batches->count(),
        ));
    }

    public function confirmBatch(Request $request, DisbursementBatch $batch, ConfirmDisbursementBatch $action): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');

        $data = $request->validate([
            'paid_on' => ['nullable', 'date'],
            'advice_reference' => ['nullable', 'string', 'max:255'],
            // ⚠ Required here, not nullable. Confirming means the money has gone
            // and a payment that cannot say which account it left is the record
            // that was missing every time somebody was paid.
            'money_account_id' => ['required', Rule::exists('money_accounts', 'id')->where('business_id', app(BusinessContext::class)->id())],
        ]);

        $paid = $action->handle(
            $batch,
            $request->user(),
            isset($data['paid_on']) ? Carbon::parse($data['paid_on']) : null,
            $data['advice_reference'] ?? null,
            MoneyAccount::findOrFail($data['money_account_id']),
        );

        return back()->with('success', sprintf('%d payslip(s) marked paid in %s.', $paid, $batch->reference));
    }

    public function failBatch(Request $request, DisbursementBatch $batch, ConfirmDisbursementBatch $action): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'failed_on' => ['nullable', 'date'],
        ]);

        $failed = $action->fail(
            $batch,
            $request->user(),
            $data['reason'],
            isset($data['failed_on']) ? Carbon::parse($data['failed_on']) : null,
        );

        return back()->with('success', sprintf(
            '%d payslip(s) recorded as failed. Any loan and carry-forward recovery on them has been reversed.',
            $failed,
        ));
    }

    /**
     * DIS-01's bank advice upload/confirmation.
     */
    public function uploadAdvice(Request $request, DisbursementBatch $batch, StoreAttachment $store): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,csv,txt,xls,xlsx,jpg,jpeg,png', 'max:5120'],
            'advice_reference' => ['nullable', 'string', 'max:255'],
        ]);

        $store->handle(
            $batch,
            $request->file('file'),
            DisbursementBatch::ADVICE_COLLECTION,
            $request->user(),
            singleton: true,
        );

        if (filled($data['advice_reference'] ?? null)) {
            $batch->update(['advice_reference' => $data['advice_reference']]);
        }

        return back()->with('success', 'Bank advice recorded against this batch.');
    }

    /**
     * DIS-01's employee-wise confirmation, and DIS-02's partial payment.
     */
    public function storePayment(Request $request, Payslip $payslip, RecordPayslipPayment $action): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['nullable', new EnumRule(SalaryPaymentMethod::class)],
            'paid_on' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            // ⚠ The individual payment now asks the same question the batch
            // confirmation does. Money leaving without a named account is the
            // record that made a cash book impossible to produce.
            'money_account_id' => ['nullable', 'integer', Rule::exists('money_accounts', 'id')->where('business_id', app(BusinessContext::class)->id())],
        ]);

        // ⚠⚠ A MISSING ACCOUNT IS A QUESTION, NOT A 500. With accounting on,
        // posting a payment from no account throws MappingNotFound deep in the
        // ledger, the transaction rolls back, and the screen shows a blank error
        // page mid-disbursement - so it reads as "nothing happened" when what
        // happened is that nobody said where the money came from. It stays
        // nullable in the rules because an installation with no accounting has
        // no account to name.
        try {
            $action->paid(
                $payslip,
                $request->user(),
                (float) $data['amount'],
                isset($data['method']) ? SalaryPaymentMethod::from($data['method']) : null,
                isset($data['paid_on']) ? Carbon::parse($data['paid_on']) : null,
                $data['reference'] ?? null,
                $payslip->batch(),
                isset($data['money_account_id']) ? MoneyAccount::find($data['money_account_id']) : null,
            );
        } catch (MappingNotFound $exception) {
            throw ValidationException::withMessages([
                'money_account_id' => 'Say which account this salary was paid from. '.$exception->getMessage(),
            ]);
        }

        return back()->with('success', 'Payment recorded.');
    }

    /**
     * DIS-02's "unpaid status and retry".
     */
    public function failPayment(Request $request, Payslip $payslip, RecordPayslipPayment $action): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'method' => ['nullable', new EnumRule(SalaryPaymentMethod::class)],
            'failed_on' => ['nullable', 'date'],
        ]);

        $action->failed(
            $payslip,
            $request->user(),
            $data['reason'],
            isset($data['method']) ? SalaryPaymentMethod::from($data['method']) : null,
            isset($data['failed_on']) ? Carbon::parse($data['failed_on']) : null,
            $payslip->batch(),
        );

        return back()->with('success', 'Failure recorded. Any loan or carry-forward recovery on this payslip has been reversed.');
    }

    /**
     * A payment that came back — a returned transfer, a bounced cheque.
     */
    public function reversePayment(Request $request, PayslipPayment $payment, RecordPayslipPayment $action): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'reversed_on' => ['nullable', 'date'],
        ]);

        $action->reverse(
            $payment,
            $request->user(),
            $data['reason'],
            isset($data['reversed_on']) ? Carbon::parse($data['reversed_on']) : null,
        );

        return back()->with('success', 'Payment reversed.');
    }

    /**
     * DIS-06's register (Correction C9d) — our own columns, through the
     * pluggable format layer. Not a bank advice.
     */
    public function exportRegister(
        Request $request,
        DisbursementBatch $batch,
        DisbursementFormatRegistry $formats,
        DisbursementRegister $register,
    ): HttpResponse {
        $this->authorize('hrm.payroll.manage');

        $format = $formats->get($request->string('format')->toString() ?: 'generic_csv');
        $body = $format->render($batch, $register->rowsFor($batch));

        return response($body, 200, [
            'Content-Type' => $format->mimeType(),
            'Content-Disposition' => 'attachment; filename="'.$format->filename($batch).'"',
        ]);
    }

    /**
     * DIS-05's auditable accounting export.
     */
    public function exportAccounting(Request $request, PayrollRun $run, AccountingExport $export): HttpResponse
    {
        $this->authorize('hrm.payroll.manage');

        $mode = $request->string('mode')->toString() === AccountingExport::DETAIL
            ? AccountingExport::DETAIL
            : AccountingExport::SUMMARY;

        return response($export->render($run, $mode), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$export->filename($run, $mode).'"',
        ]);
    }
}
