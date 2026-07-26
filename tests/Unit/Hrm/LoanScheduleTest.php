<?php

use App\Modules\Hrm\Support\LoanSchedule;

/**
 * ADJ-07's principal, installment and start month turned into a plan — tested as
 * a pure rule, no schema.
 *
 * The property that matters most is the last one asserted here: **a schedule
 * collects the principal exactly.** Over-collecting by a few paisa is the kind
 * of defect nobody notices in a test and every borrower notices on a payslip.
 */
it('splits a principal into equal installments', function () {
    $rows = LoanSchedule::build(60000, 5000, 2026, 8);

    expect($rows)->toHaveCount(12);
    expect($rows[0])->toMatchArray(['sequence' => 1, 'year' => 2026, 'month' => 8, 'amount' => 5000.0]);
    expect($rows[11])->toMatchArray(['sequence' => 12, 'year' => 2027, 'month' => 7, 'amount' => 5000.0]);
});

it('rolls the schedule across a year boundary', function () {
    $rows = LoanSchedule::build(15000, 5000, 2026, 11);

    expect(array_map(fn ($r) => "{$r['year']}-{$r['month']}", $rows))
        ->toBe(['2026-11', '2026-12', '2027-1']);
});

it('gives the last installment the remainder rather than over-collecting', function () {
    // 50,000 at 4,166.67 would collect 50,000.04 across twelve equal payments.
    $rows = LoanSchedule::build(50000, 4166.67, 2026, 1);

    expect($rows)->toHaveCount(12);
    expect(end($rows)['amount'])->toBe(4166.63);
});

it('collects the principal exactly, whatever the terms', function () {
    foreach ([[60000, 5000], [50000, 4166.67], [10000, 3000], [999.99, 100], [1234.56, 999.99]] as [$principal, $installment]) {
        $total = round(array_sum(array_column(LoanSchedule::build($principal, $installment, 2026, 1), 'amount')), 2);

        expect($total)->toBe(round((float) $principal, 2));
    }
});

it('handles a single-payment loan', function () {
    $rows = LoanSchedule::build(5000, 5000, 2026, 9);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['amount'])->toBe(5000.0);
});

it('returns nothing for terms that cannot produce a schedule', function () {
    expect(LoanSchedule::build(0, 5000, 2026, 1))->toBe([]);
    expect(LoanSchedule::build(5000, 0, 2026, 1))->toBe([]);
    expect(LoanSchedule::build(-100, 5000, 2026, 1))->toBe([]);
});

it('counts installments without building the schedule', function () {
    expect(LoanSchedule::count(60000, 5000))->toBe(12);
    expect(LoanSchedule::count(50000, 4166.67))->toBe(12);
    expect(LoanSchedule::count(10000, 3000))->toBe(4);
    expect(LoanSchedule::count(0, 3000))->toBe(0);
});
