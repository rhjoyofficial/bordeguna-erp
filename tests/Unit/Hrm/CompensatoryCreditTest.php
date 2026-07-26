<?php

use App\Modules\Hrm\Payroll\PayrollConfig;
use App\Modules\Hrm\Support\CompensatoryCredit;

/**
 * ADJ-04 via Correction C8b, tested as a pure rule — no database, no schema.
 *
 * The point of pinning these numbers is that the ratio is the *whole* argument.
 * ADJ-04 declined 1.5× and 2× and chose compensatory leave, so a day worked can
 * never buy more than a day off. If a future change makes 8 hours worth 1.5 days
 * these tests fail, which is exactly what should happen.
 */
$config = fn (float $standard = 8, int $minimum = 30) => PayrollConfig::defaults(
    standardWorkingHours: $standard,
    overtimeMinimumMinutes: $minimum,
);

it('credits a full day for a full day worked', function () use ($config) {
    expect(CompensatoryCredit::daysFor(8, $config()))->toBe(1.0);
});

it('never credits more than a day, however long the stint', function () use ($config) {
    // ADJ-04 left every pay multiple unticked. More than 1:1 would be a
    // multiplier by another name.
    expect(CompensatoryCredit::daysFor(12, $config()))->toBe(1.0);
    expect(CompensatoryCredit::daysFor(24, $config()))->toBe(1.0);
});

it('credits half a day for a part day', function () use ($config) {
    expect(CompensatoryCredit::daysFor(4, $config()))->toBe(0.5);
    expect(CompensatoryCredit::daysFor(2, $config()))->toBe(0.5);
});

it('credits nothing below the ADJ-03 minimum', function () use ($config) {
    // 20 minutes.
    expect(CompensatoryCredit::daysFor(0.33, $config()))->toBe(0.0);
    expect(CompensatoryCredit::daysFor(0, $config()))->toBe(0.0);
});

it('rounds to half days so the balance can actually be spent', function () use ($config) {
    // Leave is taken in half and full days, so 0.19 of a day is unusable.
    foreach ([1, 2, 3, 5, 6, 7, 8] as $hours) {
        $days = CompensatoryCredit::daysFor($hours, $config());
        expect(fmod($days * 2, 1.0))->toBe(0.0);
    }
});

it('follows the configured standard day rather than assuming eight hours', function () use ($config) {
    // A six-hour standard day makes six hours a full day's credit.
    expect(CompensatoryCredit::daysFor(6, $config(6)))->toBe(1.0);
    expect(CompensatoryCredit::daysFor(3, $config(6)))->toBe(0.5);
});

it('explains itself, so a balance is never just a number', function () use ($config) {
    $note = CompensatoryCredit::explain(8, 1.0, $config());

    expect($note)->toContain('8 hours')->toContain('ADJ-04');
});
