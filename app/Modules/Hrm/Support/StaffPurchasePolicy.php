<?php

namespace App\Modules\Hrm\Support;

/**
 * The Staff Product Benefit as policy constants (office order
 * BDG/HR/09/01/2026, effective 1 Sep 2026; ChatGPT ruling 17 Oct 2026 KEEPS
 * the limits exactly; decisions D1-D8 recorded as code, not assumptions).
 *
 * ⚠ Nothing here is a hidden assumption: Joy may change any line later, and
 * the test file names each rule.
 */
final class StaffPurchasePolicy
{
    /** Rule 2: Tk 3,000 per 24-23 payroll cycle (27 Oct 2026), measured at the cap reference price. */
    public const MONTHLY_CAP_AT_REFERENCE = 3000.0;

    /** Rule 3: one unit per SKU per 24-23 payroll cycle (27 Oct 2026) at the staff price. */
    public const UNITS_PER_SKU_PER_MONTH_AT_STAFF_PRICE = 1;

    /** Q1: the staff price is the cost of the FIFO batch reserved at approval. */
    public const PRICE_BASIS = 'fifo_reserved';

    /** D4: a made product's cost is its production output batch cost (no labour, no overhead). */
    public const MADE_GOODS_COST = 'production_output_batch_cost';

    /** D7: a proven defect is replaced at zero charge; the defective unit is written off. */
    public const DEFECT_REPLACEMENT = 'linked_zero_charge_replacement';

    /** D8: reference price, actual price and the difference are kept per person per month; no tax logic. */
    public const TAX_EVIDENCE = 'reference_actual_difference_no_tax_calculation';

    /** D3: who may read the cost basis behind a staff price. */
    public const COST_BASIS_PERMISSION = 'hrm.staff_purchase.cost_basis.view';

    /** The reservation reason written on every batch slice held for a request. */
    public const RESERVATION_REASON = 'staff_purchase';

    /** The office order's words, used wherever a return is refused. */
    public const NO_RETURNS = 'No returns through the staff benefit (office order BDG/HR/09/01/2026): a proven defect is replaced, at no charge, from HRM > Staff purchases.';
}
