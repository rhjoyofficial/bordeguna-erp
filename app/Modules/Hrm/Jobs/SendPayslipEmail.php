<?php

namespace App\Modules\Hrm\Jobs;

use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Mail\PayslipMail;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Payroll\Payslip\PayslipPdf;
use App\Modules\Hrm\Support\PayslipAddress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails one payslip (DIS-04), on the queue.
 *
 * Queued because a run of two hundred payslips means two hundred PDF renders
 * and two hundred SMTP conversations, and a request that does that inline will
 * time out somewhere in the middle with no way to tell which employees were
 * reached. One job per payslip, so a single bad address fails alone.
 *
 * ⚠ The same two queue traps as `CalculatePayrollRunJob`: the tenant context and
 * the acting user do not survive serialisation. Both are restored here — the
 * business because the payslip is scoped to it, the user because SAL-07 stamps
 * *who generated this copy* onto the document itself, and "system" is not an
 * answer to that question.
 *
 * The outcome is written back onto the payslip either way. A payslip that
 * quietly failed to send is one HR has to discover by an employee complaining.
 */
class SendPayslipEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public int $businessId,
        public int $payslipId,
        public ?int $actingUserId = null,
    ) {}

    public function handle(BusinessContext $context, PayslipPdf $pdf): void
    {
        // TenantScope, not the business alone. `PayslipPdf::render()` is
        // handed `Auth::user()` and C9b/ACL-02 decide what that user may see,
        // so the permission team id has to be part of entering the tenant.
        TenantScope::enter($this->businessId);

        if ($this->actingUserId !== null) {
            Auth::loginUsingId($this->actingUserId);
        }

        $payslip = Payslip::with(['employee.user', 'run.period', 'lines'])->find($this->payslipId);

        if ($payslip === null) {
            return;
        }

        $address = $this->addressFor($payslip);

        if ($address === null) {
            $payslip->update([
                'email_error' => 'No email address on record for this employee.',
                'emailed_at' => null,
            ]);

            return;
        }

        // Renders protected. If no password can be formed the PDF refuses, and
        // that refusal is recorded rather than swallowed — an unprotected
        // payslip must not go out by email as a consolation (SAL-07 / C9b).
        $rendered = $pdf->render($payslip, Auth::user(), protected: true);

        Mail::to($address)->send(new PayslipMail($payslip, $rendered, $pdf->filename($payslip)));

        $payslip->update([
            'emailed_at' => now(),
            'emailed_to' => $address,
            'email_error' => null,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Payslip::withoutGlobalScopes()->find($this->payslipId)?->update([
            'email_error' => $exception->getMessage(),
            'emailed_at' => null,
        ]);
    }

    /**
     * ⚠ Moved to PayslipAddress so the screen offering the button and the job
     * doing the sending answer "can this person be reached" the same way.
     */
    private function addressFor(Payslip $payslip): ?string
    {
        return PayslipAddress::for($payslip);
    }
}
