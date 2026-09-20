<?php

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Enums\JournalType;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\VatReturn;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\VatRegime;
use App\Modules\Accounting\Support\VatReturnBuilder;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Files one VAT period (VAT plan D9): freezes the figures and moves the
 * period's VAT onto VAT Settlement.
 *
 * ⚠⚠ THIS IS NOT "PAY VAT". Paying is a separate, ordinary payment voucher
 * against 2175 VAT Settlement, made on the day the money actually leaves the
 * bank. Filing only says what the period came to:
 *
 *   VAT registered   Dr 2170 Output VAT   (the period's output)
 *                    Cr 1185 Input VAT    (the period's claimable input)
 *                    Cr / Dr 2175         (the difference)
 *   Turnover tax     Dr 6511 Turnover Tax / Cr 2175
 *
 * A period where input exceeds output leaves a DEBIT on 2175: a credit owed to
 * the business. It is never paid out; the next period's return starts from it
 * (VatReturnBuilder::creditBroughtForward). Nothing invents a bank payment.
 *
 * ⚠ Dated the LAST DAY OF THE PERIOD, so the balance sheet at month end shows
 * one VAT figure rather than two, and refused into a closed period like every
 * other posting. One return per period, enforced by a unique index as well.
 */
class FileVatReturn
{
    public function __construct(
        private readonly VatReturnBuilder $builder,
        private readonly PostJournal $postJournal,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Business $business, Carbon $start, Carbon $end, User $by, ?string $note = null): VatReturn
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->startOfDay();
        $regime = VatRegime::tryFrom((string) $business->vat_regime) ?? VatRegime::NotRegistered;

        if ($regime === VatRegime::NotRegistered) {
            throw ValidationException::withMessages(['period' => 'This business is not registered for VAT or turnover tax, so there is no return to file.']);
        }

        if ($end->greaterThanOrEqualTo(Carbon::today())) {
            throw ValidationException::withMessages(['period' => 'A period is filed after it has ended. '.$end->format('j M Y').' is not over yet.']);
        }

        AccountingPeriod::assertOpenFor($end);

        return DB::transaction(function () use ($business, $start, $end, $by, $note, $regime) {
            $already = VatReturn::query()
                ->whereDate('period_start', $start)->whereDate('period_end', $end)
                ->lockForUpdate()->first();

            if ($already !== null) {
                throw ValidationException::withMessages(['period' => "This period was already filed as {$already->return_number}."]);
            }

            $figures = $this->builder->build($business, $start, $end);

            if (! $figures['agrees']) {
                throw ValidationException::withMessages([
                    'period' => 'The documents and the ledger disagree about this period\'s VAT. Find the difference on the screen before filing; a return is not filed on figures that do not explain themselves.',
                ]);
            }

            $return = VatReturn::create([
                'business_id' => $business->id,
                'return_number' => 'VAT-'.$start->format('Y-m').($start->isSameMonth($end) ? '' : '-to-'.$end->format('Y-m')),
                'regime' => $regime->value,
                'period_start' => $start,
                'period_end' => $end,
                'status' => VatReturn::FILED,
                'output_tax_minor' => $figures['output_tax_minor'],
                'input_tax_minor' => $figures['input_tax_minor'],
                'credit_brought_forward_minor' => $figures['credit_brought_forward_minor'],
                'turnover_minor' => $figures['turnover_minor'],
                'turnover_tax_rate' => $figures['turnover_tax_rate'],
                'net_payable_minor' => $figures['net_payable_minor'],
                'credit_carried_forward_minor' => $figures['credit_carried_forward_minor'],
                'filed_by' => $by->id,
                'filed_at' => now(),
                'created_by' => $by->id,
                'note' => $note,
            ]);

            $lines = $regime === VatRegime::TurnoverTax
                ? $this->turnoverLines($figures, $end, $return)
                : $this->vatLines($figures, $end, $return);

            if ($lines !== []) {
                $journal = $this->postJournal->handle(new JournalDraft(
                    date: $end,
                    lines: $lines,
                    memo: "VAT return {$return->return_number}",
                    source: $return,
                    type: JournalType::Manual,
                ), $by);

                $return->forceFill(['journal_id' => $journal->id])->save();
            }

            return $return->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $figures
     * @return array<int, JournalLineDraft>
     */
    private function vatLines(array $figures, Carbon $on, VatReturn $return): array
    {
        $output = Money::ofMinor((int) $figures['output_tax_minor']);
        $input = Money::ofMinor((int) $figures['input_tax_minor']);

        if ($output->isZero() && $input->isZero()) {
            return [];
        }

        $settlement = AccountMapping::resolve('accounting.vat_settlement', $on);
        $lines = [];

        // Signed on purpose: a month of net returns has NEGATIVE output, and
        // clearing it is then a credit to Output VAT, not a debit.
        if (! $output->isZero()) {
            $lines[] = JournalLineDraft::signed(AccountMapping::resolve('sales.output_vat', $on)->id, $output, "Output VAT, {$return->return_number}");
        }

        if (! $input->isZero()) {
            $lines[] = JournalLineDraft::signed(AccountMapping::resolve('purchasing.input_vat', $on)->id, $input->negated(), "Input VAT, {$return->return_number}");
        }

        $difference = Money::ofMinor($input->minor - $output->minor);

        if (! $difference->isZero()) {
            $lines[] = JournalLineDraft::signed($settlement->id, $difference, "VAT for {$return->return_number}");
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $figures
     * @return array<int, JournalLineDraft>
     */
    private function turnoverLines(array $figures, Carbon $on, VatReturn $return): array
    {
        $tax = Money::ofMinor((int) $figures['turnover_tax_minor']);

        if ($tax->isZero()) {
            return [];
        }

        return [
            JournalLineDraft::debit(AccountMapping::resolve('accounting.turnover_tax_expense', $on)->id, $tax, "Turnover tax, {$return->return_number}"),
            JournalLineDraft::credit(AccountMapping::resolve('accounting.vat_settlement', $on)->id, $tax, "Turnover tax for {$return->return_number}"),
        ];
    }
}
