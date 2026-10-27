<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Jobs\SendPayslipEmail;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Payroll\Payslip\PayslipDocument;
use App\Modules\Hrm\Payroll\Payslip\PayslipPassword;
use App\Modules\Hrm\Payroll\Payslip\PayslipPdf;
use App\Modules\Hrm\Support\EmployeePayAdjustments;
use App\Modules\Hrm\Support\PayslipAddress;
use App\Modules\Hrm\Support\SelfScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payslips: the HR view, the employee's own view, the PDF and the email
 * (DIS-03/DIS-04).
 *
 * Who may see a payslip is SAL-07's fourth control and Correction C5 / ACL-02's
 * hard line: HR by `hrm.payroll.manage`, the employee by self-scope, and a
 * **Department Manager not at all** — they hold neither, and there is
 * deliberately no team view here for them to reach.
 *
 * Employees see only **finished** payslips. A payroll still in draft is a
 * working figure, and showing it would have people querying a number that has
 * not been reviewed, approved or paid.
 */
class PayslipController extends Controller
{
    /**
     * The employee's own payslips — DIS-04's employee portal.
     */
    public function mine(Request $request): Response|RedirectResponse
    {
        $employee = Employee::forUser($request->user());

        if ($employee === null) {
            return redirect()->route('dashboard')
                ->with('error', 'Your user account is not linked to an employee record yet.');
        }

        $payslips = Payslip::with(['run.period', 'lines', 'payments'])
            ->where('employee_id', $employee->id)
            ->whereHas('run', fn ($q) => $q->whereIn('status', $this->visibleStatuses()))
            ->get()
            ->sortByDesc(fn (Payslip $p) => [$p->run?->period?->year, $p->run?->period?->month])
            ->values();

        return Inertia::render('hrm/payroll/my-payslips', [
            'employee' => [
                'name' => $employee->fullName(),
                'employee_code' => $employee->employee_code,
            ],
            'password_hint' => PayslipPassword::HINT,
            'password_unavailable' => PayslipPassword::missingReason($employee),
            // ⭐ My deductions (27 Oct 2026): what is decided for his next salary,
            // before that payslip is his to see. His own only.
            'upcoming_adjustments' => EmployeePayAdjustments::upcoming($employee),
            'payslips' => $payslips->map(fn (Payslip $payslip) => [
                'id' => $payslip->id,
                'period' => $payslip->run?->period?->label(),
                'status' => $payslip->run?->status->label(),
                'gross_salary' => (float) $payslip->gross_salary,
                'total_earnings' => (float) $payslip->total_earnings,
                'total_deductions' => (float) $payslip->total_deductions,
                'net' => (float) $payslip->net,
                'payment_status' => $payslip->paymentStatus()->label(),
                'paid_total' => $payslip->paidTotal(),
                'emailed_at' => $payslip->emailed_at?->format('d M Y, H:i'),
            ]),
        ]);
    }

    /**
     * One payslip in full — the same document the PDF prints, on screen.
     */
    public function show(Request $request, Payslip $payslip, PayslipDocument $document): Response
    {
        $this->authorizeView($request, $payslip);

        return Inertia::render('hrm/payroll/payslip', [
            'payslip_id' => $payslip->id,
            'run_status' => $payslip->run?->status->value,
            'can_manage' => $request->user()?->can('hrm.payroll.manage') ?? false,
            'password_hint' => PayslipPassword::HINT,
            'password_unavailable' => $payslip->employee === null
                ? null
                : PayslipPassword::missingReason($payslip->employee),
            'document' => $document->build($payslip, $request->user()),
        ]);
    }

    /**
     * The protected PDF (SAL-07: watermarked, stamped, password protected).
     */
    public function pdf(Request $request, Payslip $payslip, PayslipPdf $pdf): HttpResponse
    {
        $this->authorizeView($request, $payslip);

        $rendered = $pdf->render($payslip, $request->user(), protected: true);

        return response($rendered, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->filename($payslip).'"',
        ]);
    }

    /**
     * DIS-04's email, queued one payslip at a time.
     */
    public function email(Request $request, Payslip $payslip): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');
        $this->guardFinished($payslip->run?->status);

        SendPayslipEmail::dispatch($payslip->business_id, $payslip->id, $request->user()->id);

        return back()->with('success', 'Payslip queued for emailing.');
    }

    /**
     * The whole run's payslips, emailed. Still one job each, so a single bad
     * address does not take the other one hundred and ninety-nine with it.
     */
    public function emailRun(Request $request, PayrollRun $run): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');
        $this->guardFinished($run->status);

        $queued = 0;
        $skipped = [];

        foreach ($run->payslips()->where('is_blocked', false)->get() as $payslip) {
            // ⚠ Not queued at all when there is nowhere to send. Dispatching a
            // job whose only possible outcome is "no email address on record"
            // buries the one fact HR needs inside a failure they have to go
            // looking for.
            if (PayslipAddress::for($payslip) === null) {
                $skipped[] = $payslip->employee?->fullName() ?? ('payslip #'.$payslip->id);

                continue;
            }

            SendPayslipEmail::dispatch($payslip->business_id, $payslip->id, $request->user()->id);
            $queued++;
        }

        $message = sprintf('%d payslip(s) queued for emailing.', $queued);

        if ($skipped !== []) {
            $message .= ' No email address on record for '.implode(', ', $skipped)
                .'; hand those over in person or add an address first.';
        }

        return back()->with($skipped === [] ? 'success' : 'warning', $message);
    }

    /**
     * SAL-07's restricted access and C5/ACL-02 in one place: HR, or the
     * employee themselves, and nobody else.
     */
    private function authorizeView(Request $request, Payslip $payslip): void
    {
        abort_unless(
            SelfScope::allows($request->user(), $payslip->employee, 'hrm.payroll.manage'),
            403,
        );

        // An employee may see their own payslip only once the payroll is
        // finished; HR sees it at every stage, which is the whole point of
        // reviewing one.
        if (! ($request->user()?->can('hrm.payroll.manage') ?? false)) {
            abort_unless(in_array($payslip->run?->status, $this->visibleStatuses(), true), 403);
        }
    }

    private function guardFinished(?PayrollStatus $status): void
    {
        abort_unless(in_array($status, $this->visibleStatuses(), true), 403);
    }

    /**
     * @return array<int, PayrollStatus>
     */
    private function visibleStatuses(): array
    {
        return EmployeePayAdjustments::finishedStatuses();
    }
}
