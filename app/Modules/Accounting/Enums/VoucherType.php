<?php

namespace App\Modules\Accounting\Enums;

/**
 * The four voucher types an accountant names. A type decides the SHAPE of data
 * entry and nothing else: every one of them posts the same journal.
 */
enum VoucherType: string
{
    case Payment = 'payment';
    case Receipt = 'receipt';
    case Contra = 'contra';
    case Journal = 'journal';

    public function label(): string
    {
        return match ($this) {
            self::Payment => 'Payment voucher',
            self::Receipt => 'Receive voucher',
            self::Contra => 'Contra voucher',
            self::Journal => 'Journal voucher',
        };
    }

    public function plural(): string
    {
        return $this->label().'s';
    }

    /** PV-2026-27-0001. A journal voucher takes its journal's own JV number instead. */
    public function prefix(): string
    {
        return match ($this) {
            self::Payment => 'PV',
            self::Receipt => 'RV',
            self::Contra => 'CV',
            self::Journal => 'JV',
        };
    }

    /** The side every line sits on, or null where each line chooses (journal). */
    public function fixedLineSide(): ?string
    {
        return match ($this) {
            self::Payment => 'debit',
            self::Receipt => 'credit',
            default => null,
        };
    }

    public function usesMoneyAccount(): bool
    {
        return $this !== self::Journal;
    }

    public function hasLines(): bool
    {
        return $this !== self::Contra;
    }

    /** What the counterparty field is called on this type's form and print. */
    public function counterpartyLabel(): ?string
    {
        return match ($this) {
            self::Payment => 'Paid to',
            self::Receipt => 'Received from',
            default => null,
        };
    }
}
