<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\LedgerRounding;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records what the business spent on running itself.
 *
 *   Dr  each expense account named on the lines
 *       Cr  the money account it was paid from
 *
 * ⚠⚠ THIS DOCUMENT CANNOT EXIST WITHOUT THE CHART, and it is the one
 * integration with no "accounting not switched on" path. Every other event -
 * receiving stock, approving a payroll - is an operational act that happens to
 * have an accounting consequence, so it must still work with no ledger. An
 * expense voucher's entire content IS the classification: without accounts
 * there is nothing to record but an amount. Stated here so the exception reads
 * as a decision rather than an oversight.
 */
class RecordExpenseVoucher
{
    public function __construct(private readonly PostJournal $postJournal) {}

    /**
     * @param  array<int, array{account: Account, amount: string, description?: string}>  $lines
     *
     * @throws ValidationException
     */
    public function handle(
        MoneyAccount $moneyAccount,
        mixed $spentOn,
        array $lines,
        User $createdBy,
        ?Supplier $supplier = null,
        ?string $paidTo = null,
        ?string $reference = null,
        ?string $note = null,
    ): ExpenseVoucher {
        $date = Carbon::parse($spentOn)->startOfDay();

        AccountingPeriod::assertOpenFor($date);

        if ($date->isFuture()) {
            throw ValidationException::withMessages([
                'spent_on' => 'A voucher cannot be dated in the future.',
            ]);
        }

        $businessId = app(BusinessContext::class)->id();

        if ($moneyAccount->business_id !== $businessId) {
            throw ValidationException::withMessages([
                'money_account_id' => 'That account belongs to a different business.',
            ]);
        }

        if (! $moneyAccount->is_active) {
            throw ValidationException::withMessages([
                'money_account_id' => 'That account is no longer active.',
            ]);
        }

        if ($moneyAccount->account_id === null) {
            throw MappingNotFound::for("money account [{$moneyAccount->name}]", $date);
        }

        $lines = array_values(array_filter($lines, fn (array $line) => Money::ofTaka($line['amount'])->minor > 0));

        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'A voucher needs at least one line with an amount.',
            ]);
        }

        foreach ($lines as $index => $line) {
            $account = $line['account'];

            // ⚠ An expense voucher may only ever debit an EXPENSE account.
            // Without this the document becomes a free hand over the whole
            // ledger - somebody could pay rent into Sales Revenue and the books
            // would still balance perfectly.
            if ($account->type !== AccountType::Expense) {
                throw ValidationException::withMessages([
                    "lines.{$index}.account" => "{$account->label()} is not an expense account.",
                ]);
            }

            if (! $account->is_postable || ! $account->is_active) {
                throw ValidationException::withMessages([
                    "lines.{$index}.account" => "{$account->label()} cannot be posted to.",
                ]);
            }
        }

        return DB::transaction(function () use ($moneyAccount, $date, $lines, $createdBy, $supplier, $paidTo, $reference, $note, $businessId) {
            $total = Money::sum(array_map(fn (array $line) => Money::ofTaka($line['amount']), $lines));

            $voucher = ExpenseVoucher::create([
                'voucher_number' => $this->nextNumber($businessId, $date),
                'spent_on' => $date,
                'money_account_id' => $moneyAccount->id,
                'supplier_id' => $supplier?->id,
                'paid_to' => $paidTo,
                'total' => $total->toTaka(),
                'reference' => $reference,
                'note' => $note,
                'created_by' => $createdBy->id,
            ]);

            $journalLines = [];

            foreach ($lines as $line) {
                $amount = Money::ofTaka($line['amount']);

                $voucher->lines()->create([
                    'business_id' => $voucher->business_id,
                    'account_id' => $line['account']->id,
                    'description' => $line['description'] ?? null,
                    'amount' => $amount->toTaka(),
                ]);

                $journalLines[] = JournalLineDraft::debit($line['account']->id, $amount, $line['description'] ?? null);
            }

            $journalLines[] = JournalLineDraft::credit(
                $moneyAccount->account_id,
                $total,
                $moneyAccount->label(),
                $supplier,
                $moneyAccount,
            );

            $journalLines = LedgerRounding::balance($journalLines, $date, "Rounding on voucher {$voucher->voucher_number}");

            $this->postJournal->handle(new JournalDraft(
                date: $date,
                lines: $journalLines,
                memo: "Expense voucher {$voucher->voucher_number} - {$voucher->payee()}",
                source: $voucher,
            ), $createdBy);

            return $voucher->fresh('lines');
        });
    }

    /**
     * `EXP-2026-27-0001`, restarting each fiscal year.
     *
     * ⚠ Computed as max-plus-one inside the transaction rather than from a
     * locked sequence row, and the honest reason is that vouchers are entered by
     * one person at a keyboard, not by concurrent processes. **The unique index
     * on (business_id, voucher_number) is the real guarantee**: a genuine race
     * surfaces as a rejected save the user retries, never as two vouchers
     * sharing a number. Journals earned a locked sequence because subsystems
     * post them automatically and a collision there would abort a business
     * event nobody was watching.
     */
    private function nextNumber(int $businessId, Carbon $date): string
    {
        $business = Business::withoutGlobalScopes()->findOrFail($businessId);
        $label = FiscalYear::containing($date, $business)->label();
        $prefix = "EXP-{$label}-";

        $latest = ExpenseVoucher::query()
            ->where('voucher_number', 'like', $prefix.'%')
            ->orderByDesc('voucher_number')
            ->value('voucher_number');

        $next = $latest === null ? 1 : ((int) substr($latest, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
