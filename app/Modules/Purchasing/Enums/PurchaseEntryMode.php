<?php

namespace App\Modules\Purchasing\Enums;

/**
 * Which figure on a purchase line the person TYPED, and which the system
 * worked out from it (Phase 1 part A, 17 Oct 2026, ChatGPT ruling Q1).
 *
 * ⚠ The typed figure is the truth. A line entered as "3,000 units for
 * 100,000" shows a rate of 33.333333, and 3,000 x 33.333333 is 99,999.999:
 * the total is never rebuilt from the displayed rate.
 */
enum PurchaseEntryMode: string
{
    /** The unit price was typed; the line total is quantity x price, rounded once to the paisa. */
    case Rate = 'rate';

    /** The line total was typed and kept exactly; the unit price is a six-place derivation for display and costing. */
    case Total = 'total';
}
