<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Enums\NormalBalance;
use App\Modules\Accounting\Models\Account;

/**
 * One account's line on a financial statement.
 *
 * ⚠ `amount` carries the LEDGER'S SIGN, not the reader's. An income line is
 * negative here and printed positive; a contra-income line (4200 Sales Returns,
 * 4210 Discounts Allowed) is positive here and reduces its section total by
 * ordinary addition. That is why contra accounts need no special case anywhere
 * in this slice (C-FS-2, C-FS-7): the ledger stores one signed amount and the
 * arithmetic already works.
 */
final class StatementLine
{
    /**
     * @param  ?Account  $group  the sub-group heading inside the section, when the head sits deeper
     *                           than the section (a bank account under Bank Accounts under Current Assets)
     */
    public function __construct(
        public readonly Account $account,
        public readonly Money $amount,
        public readonly ?Account $group = null,
    ) {}

    /**
     * ⚠ Presented positive, with the sign carried separately. A reader of a
     * profit and loss should never have to work out whether a negative expense
     * is a refund or a formatting accident - and a line that is genuinely the
     * wrong way round for its section is worth SEEING, which `isContra` makes
     * possible without changing the arithmetic.
     */
    public function presentedAmount(): Money
    {
        return $this->amount->absolute();
    }

    /**
     * True when this account sits on the opposite side from its type's normal
     * balance: accumulated depreciation under assets, sales returns under
     * income. Presentation may show it in brackets; the maths does not care.
     */
    public function isContra(): bool
    {
        if ($this->amount->isZero()) {
            return false;
        }

        return $this->account->type->normalBalance() === NormalBalance::Debit
            ? $this->amount->isCredit()
            : $this->amount->isDebit();
    }
}
