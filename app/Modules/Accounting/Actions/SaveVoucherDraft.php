<?php

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Enums\VoucherStatus;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\PartyHeads;
use App\Modules\Accounting\Support\PartyLedgers;
use App\Modules\Accounting\Support\VoucherAccounts;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or rewrites a DRAFT voucher. Nothing here reaches the ledger.
 *
 * ⚠⚠ A POSTED VOUCHER NEVER PASSES THROUGH HERE. The guard is the first line,
 * not a rule in a form request, because this action is also what "Reverse and
 * copy" and "Duplicate" call.
 *
 * ⚠ A draft may be incomplete on purpose - that is what a draft is for. What
 * IS checked here is everything that would make it nonsense rather than merely
 * unfinished: an account a voucher may not touch, a party on the wrong head,
 * another business's bank. The full "is this postable" check is PostVoucher's.
 */
class SaveVoucherDraft
{
    /**
     * @param  array{voucher_date: string, money_account_id?: int|null, to_money_account_id?: int|null, counterparty?: string|null,
     *     narration?: string|null, instrument_type?: string|null, instrument_number?: string|null, instrument_date?: string|null,
     *     instrument_bank?: string|null, amount?: string|null, copied_from_id?: int|null,
     *     lines?: array<int, array{account_id: int, side?: string|null, amount: string, narration?: string|null, party_id?: int|null}>}  $data
     *
     * @throws ValidationException
     */
    public function handle(VoucherType $type, array $data, User $by, ?Voucher $voucher = null): Voucher
    {
        if ($voucher !== null && ! $voucher->isDraft()) {
            throw ValidationException::withMessages([
                'voucher' => "{$voucher->title()} is posted and cannot be changed. Reverse it and enter it again.",
            ]);
        }

        $date = Carbon::parse($data['voucher_date'])->startOfDay();
        $businessId = app(BusinessContext::class)->id();

        // A contra's SOURCE may be a payment gateway (its payout reaching the bank).
        $from = $this->moneyAccount($data['money_account_id'] ?? null, 'money_account_id', $businessId, allowGateway: $type === VoucherType::Contra);
        $to = $this->moneyAccount($data['to_money_account_id'] ?? null, 'to_money_account_id', $businessId);

        if (! $type->usesMoneyAccount()) {
            $from = null;
        }

        if ($type !== VoucherType::Contra) {
            $to = null;
        } elseif ($from !== null && $to !== null && $from->id === $to->id) {
            throw ValidationException::withMessages(['to_money_account_id' => 'Money cannot be moved into the account it came from.']);
        }

        $lines = $type->hasLines() ? $this->lines($type, $data['lines'] ?? [], $date) : [];

        $total = $type->hasLines()
            ? $this->totalOf($type, $lines)
            : Money::ofTaka((string) ($data['amount'] ?? '0'));

        if ($total->minor < 0) {
            throw ValidationException::withMessages(['amount' => 'The amount cannot be negative.']);
        }

        return DB::transaction(function () use ($type, $data, $by, $voucher, $date, $from, $to, $lines, $total, $businessId) {
            $attributes = [
                'voucher_date' => $date,
                'money_account_id' => $from?->id,
                'to_money_account_id' => $to?->id,
                'counterparty' => $this->text($data['counterparty'] ?? null),
                'narration' => $this->text($data['narration'] ?? null),
                'instrument_type' => $type->usesMoneyAccount() ? ($data['instrument_type'] ?? null) : null,
                'instrument_number' => $type->usesMoneyAccount() ? $this->text($data['instrument_number'] ?? null) : null,
                'instrument_date' => $type->usesMoneyAccount() ? ($data['instrument_date'] ?? null) : null,
                'instrument_bank' => $type->usesMoneyAccount() ? $this->text($data['instrument_bank'] ?? null) : null,
                'total_minor' => $total->minor,
            ];

            // ⚠⚠ ACC-003 (14 Oct 2026): the draft check above read the model
            // the route bound, with no lock. A Post pressed by another user in
            // between committed `posted`, and this update then rewrote the
            // lines and total of a voucher whose journal no longer matched.
            // Lock the row and ask again here, where nothing can slip between.
            if ($voucher !== null) {
                $voucher = Voucher::query()->lockForUpdate()->findOrFail($voucher->id);

                if (! $voucher->isDraft()) {
                    throw ValidationException::withMessages([
                        'voucher' => "{$voucher->title()} was posted or sent for approval while you were editing it, so your changes were not saved. Reverse it and enter it again if it is wrong.",
                    ]);
                }
            }

            if ($voucher === null) {
                $voucher = Voucher::create($attributes + [
                    'business_id' => $businessId,
                    'type' => $type,
                    'status' => VoucherStatus::Draft,
                    'copied_from_id' => $data['copied_from_id'] ?? null,
                    'created_by' => $by->id,
                ]);
            } else {
                $voucher->update($attributes);
                $voucher->lines()->delete();
            }

            foreach ($lines as $index => $line) {
                $voucher->lines()->create($line + ['business_id' => $voucher->business_id, 'line_no' => $index + 1]);
            }

            return $voucher->fresh('lines');
        });
    }

