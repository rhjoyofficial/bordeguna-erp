<?php

use App\Support\DocumentNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Audit #7 and SAL-001, 14 Oct 2026: the shared document numbering.
 *
 * `next()` is numeric past the padding; `claim()` turns a number collision
 * (two saves in the same second) into the next free number instead of an
 * error page, and leaves every other unique refusal alone.
 */
function quoteRow(string $number): array
{
    return [
        'business_id' => 1, 'quotation_number' => $number, 'valid_until' => '2026-12-31',
        'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
    ];
}

it('finds the numerically largest number, not the alphabetically largest', function () {
    DB::table('quotations')->insert([quoteRow('QT-2026-27-9999'), quoteRow('QT-2026-27-10000'), quoteRow('QT-2025-26-12345')]);

    expect(DocumentNumber::next(DB::table('quotations'), 'quotation_number', 'QT-2026-27-'))->toBe('QT-2026-27-10001')
        ->and(DocumentNumber::next(DB::table('quotations'), 'quotation_number', 'QT-2027-28-'))->toBe('QT-2027-28-0001');
});

it('retries a collided number with the next free one, inside a transaction', function () {
    DB::table('quotations')->insert([quoteRow('QT-X-0005'), quoteRow('QT-X-0006')]);

    // The read says 0005 every time, as a stale MySQL snapshot would: the claim
    // still moves on by bumping past what collided.
    $id = DB::transaction(fn () => DocumentNumber::claim(
        'quotation_number',
        fn () => 'QT-X-0005',
        fn (string $number) => DB::table('quotations')->insertGetId(quoteRow($number)),
    ));

    expect(DB::table('quotations')->where('id', $id)->value('quotation_number'))->toBe('QT-X-0007')
        ->and(DB::table('quotations')->count())->toBe(3);
});

it('gives up after three collisions rather than looping', function () {
    DB::table('quotations')->insert([quoteRow('QT-Y-0001'), quoteRow('QT-Y-0002'), quoteRow('QT-Y-0003')]);

    DocumentNumber::claim('quotation_number', fn () => 'QT-Y-0001', fn (string $number) => DB::table('quotations')->insert(quoteRow($number)));
})->throws(UniqueConstraintViolationException::class);

it('bumps keeping the width, and grows past it', function () {
    expect(DocumentNumber::bump('INV-2026-27-0042'))->toBe('INV-2026-27-0043')
        ->and(DocumentNumber::bump('INV-2026-27-9999'))->toBe('INV-2026-27-10000')
        ->and(DocumentNumber::bump('PO-000009'))->toBe('PO-000010');
});
