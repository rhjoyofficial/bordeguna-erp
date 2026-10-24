<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Validation\Rule;

/**
 * ⭐ Initial plan B2 (R57, 25 Oct 2026): "some from bank, some from bKash, the
 * rest stays due". The request shape every purchase payment screen shares:
 *
 *   payment account (the first row)   pays the rest of the amount
 *   split_lines[]                     the OTHER accounts, each with its amount
 *
 * One row = no `split_lines` = the payment exactly as before. The picker lists
 * whatever money accounts the screen is given, so a new account kind (Employee
 * cash, B3) appears in it with no change here.
 */
final class PaymentSplit
{
    /** More rows than this is a typing accident, not a payment. */
    public const MAX_LINES = 8;

    /** @return array<string, mixed> */
    public static function rules(int $businessId): array
    {
        return [
            'split_lines' => ['nullable', 'array', 'max:'.self::MAX_LINES],
            'split_lines.*.money_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')->where('business_id', $businessId)],
            'split_lines.*.amount' => ['required', 'numeric', 'gt:0'],
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return [
            'split_lines.*.money_account_id' => 'account',
            'split_lines.*.amount' => 'amount',
        ];
    }

    /**
     * @param  array<string, mixed>  $data  validated
     * @return list<array{money_account: MoneyAccount, amount: string}>
     */
    public static function fromRequest(array $data): array
    {
        return array_values(array_map(fn (array $row) => [
            'money_account' => MoneyAccount::query()->findOrFail((int) $row['money_account_id']),
            'amount' => (string) $row['amount'],
        ], $data['split_lines'] ?? []));
    }
}
