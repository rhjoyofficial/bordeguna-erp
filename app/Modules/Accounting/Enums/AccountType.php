<?php

namespace App\Modules\Accounting\Enums;

/**
 * The five kinds of account every double-entry system has, and the two
 * statements they divide into.
 *
 * ⚠ Unlike InventoryAdjustmentType, this enum IS matched on, and that is
 * correct: an account's normal balance is not configuration, it is what the
 * type MEANS. An asset with a credit normal balance is not an unusual asset,
 * it is a liability.
 *
 * ⚠ It is also a genuinely closed set. Accounting has had these five for five
 * centuries; a sixth is not coming. Contra accounts (accumulated depreciation,
 * sales returns) are NOT a sixth type - they are ordinary accounts of an
 * existing type that happen to carry the opposite sign, which the ledger
 * represents naturally because it stores signed amounts.
 */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    /**
     * Which side of the ledger this type normally sits on.
     *
     * ⚠ DERIVED, never stored beside the type. Two columns that must agree are
     * one column and a future disagreement, and this project has already paid
     * for that lesson in stock quantities.
     */
    public function normalBalance(): NormalBalance
    {
        return match ($this) {
            self::Asset, self::Expense => NormalBalance::Debit,
            self::Liability, self::Equity, self::Income => NormalBalance::Credit,
        };
    }

    /**
     * Balance sheet or profit and loss. Decides which statement an account
     * appears on, and - later - which balances are closed off at year end.
     */
    public function isBalanceSheet(): bool
    {
        return match ($this) {
            self::Asset, self::Liability, self::Equity => true,
            self::Income, self::Expense => false,
        };
    }

    public function label(): string
    {
        return [
            'asset' => 'Asset',
            'liability' => 'Liability',
            'equity' => 'Equity',
            'income' => 'Income',
            'expense' => 'Expense',
        ][$this->value] ?? $this->value;
    }
}
