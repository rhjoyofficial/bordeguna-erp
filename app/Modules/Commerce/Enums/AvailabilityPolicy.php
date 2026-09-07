<?php

namespace App\Modules\Commerce\Enums;

/**
 * How a channel answers "can this be sold right now?" against one stock number.
 *
 * ADR-004. There is ONE stock number in the ERP. Channels differ only in the
 * policy applied to it, because two stock numbers would eventually disagree and
 * the disagreement would reach a customer before it reached us.
 *
 * ⛔ `StatusBased` does NOT mean stock is ignored. It means a sale at zero
 * records a COMMITMENT or a backorder and never a stock movement, because a
 * movement for goods that do not exist is a lie in the ledger that
 * `inventory:verify-ledger` would eventually catch.
 */
enum AvailabilityPolicy: string
{
    /**
     * The till. The customer is standing there, so what is not on the shelf
     * cannot be sold.
     */
    case Strict = 'strict';

    /**
     * The web. The goods are made to order, so an active product keeps selling
     * at zero and the order carries a promised lead time.
     */
    case StatusBased = 'status_based';

    public function label(): string
    {
        return match ($this) {
            self::Strict => 'Strict (cannot sell at zero)',
            self::StatusBased => 'Status based (made to order, keeps selling)',
        };
    }

    /**
     * May a sale be taken when there is no stock to move?
     */
    public function allowsSaleAtZero(): bool
    {
        return $this === self::StatusBased;
    }
}
