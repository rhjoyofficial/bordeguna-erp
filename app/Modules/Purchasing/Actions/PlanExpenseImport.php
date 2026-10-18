<?php

namespace App\Modules\Purchasing\Actions;

use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\VoucherAccounts;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Support\ExpenseImportPlan;
use App\Support\DayFirstDate;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Works out what an expense file would pay, without paying any of it.
 *
 * ⚠⚠ THE DUPLICATE CHECK IS THE REASON THIS CLASS EXISTS. An expense voucher
 * in this ERP is PAID the moment it is recorded, so importing the same file
 * twice does not create a tidy duplicate to delete later - it records a second
 * payment that never happened, on a date already reconciled. So a reference
 * that already exists in the business is a hard ERROR, and so is the same
 * reference twice inside one file.
 *
 * ⚠ The whole existing reference list is read ONCE, up front. Checking per row
 * with a query each time cannot see the row two lines above it.
 */
class PlanExpenseImport
{
    public function handle(array $rows, array $ignoredColumns = []): ExpenseImportPlan
    {
        $plan = new ExpenseImportPlan;
        $plan->ignoredColumns = $ignoredColumns;

        $accounts = Account::query()
            ->where('type', AccountType::Expense)
            ->get()
            ->keyBy(fn (Account $a) => strtoupper(trim($a->code)));

        $moneyAccounts = MoneyAccount::query()->handled()
            ->get()
            ->keyBy(fn (MoneyAccount $a) => strtoupper(trim($a->name)));

        $suppliers = Supplier::query()
            ->get()
            ->keyBy(fn (Supplier $s) => strtoupper(trim($s->name)));

        // ⚠⚠ Every reference already spent in this business, uppercased. This
        // is the guard between "import the file again to be safe" and paying
        // the hosting bill twice.
        $spent = ExpenseVoucher::query()
            ->whereNotNull('reference')
            // PUR-003 (14 Oct 2026): a reversed voucher spent nothing, so its
            // reference may be imported again as the corrected payment.
            ->whereNull('reversed_at')
            ->pluck('voucher_number', 'reference')
            ->mapWithKeys(fn ($number, $reference) => [strtoupper(trim((string) $reference)) => $number]);

        $seen = [];

        foreach ($rows as $row) {
            $line = $row['line'];
            $values = $row['values'];
            $reference = trim($values['reference'] ?? '');
            $key = strtoupper($reference);

            if ($reference !== '' && isset($spent[$key])) {
                $plan->addError($line, $reference,
                    "Reference \"{$reference}\" is already on voucher {$spent[$key]}. Importing it again would record a second payment that never happened.");

                continue;
            }

            if ($reference !== '' && isset($seen[$key])) {
                $plan->addError($line, $reference,
                    "The same reference appears on line {$seen[$key]} of this file as well.");

                continue;
            }

            if ($reference === '') {
                // ⚠ Allowed, but said out loud. A row with no reference cannot
                // be duplicate-checked on any future import, so the person
                // approving this one should know that.
                $plan->addWarning($line, '(no reference)',
                    'This row has no reference, so a future import cannot tell it apart from a repeat of itself.');
            } else {
                $seen[$key] = $line;
            }

            $date = $this->date($values['date'] ?? '');

            if ($date === null) {
                $plan->addError($line, $reference, 'The date is missing or is not a date the system can read. Use YYYY-MM-DD.');

                continue;
            }

            if ($date->isFuture()) {
                $plan->addError($line, $reference, 'This is dated in the future. An expense voucher records money that has already gone.');

                continue;
            }

            try {
                // ⚠ Checked HERE as well as in the action, so a closed month is
                // reported on the preview rather than half way through applying
                // sixty rows.
                AccountingPeriod::assertOpenFor($date);
            } catch (ValidationException $e) {
                $plan->addError($line, $reference, $date->format('F Y').' is closed, so nothing can be posted into it.');

                continue;
            }

            $code = strtoupper(trim($values['account_code'] ?? ''));
            $account = $accounts->get($code);

            if ($account === null) {
                $plan->addError($line, $reference, "There is no expense account with the code \"{$code}\".");

                continue;
            }

            if (! $account->is_postable || ! $account->is_active) {
                $plan->addError($line, $reference, "{$account->label()} cannot be posted to.");

                continue;
            }

            // ⚠ PUR-002 (audit 14 Oct 2026): the voucher's own rule, on the
            // preview, so a Salaries row is refused before anything is applied.
            if (($refusal = VoucherAccounts::refusal($account, $date)) !== null) {
                $plan->addError($line, $reference, "{$account->label()}: {$refusal}");

                continue;
            }

            $paidFrom = strtoupper(trim($values['paid_from'] ?? ''));
            $moneyAccount = $moneyAccounts->get($paidFrom);

            if ($moneyAccount === null) {
                $plan->addError($line, $reference,
                    "There is no account called \"{$values['paid_from']}\" to pay from. Use the name exactly as it appears on the expense screen.");

                continue;
            }

            if (! $moneyAccount->is_active) {
                $plan->addError($line, $reference, "{$moneyAccount->name} is no longer active.");

                continue;
            }

            $amount = $this->amount($values['amount'] ?? '');

            if ($amount === null || $amount <= 0) {
                $plan->addError($line, $reference, 'The amount is missing, is not a number, or is not more than zero.');

                continue;
            }

            $payee = trim($values['payee'] ?? '');
            $supplier = $payee === '' ? null : $suppliers->get(strtoupper($payee));

            if ($payee !== '' && $supplier === null) {
                // ⚠ NOT an error. A payee can perfectly well be somebody who is
                // not a supplier - a rickshaw fare, a one-off repair - and the
                // voucher has a free-text field for exactly that. The warning
                // exists so a MISSPELT supplier does not quietly become one.
                $plan->addWarning($line, $reference === '' ? '(no reference)' : $reference,
                    "\"{$payee}\" is not a supplier on file, so it will be recorded as a typed payee name.");
            }

            $plan->vouchers[] = [
                'line' => $line,
                'date' => $date->toDateString(),
                'payee' => $payee,
                'account' => $account->label(),
                'description' => trim($values['description'] ?? ''),
                'amount' => $amount,
                'paid_from' => $moneyAccount->name,
                'reference' => $reference,
                'note' => trim($values['note'] ?? ''),
                'account_id' => $account->id,
                'money_account_id' => $moneyAccount->id,
                'supplier_id' => $supplier?->id,
            ];
        }

        return $plan;
    }

