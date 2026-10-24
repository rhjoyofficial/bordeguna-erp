<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Purchasing\Enums\LandedCostAllocationMethod;
use App\Modules\Purchasing\Enums\LandedCostTreatment;
use App\Modules\Purchasing\Enums\LandedCostType;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ The charges and the payment plan typed on a purchase order (purchase
 * redesign PR1), checked when the ORDER is saved, with the landed-cost
 * engine's own rules (SaveLandedCost::prepare), so a charge that would be
 * refused on the receipt is refused now, under its own box, while the person
 * who typed it is still there. The receipt then never fails on its charges.
 */
final class PurchaseOrderCharges
{
    /** The types an order offers, Tally's "additional cost of purchase". */
    public const ORDER_TYPES = ['freight', 'labour', 'insurance', 'duty', 'packaging', 'other'];

    /**
     * @param  list<array<string, mixed>>  $charges  cost_type, description?, amount, allocation_method?, line_index?, supplier_id?, money_account_id?, treatment?, override_reason?, expense_account_id?
     * @return list<array<string, mixed>> ready for purchase_order_charges (line_index kept for the caller to map)
     *
     * @throws ValidationException
     */
    public static function checked(array $charges, int $lineCount): array
    {
        $problems = [];
        $checked = [];

        foreach (array_values($charges) as $index => $charge) {
            $field = fn (string $name) => "charges.{$index}.{$name}";
            $type = LandedCostType::tryFrom((string) ($charge['cost_type'] ?? ''));

            if ($type === null) {
                $problems[$field('cost_type')] = 'Pick what the charge is for.';

                continue;
            }

            $amount = Money::ofTaka((string) ($charge['amount'] ?? '0'));

            if ($amount->minor <= 0) {
                $problems[$field('amount')] = 'The amount must be above zero.';
            }

            $treatment = empty($charge['treatment']) ? $type->defaultTreatment() : LandedCostTreatment::tryFrom((string) $charge['treatment']);
            $reason = trim((string) ($charge['override_reason'] ?? ''));
            $description = trim((string) ($charge['description'] ?? '')) ?: null;

            if ($treatment === null) {
                $problems[$field('treatment')] = 'Pick whether it goes into the item cost or is an expense.';

                continue;
            }

            if ($treatment !== $type->defaultTreatment() && $reason === '') {
                $problems[$field('override_reason')] = $type->label().' is '.strtolower($type->defaultTreatment()->label()).' by default. Give the reason for treating it as '.strtolower($treatment->label()).'.';
            }

            if ($type === LandedCostType::Other && $treatment === LandedCostTreatment::Capitalize && $description === null) {
                $problems[$field('description')] = 'Say what this charge is before adding it to the item cost: an unclassified cost stays an expense.';
            }

            $expenseAccountId = empty($charge['expense_account_id']) ? null : (int) $charge['expense_account_id'];

            if ($treatment === LandedCostTreatment::Expense && $type->expenseMappingKey() === null) {
                $account = $expenseAccountId === null ? null : Account::query()->find($expenseAccountId);

                if ($account === null || $account->type !== AccountType::Expense || ! $account->is_postable || ! $account->is_active) {
                    $problems[$field('expense_account_id')] = 'Name the expense head this charge belongs to.';
                }
            }

            $method = LandedCostAllocationMethod::tryFrom((string) ($charge['allocation_method'] ?? '') ?: LandedCostAllocationMethod::Value->value);
            $lineIndex = isset($charge['line_index']) && $charge['line_index'] !== '' && $charge['line_index'] !== null ? (int) $charge['line_index'] : null;

            if ($method === null) {
                $problems[$field('allocation_method')] = 'Pick how the charge spreads over the items.';
            } elseif ($method === LandedCostAllocationMethod::Direct && ($lineIndex === null || $lineIndex < 0 || $lineIndex >= $lineCount)) {
                $problems[$field('line_index')] = 'Pick the item the charge belongs to.';
            }

            $supplierId = empty($charge['supplier_id']) ? null : (int) $charge['supplier_id'];
            $moneyAccountId = empty($charge['money_account_id']) ? null : (int) $charge['money_account_id'];

            if (($supplierId === null) === ($moneyAccountId === null)) {
                $problems[$field('payee')] = 'Say who is paid: a supplier (owed) or a money account (paid now), one of the two.';
            } elseif ($supplierId !== null && ! Supplier::query()->whereKey($supplierId)->exists()) {
                $problems[$field('payee')] = 'That supplier is not in this business.';
            } elseif ($moneyAccountId !== null) {
                $account = MoneyAccount::query()->whereKey($moneyAccountId)->first();

                if ($account === null || ! $account->is_active) {
                    $problems[$field('payee')] = 'That money account is not active in this business.';
                } elseif (($refusal = $account->handRefusal()) !== null) {
                    $problems[$field('payee')] = $refusal;
                }
            }

            $checked[] = [
                'cost_type' => $type->value,
                'description' => $description,
                'amount_paisa' => $amount->minor,
                'allocation_method' => $treatment === LandedCostTreatment::Capitalize ? ($method?->value ?? 'value') : LandedCostAllocationMethod::Value->value,
                'line_index' => $method === LandedCostAllocationMethod::Direct && $treatment === LandedCostTreatment::Capitalize ? $lineIndex : null,
                'supplier_id' => $supplierId,
                'money_account_id' => $supplierId === null ? $moneyAccountId : null,
                // ⭐ Initial plan B1 (R49): a one-off person paid from the
                // account (a truck owner, a restaurant), named, not registered.
                'payee_name' => $supplierId === null && $moneyAccountId !== null ? (mb_substr(trim((string) ($charge['payee_name'] ?? '')), 0, 120) ?: null) : null,
                'treatment' => $treatment === $type->defaultTreatment() ? null : $treatment->value,
                'override_reason' => $treatment === $type->defaultTreatment() ? null : $reason,
                'expense_account_id' => $treatment === LandedCostTreatment::Expense ? $expenseAccountId : null,
            ];
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        return $checked;
    }

    /**
     * The planned part payments: a date and an amount each, together no more
     * than the order's grand total.
     *
     * @param  list<array<string, mixed>>  $rows  due_on, amount, note?
     * @return list<array{due_on: string, amount_paisa: int, note: ?string}>
     *
     * @throws ValidationException
     */
    public static function schedule(array $rows, Money $grandTotal): array
    {
        $problems = [];
        $checked = [];

        foreach (array_values($rows) as $index => $row) {
            $amount = Money::ofTaka((string) ($row['amount'] ?? '0'));

            if ($amount->minor <= 0) {
                $problems["schedule.{$index}.amount"] = 'The amount must be above zero.';
            }

            if (empty($row['due_on'])) {
                $problems["schedule.{$index}.due_on"] = 'Pick the date this part is due.';
            }

            $checked[] = ['due_on' => (string) ($row['due_on'] ?? ''), 'amount_paisa' => $amount->minor, 'note' => trim((string) ($row['note'] ?? '')) ?: null];
        }

        $planned = array_sum(array_column($checked, 'amount_paisa'));

        if ($problems === [] && $planned > $grandTotal->minor) {
            $problems['schedule'] = 'The planned payments add up to '.Money::ofMinor($planned)->format().', more than the order total of '.$grandTotal->format().'.';
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        return $checked;
    }
}
