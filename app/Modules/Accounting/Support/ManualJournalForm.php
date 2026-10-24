<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Enums\JournalType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Counterparty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The manual journal form, read once (corrections wave 2, B7, 24 Oct 2026).
 *
 * ⭐ Moved out of JournalController::store so the record screen and a manual
 * journal's Correct read the SAME rules: one side per line, an amount that
 * parses, the party a party-kept head names, and the safeguard that keeps
 * Payroll, Inventory, Purchasing and Fixed Assets' own heads out of a hand
 * entry (opening balances exempt). Balance, postable heads, the period lock
 * and the date stay PostJournal's, decided once.
 */
final class ManualJournalForm
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'journal_date' => ['required', 'date'],
            'memo' => ['nullable', 'string', 'max:1000'],
            'journal_type' => ['required', Rule::enum(JournalType::class)],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')],
            'lines.*.debit' => ['nullable', 'string', 'max:20'],
            'lines.*.credit' => ['nullable', 'string', 'max:20'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
            'lines.*.party_id' => ['nullable', 'integer'],
            'lines.*.loan_contract_id' => ['nullable', 'integer'],
        ];
    }

    /** @throws ValidationException */
    public static function validate(array $input): array
    {
        return Validator::make($input, self::rules())->validate();
    }

    /**
     * The validated form as a journal draft.
     *
     * @throws ValidationException
     */
    public static function draft(array $data, ?string $memo = null): JournalDraft
    {
        $lines = [];
        $accounts = Account::query()->findMany(array_column($data['lines'], 'account_id'))->keyBy('id');

        foreach ($data['lines'] as $index => $line) {
            $debit = self::amountOrNull($line['debit'] ?? null, "lines.{$index}.debit");
            $credit = self::amountOrNull($line['credit'] ?? null, "lines.{$index}.credit");

            // ⚠ Exactly one side per line. A line carrying both is the shape
            // that a signed single amount cannot express, so it has to be
            // refused at the door rather than silently netted - netting a line
            // that says 500 debit AND 200 credit into 300 is a number nobody
            // typed.
            if ($debit !== null && $credit !== null) {
                throw ValidationException::withMessages([
                    "lines.{$index}.debit" => 'Put an amount in the debit column or the credit column, not both.',
                ]);
            }

            if ($debit === null && $credit === null) {
                throw ValidationException::withMessages([
                    "lines.{$index}.debit" => 'This line needs an amount.',
                ]);
            }

            $party = self::partyFor($accounts->get((int) $line['account_id']), $line['party_id'] ?? null, $index);
            $loanId = isset($line['loan_contract_id']) && $line['loan_contract_id'] !== '' ? (int) $line['loan_contract_id'] : null;

            $lines[] = $debit !== null
                ? JournalLineDraft::debit((int) $line['account_id'], $debit, $line['memo'] ?? null, $party, null, $loanId)
                : JournalLineDraft::credit((int) $line['account_id'], $credit, $line['memo'] ?? null, $party, null, $loanId);
        }

        $type = JournalType::from($data['journal_type']);
        $on = Carbon::parse($data['journal_date'])->startOfDay();

        self::assertNoSubsystemAccounts($data['lines'], $type, $on);

        return new JournalDraft(
            date: $on,
            lines: $lines,
            memo: $memo ?? ($data['memo'] ?? null),
            type: $type,
        );
    }

    /** The debit total of the form, in paisa (what a manual journal "is worth"). */
    public static function debitMinor(array $data): int
    {
        $total = 0;

        foreach ($data['lines'] ?? [] as $index => $line) {
            $debit = self::amountOrNull(isset($line['debit']) ? (string) $line['debit'] : null, "lines.{$index}.debit");
            $total += $debit?->minor ?? 0;
        }

        return $total;
    }

    /**
     * ⚠⚠ THE SAFEGUARD. Payroll, Inventory, Purchasing and Fixed Assets own the
     * accounts they are mapped to, and a human may not post to them by hand.
     * A wages figure with two possible origins can never be conclusively
     * reconciled, and the way that principle dies is one well-meaning
     * correction.
     *
     * ⚠ ENFORCED HERE AND NOT IN PostJournal, deliberately. The subsystems
     * themselves post to these accounts constantly and legitimately; the engine
     * must stay blind to what kind of journal it is handling, and a structural
     * test keeps it that way. What is being restricted is the MANUAL ENTRY PATH,
     * which is a property of this screen rather than of the ledger.
     *
     * ⚠ OPENING BALANCES ARE EXEMPT, and must be. The opening position of a
     * business is precisely a hand-entered figure for Stock on Hand, Accounts
     * Payable and Employee Advances - there is no subsystem history to derive it
     * from, because the history predates the system.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function assertNoSubsystemAccounts(array $lines, JournalType $type, Carbon $on): void
    {
        if ($type === JournalType::OpeningBalance) {
            return;
        }

        $owned = SubsystemAccounts::on($on);

        if ($owned === []) {
            return;
        }

        foreach ($lines as $index => $line) {
            $accountId = (int) ($line['account_id'] ?? 0);

            if (! isset($owned[$accountId])) {
                continue;
            }

            $account = Account::find($accountId);

            throw ValidationException::withMessages([
                "lines.{$index}.account_id" => sprintf(
                    '%s is maintained by another part of the system. Record the business event there instead - entries here would make that account impossible to reconcile.',
                    $account?->label() ?? 'That account',
                ),
            ]);
        }
    }

    /**
     * The employee, customer or supplier a party-kept head's line is about.
     *
     * ⚠ Only resolved, never required here: whether a line MUST name a party is
     * PostJournal's rule (assertPartyLedgersNameTheirParty), and it refuses with
     * a sentence. A party sent for an ordinary head is dropped, as on vouchers:
     * the form clears the picker when the account changes, and a stale id must
     * not block a journal that is otherwise right.
     */
    private static function partyFor(?Account $account, mixed $partyId, int $index): ?Model
    {
        $class = $account === null ? null : PartyLedgers::partyClassFor($account);

        if ($class === null || $partyId === null || $partyId === '') {
            return null;
        }

        // Tenant-scoped query: another business's employee is "not found".
        $party = $class::query()->find((int) $partyId);

        if ($party === null) {
            throw ValidationException::withMessages(["lines.{$index}.party_id" => $class === Counterparty::class
                ? 'That counterparty does not exist.'
                : 'That '.PartyLedgers::noun($class).' is not in this business\'s list.']);
        }

        if ($party instanceof Counterparty && ($refusal = CounterpartyRules::refusal($account, $party)) !== null) {
            throw ValidationException::withMessages(["lines.{$index}.party_id" => $refusal]);
        }

        return $party;
    }

    private static function amountOrNull(?string $raw, string $key): ?Money
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        try {
            $amount = Money::ofTaka($raw);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([$key => 'That is not an amount of money.']);
        }

        // A typed zero is a line somebody meant to delete. Treated as empty so
        // the "this line needs an amount" message is the one they get.
        return $amount->isZero() ? null : $amount;
    }
}