    private function moneyAccount(mixed $id, string $field, ?int $businessId, bool $allowGateway = false): ?MoneyAccount
    {
        if ($id === null || $id === '') {
            return null;
        }

        // ⚠ Resolved through the tenant scope: another business's bank is
        // "not found", never "found and refused".
        $account = MoneyAccount::query()
            ->when(
                $allowGateway,
                fn ($query) => $query->where('type', '!=', MoneyAccountType::Courier->value),
                fn ($query) => $query->handled(),
            )
            ->find((int) $id);

        if ($account === null || $account->business_id !== $businessId) {
            throw ValidationException::withMessages([$field => 'Choose one of this business\'s cash, bank or mobile accounts.']);
        }

        return $account;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function lines(VoucherType $type, array $rows, Carbon $date): array
    {
        $lines = [];

        foreach (array_values($rows) as $index => $row) {
            $amount = Money::ofTaka((string) ($row['amount'] ?? '0'));

            // An untouched row at the bottom of the form is not a line.
            if (($row['account_id'] ?? null) === null && $amount->minor === 0) {
                continue;
            }

            $account = Account::find((int) ($row['account_id'] ?? 0));

            if ($account === null) {
                throw ValidationException::withMessages(["lines.{$index}.account_id" => 'Choose an account for this line.']);
            }

            if (($refusal = VoucherAccounts::refusal($account, $date, $type)) !== null) {
                throw ValidationException::withMessages(["lines.{$index}.account_id" => $refusal]);
            }

            if ($amount->minor < 0) {
                throw ValidationException::withMessages(["lines.{$index}.amount" => 'An amount cannot be negative.']);
            }

            $side = $type->fixedLineSide() ?? ($row['side'] ?? null);

            if (! in_array($side, ['debit', 'credit'], true)) {
                throw ValidationException::withMessages(["lines.{$index}.side" => 'Say whether this line is a debit or a credit.']);
            }

            // A person's own head names its person: no second picker to agree with.
            $person = $account->is_postable ? null : PartyHeads::entityFor($account);

            [$partyType, $partyId] = $person !== null
                ? [$person->getMorphClass(), $person->getKey()]
                : $this->party($account, $row['party_id'] ?? null, $index);

            $lines[] = [
                'account_id' => $account->id,
                'side' => $side,
                'amount_minor' => $amount->minor,
                'narration' => $this->text($row['narration'] ?? null),
                'party_type' => $partyType,
                'party_id' => $partyId,
            ];
        }

        return $lines;
    }

    /**
     * @return array{0: ?string, 1: ?int}
     */
    private function party(Account $account, mixed $partyId, int $index): array
    {
        $class = VoucherAccounts::partyClassFor($account);
        $given = $partyId !== null && $partyId !== '';

        if ($class === null) {
            // ⚠ Dropped silently rather than refused: the form clears the
            // picker when the account changes, and a stale id from a re-used
            // row must not block a draft that is otherwise right.
            return [null, null];
        }

        if (! $given) {
            // A draft may not know its party yet; posting insists.
            return [null, null];
        }

        $party = $class::query()->find((int) $partyId);

        if ($party === null) {
            throw ValidationException::withMessages(["lines.{$index}.party_id" => 'That '.PartyLedgers::noun($class).' is not in this business\'s list.']);
        }

        return [$party->getMorphClass(), $party->getKey()];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function totalOf(VoucherType $type, array $lines): Money
    {
        // A journal voucher's total is its debit side; the others have one side.
        $minor = 0;

        foreach ($lines as $line) {
            if ($type !== VoucherType::Journal || $line['side'] === 'debit') {
                $minor += $line['amount_minor'];
            }
        }

        return Money::ofMinor($minor);
    }

    private function text(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
