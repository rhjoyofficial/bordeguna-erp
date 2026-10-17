<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The SHAPE of an Other income form, shared by "Record income" and "Correct"
 * (PHASE-0-CONTRACT 3.3), so the screen and the correction can never drift, the
 * way RecordIncomeVoucher::SALES_ONLY already keeps the picker and the action in
 * step. The accounting rules (income heads only, no sales heads, an active money
 * account with a ledger mapping, not in the future, the period lock) stay in
 * RecordIncomeVoucher and are applied to a correction's successor by the same
 * action.
 */
final class IncomeVoucherRules
{
    public static function rules(): array
    {
        $businessId = app(BusinessContext::class)->id();

        return [
            // ⚠ Scoped exists, never a bare one: a bare rule accepts another
            // business's id and the global scope then hides the row the document
            // points at.
            'money_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')->where('business_id', $businessId)],
            'received_on' => ['required', 'date'],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('business_id', $businessId)],
            'received_from' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            // ⚠ The chart of accounts is GLOBAL (no business_id), so this exists
            // rule is correctly unscoped. The action refuses anything that is not
            // a postable, active, non-sales income account.
            'lines.*.account_id' => ['required', 'integer', 'distinct', 'exists:accounts,id'],
            'lines.*.amount' => ['required', 'numeric', 'gt:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** Validate a raw array (a correction's replacement) with the same rules. */
    public static function validate(array $input): array
    {
        return Validator::make($input, self::rules())->validate();
    }

    /**
     * The validated form as RecordIncomeVoucher's arguments.
     *
     * @return array{moneyAccount: MoneyAccount, receivedOn: string, lines: list<array{account: Account, amount: string, description: ?string}>, customer: ?Customer, receivedFrom: ?string, reference: ?string, note: ?string}
     */
    public static function arguments(array $data): array
    {
        $lines = [];

        foreach ($data['lines'] as $row) {
            $lines[] = [
                'account' => Account::findOrFail($row['account_id']),
                'amount' => (string) $row['amount'],
                'description' => $row['description'] ?? null,
            ];
        }

        return [
            'moneyAccount' => MoneyAccount::findOrFail($data['money_account_id']),
            'receivedOn' => (string) $data['received_on'],
            'lines' => $lines,
            'customer' => empty($data['customer_id']) ? null : Customer::findOrFail($data['customer_id']),
            'receivedFrom' => $data['received_from'] ?? null,
            'reference' => $data['reference'] ?? null,
            'note' => $data['note'] ?? null,
        ];
    }

    /** Total of the lines in paisa, the way RecordIncomeVoucher adds them up. */
    public static function totalMinor(array $data): int
    {
        return Money::sum(array_map(
            fn (array $row) => Money::ofTaka((string) $row['amount']),
            array_values(array_filter($data['lines'] ?? [], fn ($row) => Money::ofTaka((string) ($row['amount'] ?? '0'))->minor > 0)),
        ))->minor;
    }
}