    private function date(string $given): ?Carbon
    {
        $given = trim($given);

        if ($given === '') {
            return null;
        }

        // ⚠ The formats are pinned rather than left to Carbon's parser, which
        // reads 03/09/2026 as 9 March in one locale and 3 September in another.
        // A file whose dates are silently a month out is the worst outcome here.
        // 18 Oct 2026: a one-digit day or month (5/9/2026) is read day first too.
        $dayFirst = DayFirstDate::toIso($given);

        if ($dayFirst !== null) {
            return Carbon::createFromFormat('Y-m-d', $dayFirst)->startOfDay();
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d'] as $format) {
            try {
                // ⚠ Carbon runs in strict mode here, so a format that does not
                // fit THROWS rather than returning false. Caught per format so
                // the next one still gets its turn.
                $date = Carbon::createFromFormat($format, $given);
            } catch (InvalidFormatException) {
                continue;
            }

            if ($date !== false && $date->format($format) === $given) {
                return $date->startOfDay();
            }
        }

        return null;
    }

    private function amount(string $given): ?float
    {
        // Thousands separators and a stray currency symbol are what a
        // spreadsheet produces, not a mistake worth refusing a file over.
        $clean = str_replace([',', ' ', "\u{09F3}", 'BDT', 'Tk', 'tk'], '', trim($given));

        if ($clean === '' || ! is_numeric($clean)) {
            return null;
        }

        return round((float) $clean, 2);
    }
}
