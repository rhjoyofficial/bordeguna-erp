<?php

use App\Modules\Hrm\Support\LeaveEntitlement;

/**
 * LEV-02, as revised by Joy on 2026-07-29.
 *
 * The approved rule was "a full grant ... not prorated by joining date". It now
 * pro-rates mid-year joiners, every year, so that somebody joining in 2029 is
 * treated exactly as the go-live migration treated a 2026 joiner.
 *
 * ⚠ The rule lives in ONE class because the opening-balance migration and the
 * annual grant both need it. Two copies would eventually disagree about the
 * same person — silently, and in cash, since Earned/Annual is encashable.
 */
it('grants the full quota to someone employed all year', function () {
    expect(LeaveEntitlement::daysFor(20.0, '2019-05-01', 2026))->toBe(20.0);
    expect(LeaveEntitlement::daysFor(20.0, '2026-01-01', 2026))->toBe(20.0);
});

it('pro-rates someone who joined during the leave year', function () {
    // 2026-07-01 → 184 of 365 days. 20 × 184/365 = 10.08, floored to 10.0.
    expect(LeaveEntitlement::daysFor(20.0, '2026-07-01', 2026))->toBe(10.0);
    expect(LeaveEntitlement::daysFor(10.0, '2026-07-01', 2026))->toBe(5.0);
});

it('rounds DOWN to the nearest half day, never up', function () {
    // ⚠ The direction is deliberate. Earned/Annual is encashable, so an
    // over-granted day is cash the company owes on separation. 20 × 176/365 =
    // 9.64, which must become 9.5 and never 10.
    expect(LeaveEntitlement::daysFor(20.0, '2026-07-09', 2026))->toBe(9.5);
    // 14 × 164/365 = 6.29 → 6.0, not 6.5.
    expect(LeaveEntitlement::daysFor(14.0, '2026-07-21', 2026))->toBe(6.0);
});

it('treats an unknown joining date as employed all year, not as zero', function () {
    // ⚠ Withholding leave because a date is missing from a record punishes the
    // employee for an administrative gap.
    expect(LeaveEntitlement::daysFor(20.0, null, 2026))->toBe(20.0);
    expect(LeaveEntitlement::isProrated(null, 2026))->toBeFalse();
});

it('grants nothing for a year the employee had not yet joined', function () {
    expect(LeaveEntitlement::daysFor(20.0, '2027-03-01', 2026))->toBe(0.0);
});

it('never grants more than the full quota', function () {
    foreach (['2019-01-01', '2026-01-01', '2026-06-15', '2026-12-31'] as $joined) {
        expect(LeaveEntitlement::daysFor(20.0, $joined, 2026))->toBeLessThanOrEqual(20.0);
    }
});

it('reports whether an entitlement was pro-rated, for the ledger note', function () {
    expect(LeaveEntitlement::isProrated('2026-07-01', 2026))->toBeTrue();
    expect(LeaveEntitlement::isProrated('2025-07-01', 2026))->toBeFalse();
    expect(LeaveEntitlement::employedDays('2026-07-01', 2026))->toBe(184);
    expect(LeaveEntitlement::employedDays('2019-05-01', 2026))->toBe(365);
});
