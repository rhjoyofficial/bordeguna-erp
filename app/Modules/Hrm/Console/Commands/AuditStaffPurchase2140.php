<?php

namespace App\Modules\Hrm\Console\Commands;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\PayslipLine;
use App\Modules\Hrm\Payroll\Stages\ApplyStaffPurchaseRecovery;
use App\Modules\Sales\Models\CustomerReceipt;
use Illuminate\Console\Command;

/**
 * READ-ONLY (decision D6, 17 Oct 2026): list the payroll journals that
 * credited 2140 Employee Deductions Payable for a `staff_purchase` recovery
 * without an invoice-linked payroll offset, so Joy can sign off their repair
 * through the normal corrections process. Nothing is written, ever.
 */
class AuditStaffPurchase2140 extends Command
{
    protected $signature = 'staff-purchases:audit-2140 {--business= : One business id, else every business}';

    protected $description = 'List payroll runs whose staff purchase recovery was credited to 2140 with no invoice-linked offset (read-only, Phase 2 D6).';

    public function handle(): int
    {
        $businesses = Business::withoutGlobalScopes()
            ->when($this->option('business'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id')->get();

        $rows = [];

        foreach ($businesses as $business) {
            TenantScope::enter($business->id);

            $deductions = Account::bySlug('employee_deductions_payable');

            if ($deductions === null) {
                continue;
            }

            $runIds = PayslipLine::query()
                ->where('code', ApplyStaffPurchaseRecovery::CODE)
                ->where('amount', '>', 0)
                ->join('payslips', 'payslips.id', '=', 'payslip_lines.payslip_id')
                ->distinct()
                ->pluck('payslips.payroll_run_id');

            foreach (PayrollRun::query()->whereIn('id', $runIds)->with('period')->orderBy('id')->get() as $run) {
                $journal = Journal::query()
                    ->where('source_type', $run->getMorphClass())
                    ->where('source_id', $run->id)
                    ->whereNull('reverses_journal_id')
                    ->first();

                if ($journal === null || $journal->hasBeenReversed()) {
                    continue;
                }

                $creditedTo2140 = (int) JournalLine::query()
                    ->where('journal_id', $journal->id)
                    ->where('account_id', $deductions->id)
                    ->sum('amount_minor');

                $recovered = (float) PayslipLine::query()
                    ->where('code', ApplyStaffPurchaseRecovery::CODE)
                    ->whereIn('payslip_id', $run->payslips()->select('id'))
                    ->sum('amount');

                $offsets = CustomerReceipt::query()
                    ->where('kind', CustomerReceipt::KIND_PAYROLL_OFFSET)
                    ->where('payroll_run_id', $run->id)
                    ->count();

                if ($offsets > 0 || $creditedTo2140 >= 0) {
                    continue;
                }

                $rows[] = [
                    $business->id,
                    $run->id,
                    ($run->period?->label() ?? '?').' v'.$run->version,
                    $journal->journal_number ?? ('#'.$journal->id),
                    number_format($recovered, 2),
                    number_format(abs($creditedTo2140) / 100, 2),
                ];
            }
        }

        TenantScope::leave();

        if ($rows === []) {
            $this->info('No payroll run credited 2140 for a staff purchase recovery without an offset.');

            return self::SUCCESS;
        }

        $this->warn('Payroll runs whose staff purchase recovery sits on 2140 with no invoice-linked offset. Repair through Corrections (reverse + re-post) with the Owner\'s sign-off; this command writes nothing.');
        $this->table(['Business', 'Run', 'Payroll', 'Journal', 'Staff purchase recovered', 'Credited to 2140 (all codes)'], $rows);

        return self::SUCCESS;
    }
}
