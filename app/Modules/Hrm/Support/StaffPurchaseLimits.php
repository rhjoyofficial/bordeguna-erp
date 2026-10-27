<?php

namespace App\Modules\Hrm\Support;

use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\StaffPurchaseLine;
use App\Modules\Hrm\Models\StaffPurchaseRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The office order's limits, checked at REQUEST, APPROVAL and HANDOVER
 * (ChatGPT ruling Q3, Phase 2, 17 Oct 2026):
 *
 *  - only active staff employed in this business;
 *  - one unit per SKU per month at the staff price; a further unit
 *    is priced at the cap reference price (MRP or market price) automatically,
 *    or refused when the product has none;
 *  - Tk 3,000 per month measured at the cap reference price frozen
 *    on each line; approved-but-not-handed-over requests count, cancelled and
 *    rejected never do; nothing carries forward.
 *
 * ⭐ 27 Oct 2026 (Joy): "for employees everything not 1-31 always 24-23". The
 * "month" above is the 24-23 payroll cycle the request was made in
 * (`window()`, from `PayrollCycle::containing`), and the month counter row is
 * keyed by that cycle's label (a request on 27 Oct locks 2026/11). It was the
 * calendar month before; nothing is stored per month except the lock row, so
 * the switch needs no data change.
 *
 * ⚠⚠ CONCURRENCY. Two requests for the last Tk 500 of an allowance must not
 * both pass. Every check runs inside a transaction AFTER `lockMonth()` took a
 * row lock on the employee's month counter (`staff_purchase_month_counters`),
 * so the second request waits for the first and then reads its lines. The
 * counter row carries no figures: what is used is always summed from the
 * lines, so nothing can drift.
 */
