<?php

namespace App\Modules\Hrm\Payroll\Payslip;

use App\Models\User;
use App\Modules\Hrm\Enums\SalaryPaymentMethod;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Models\PayslipLine;

/**
 * Everything DIS-03 says a payslip must carry, gathered once.
 *
 * DIS-03 ticked "সবগুলো" — employee and organisational information, an
 * attendance summary, earnings, deductions, net, payment method and status,
 * *and* the formula or explanation behind each figure. The last of those is
 * only possible because every `PayslipLine` has stored its own quantity, rate,
 * formula and source since 3b; this class does not derive a single number, it
 * reads what was paid.
 *
 * One builder feeds three destinations — the PDF, the employee portal and the
 * email — so they cannot drift into showing different payslips.
 */
class PayslipDocument
{
    /**
     * @return array<string, mixed>
     */
    public function build(Payslip $payslip, ?User $generatedBy = null): array
    {
        $payslip->loadMissing(['lines', 'employee', 'run.period', 'payments']);

        $employee = $payslip->employee;
        $assignment = $employee?->currentAssignment();
        $period = $payslip->run?->period;
        $attendance = $payslip->calculation['attendance'] ?? null;

        return [
            'employee' => [
                'name' => $employee?->fullName(),
                'code' => $employee?->employee_code,
                'designation' => $assignment?->designation?->name,
                'department' => $assignment?->department?->name,
                'section' => $assignment?->section?->name,
                'office' => $assignment?->office?->name,
                'business' => $assignment?->office?->business?->name ?? $payslip->business?->name,
                'joining_date' => $employee?->joining_date?->format('d M Y'),
                'employment_type' => $employee?->employment_type?->label(),
            ],
            'period' => [
                'label' => $period?->label(),
                'start' => $period?->start_date->format('d M Y'),
                'end' => $period?->end_date->format('d M Y'),
                'divisor_days' => $period?->divisor_days,
                'run_version' => $payslip->run?->version,
            ],
            // Facts, not figures: Attendance decided every one of these and
            // Payroll only priced them.
            'attendance' => $attendance === null ? null : [
                'present_days' => (float) ($attendance['present_days'] ?? 0),
                'absent_days' => (float) ($attendance['absent_days'] ?? 0),
                'paid_leave_days' => (float) ($attendance['paid_leave_days'] ?? 0),
                'unpaid_leave_days' => (float) ($attendance['unpaid_leave_days'] ?? 0),
                'late_occurrences' => (int) ($attendance['late_occurrences'] ?? 0),
                'deductible_days' => (float) ($attendance['deductible_days'] ?? 0),
            ],
            'earnings' => $this->lines($payslip, 'earning'),
            'deductions' => $this->lines($payslip, 'deduction'),
            'totals' => [
                'gross_salary' => (float) $payslip->gross_salary,
                'total_earnings' => (float) $payslip->total_earnings,
                'total_deductions' => (float) $payslip->total_deductions,
                'net' => (float) $payslip->net,
            ],
            'payment' => [
                'method' => ($employee?->salary_payment_method ?? SalaryPaymentMethod::Cash)->label(),
                'status' => $payslip->paymentStatus()->label(),
                'paid_total' => $payslip->paidTotal(),
                'events' => $payslip->payments->map(fn ($event) => [
                    'type' => $event->event_type->label(),
                    'amount' => (float) $event->amount,
                    'method' => $event->method->label(),
                    'date' => $event->paid_on->format('d M Y'),
                    'reference' => $event->reference,
                    'failure_reason' => $event->failure_reason,
                ])->all(),
            ],
            'notes' => $payslip->explanation(),
            'block_reasons' => $payslip->block_reasons ?? [],
            // SAL-07: who produced this copy and when. Stamped on the document
            // itself, so a printed payslip that surfaces somewhere it should not
            // still says where it came from.
            'generated' => [
                'by' => $generatedBy?->name,
                'at' => now()->format('d M Y, H:i'),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lines(Payslip $payslip, string $type): array
    {
        return $payslip->lines
            ->filter(fn (PayslipLine $line) => $line->type->value === $type)
            ->map(fn (PayslipLine $line) => [
                'code' => $line->code,
                'label' => $line->label,
                'amount' => (float) $line->amount,
                'quantity' => $line->quantity === null ? null : (float) $line->quantity,
                'rate' => $line->rate === null ? null : (float) $line->rate,
                // PAY-13's explainability, stored at calculation time rather
                // than reconstructed now — after a salary revision, rebuilding
                // it would describe today's rules, not the ones that paid this.
                'formula' => $line->formula,
                'source' => $line->source,
            ])
            ->values()
            ->all();
    }
}
