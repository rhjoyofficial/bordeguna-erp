<?php

use App\Modules\Accounting\Support\Money;

/**
 * ⚠⚠ Five decimal incidents on this project already: three of stringification,
 * one of float arithmetic inside FIFO, and one avoided only because a value was
 * stored rather than recomputed. This class is the sixth answer, and the ledger
 * is where it matters most, because a journal's correctness is an EXACT
 * equality rather than a tolerance.
 */
it('parses taka without ever going through a float', function () {
    // ⚠⚠ THE CASE THAT JUSTIFIES THE WHOLE CLASS. In PHP,
    // (int) ((float) '0.29' * 100) is 28. Not a rounding error anybody would
    // predict by reading the code, and it would be two paisa off in a ledger
    // that has to balance to zero.
    expect(Money::ofTaka('0.29')->minor)->toBe(29)
        ->and(Money::ofTaka('1.15')->minor)->toBe(115)
        ->and(Money::ofTaka('0.07')->minor)->toBe(7)
        ->and(Money::ofTaka('24000')->minor)->toBe(2400000)
        ->and(Money::ofTaka('239003.00')->minor)->toBe(23900300);
});

it('accepts what a human actually types', function () {
    expect(Money::ofTaka(' 1,234.50 ')->minor)->toBe(123450)
        ->and(Money::ofTaka('+90')->minor)->toBe(9000)
        ->and(Money::ofTaka('-90.25')->minor)->toBe(-9025);

    expect(fn () => Money::ofTaka('abc'))->toThrow(InvalidArgumentException::class);
    expect(fn () => Money::ofTaka(''))->toThrow(InvalidArgumentException::class);
    expect(fn () => Money::ofTaka('1.2.3'))->toThrow(InvalidArgumentException::class);
});

it('rounds anything finer than a paisa away from zero', function () {
    // ⚠ Inventory carries costs to FOUR decimal places, so a posting derived
    // from stock can genuinely arrive with a fraction of a paisa. Rounding here
    // is a decision, and in Phase 3 the residual becomes an explicit line on
    // Rounding Differences rather than disappearing into the last line computed.
    expect(Money::ofTaka('3333.3333')->minor)->toBe(333333)
        ->and(Money::ofTaka('3333.3350')->minor)->toBe(333334)
        ->and(Money::ofTaka('0.005')->minor)->toBe(1)
        ->and(Money::ofTaka('-0.005')->minor)->toBe(-1)
        ->and(Money::ofTaka('0.004')->minor)->toBe(0);
});

it('adds without drift, which is the entire point', function () {
    // The FIFO case that produced 2.78e-17 in floats: 0.1 + 0.2 - 0.3.
    $total = Money::sum([
        Money::ofTaka('0.10'),
        Money::ofTaka('0.20'),
        Money::ofTaka('-0.30'),
    ]);

    expect($total->isZero())->toBeTrue()
        ->and($total->minor)->toBe(0);
});

it('knows which side of the ledger it is on', function () {
    expect(Money::ofTaka('100')->isDebit())->toBeTrue()
        ->and(Money::ofTaka('100')->isCredit())->toBeFalse()
        ->and(Money::ofTaka('-100')->isCredit())->toBeTrue()
        ->and(Money::zero()->isDebit())->toBeFalse()
        ->and(Money::zero()->isCredit())->toBeFalse();
});

it('prints money the way a report has to', function () {
    // ⚠ `19200.00000000` has reached a real user on this project twice, in two
    // different modules - the second time because fixing an instance was
    // mistaken for fixing a pattern.
    expect(Money::ofTaka('19200')->format())->toBe('19,200.00')
        ->and(Money::ofTaka('239003.5')->format())->toBe('239,003.50')
        ->and(Money::ofTaka('-1500.05')->format())->toBe('-1,500.05')
        ->and(Money::zero()->format())->toBe('0.00');

    expect(Money::ofTaka('19200')->toTaka())->toBe('19200.00')
        ->and(Money::ofTaka('-0.05')->toTaka())->toBe('-0.05');
});

it('cannot be changed after it has been checked', function () {
    $original = Money::ofTaka('100');

    $original->plus(Money::ofTaka('50'));
    $original->negated();

    // Every operation returns a new instance, so an amount cannot be altered
    // after the balance check that approved it.
    expect($original->minor)->toBe(10000);
});