final class StaffPurchaseLimits
{
    /**
     * The payroll cycle a date falls in, as the [start, end] of the created_at
     * window: from the first moment of the 24th to the last moment of the 23rd.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function window(Carbon $date): array
    {
        $cycle = PayrollCycle::containing($date);

        return [$cycle['start']->copy()->startOfDay(), $cycle['end']->copy()->endOfDay()];
    }

    /** Serialise this employee's cycle. Inside a transaction, before any check. */
    public static function lockMonth(Employee $employee, Carbon $month): void
    {
        $cycle = PayrollCycle::containing($month);
        $key = ['employee_id' => $employee->id, 'year' => $cycle['year'], 'month' => $cycle['month']];

        if (! DB::table('staff_purchase_month_counters')->where($key)->exists()) {
            DB::table('staff_purchase_month_counters')->insertOrIgnore($key + [
                'business_id' => $employee->business_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('staff_purchase_month_counters')->where($key)->lockForUpdate()->get();
    }

    /** @throws ValidationException */
    public static function assertEligible(Employee $employee, int $businessId): void
    {
        if ($employee->status !== EmployeeStatus::Active) {
            throw ValidationException::withMessages([
                'lines' => 'Only active staff may use the staff benefit (office order BDG/HR/09/01/2026).',
            ]);
        }

        // HRM-005 (14 Oct 2026): a benefit of employment, so only where the
        // person is employed.
        if (! $employee->isEmployedIn($businessId)) {
            throw ValidationException::withMessages(['lines' => Employee::notEmployedHere()]);
        }
    }

    /**
     * Reference price already used this month by requests still standing,
     * leaving out one request (the one being checked).
     */
    public static function referenceUsed(int $employeeId, Carbon $month, ?int $exceptRequestId = null): float
    {
        return (float) StaffPurchaseLine::query()
            ->join('staff_purchase_requests', 'staff_purchase_requests.id', '=', 'staff_purchase_lines.request_id')
            ->where('staff_purchase_requests.employee_id', $employeeId)
            ->whereIn('staff_purchase_requests.status', StaffPurchaseRequest::COUNTING)
            ->when($exceptRequestId !== null, fn ($q) => $q->where('staff_purchase_requests.id', '<>', $exceptRequestId))
            ->whereBetween('staff_purchase_requests.created_at', self::window($month))
            // ⭐ Answer 6: a custom-priced not-for-sale line is not the benefit.
            ->where('staff_purchase_lines.priced_at', '!=', StaffPurchaseLine::PRICED_AT_CUSTOM)
            ->sum(DB::raw('staff_purchase_lines.cap_reference_price * staff_purchase_lines.quantity'));
    }

    /** Units of this SKU already at the STAFF price this month, by standing requests. */
    public static function unitsAtStaffPrice(int $employeeId, int $productId, Carbon $month, ?int $exceptRequestId = null): float
    {
        return (float) StaffPurchaseLine::query()
            ->join('staff_purchase_requests', 'staff_purchase_requests.id', '=', 'staff_purchase_lines.request_id')
            ->where('staff_purchase_requests.employee_id', $employeeId)
            ->where('staff_purchase_lines.product_id', $productId)
            ->where('staff_purchase_lines.priced_at', StaffPurchaseLine::PRICED_AT_STAFF)
            ->whereIn('staff_purchase_requests.status', StaffPurchaseRequest::COUNTING)
            ->when($exceptRequestId !== null, fn ($q) => $q->where('staff_purchase_requests.id', '<>', $exceptRequestId))
            ->whereBetween('staff_purchase_requests.created_at', self::window($month))
            ->sum('staff_purchase_lines.quantity');
    }

    /**
     * The whole set of checks for a request's lines, at any of the three
     * stages. `$lines` are arrays {product_id, product_name, quantity,
     * cap_reference_price|null, priced_at} so the request stage can check
     * before any row exists and the later stages check the saved rows.
     *
     * @param  array<int, array{product_id: int, product_name: string, quantity: string, cap_reference_price: string|null, priced_at: string}>  $lines
     *
     * @throws ValidationException
     */
    public static function assert(Employee $employee, int $businessId, Carbon $month, array $lines, ?int $exceptRequestId, string $stage): void
    {
        self::assertEligible($employee, $businessId);

        $referenceTotal = 0.0;

        foreach ($lines as $line) {
            // ⭐ Answer 6 (25 Oct 2026): the Owner's or a Manager's custom price
            // for a not-for-sale item is outside the allowance and the one-unit rule.
            if ($line['priced_at'] === StaffPurchaseLine::PRICED_AT_CUSTOM) {
                continue;
            }

            if ($line['cap_reference_price'] === null) {
                throw ValidationException::withMessages([
                    'lines' => "{$line['product_name']} has no MRP and no selling price in force, so the allowance cannot be measured for it.",
                ]);
            }

            if ($line['priced_at'] === StaffPurchaseLine::PRICED_AT_STAFF) {
                $already = self::unitsAtStaffPrice($employee->id, $line['product_id'], $month, $exceptRequestId);

                if ($already + (float) $line['quantity'] > StaffPurchasePolicy::UNITS_PER_SKU_PER_MONTH_AT_STAFF_PRICE) {
                    throw ValidationException::withMessages([
                        'lines' => "{$line['product_name']} was already taken this month at the staff price (one unit per SKU a month); at {$stage} this line can only be priced at its reference price.",
                    ]);
                }
            }

            $referenceTotal += (float) $line['cap_reference_price'] * (float) $line['quantity'];
        }

        $used = self::referenceUsed($employee->id, $month, $exceptRequestId);

        if ($used + $referenceTotal > StaffPurchasePolicy::MONTHLY_CAP_AT_REFERENCE + 0.00001) {
            throw ValidationException::withMessages([
                'lines' => sprintf(
                    'This would use %s of the %s monthly allowance (measured at the reference price); %s is already used%s. The allowance does not roll over.',
                    number_format($referenceTotal, 2),
                    number_format(StaffPurchasePolicy::MONTHLY_CAP_AT_REFERENCE, 2),
                    number_format($used, 2),
                    $stage === 'request' ? '' : " (checked again at {$stage})",
                ),
            ]);
        }
    }
}
