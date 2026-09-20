<?php

namespace App\Modules\Accounting\Support;

/**
 * Which tax the business stands under (VAT plan D5).
 *
 * ⚠⚠ TURNOVER TAX IS NOT A VAT MODE. It is a different tax: a percentage of
 * turnover paid by the seller, with no input credit and nothing added to an
 * invoice. It is therefore a regime of its own here, the document engine stays
 * off under it, and the VAT return works it out for the period.
 *
 * ⛔ No percentage is written in code. The standard VAT rate and the turnover
 * tax rate are both rows the accountant enters, effective-dated.
 */
enum VatRegime: string
{
    case NotRegistered = 'not_registered';
    case TurnoverTax = 'turnover_tax';
    case VatStandard = 'vat_standard';

    public function label(): string
    {
        return match ($this) {
            self::NotRegistered => 'Not registered',
            self::TurnoverTax => 'Turnover tax enlisted',
            self::VatStandard => 'VAT registered',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::NotRegistered => 'No VAT on anything. Invoices and bills are exactly as they are today.',
            self::TurnoverTax => 'A percentage of sales is paid as turnover tax. Nothing is added to an invoice and no input VAT is claimed; the VAT return works out the amount for each period.',
            self::VatStandard => 'VAT is charged on every invoice and claimed on every bill dated from the start date. Registers and the VAT return are produced from those documents.',
        };
    }
}
