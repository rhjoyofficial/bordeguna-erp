<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The next document number ("INV-2026-27-0042"), and a safe way to take it.
 *
 * ⚠⚠ TWO DEFECTS, ONE PLACE (14 Oct 2026, audit #7 and SAL-001).
 *
 *  1. TEXT ORDER. Every generator found "the latest" by sorting the number as
 *     a STRING, and "...-9999" sorts above "...-10000". So once the 10,000th
 *     document of a year was issued, the next save computed 10000 again, hit
 *     the unique index, and so did every save after it until the fiscal year
 *     changed: the counter, courier deliveries and returns would all stop.
 *     `next()` orders by LENGTH first, then by value, which is numeric order
 *     for numbers that share a prefix, on MySQL and SQLite alike.
 *
 *  2. NO LOCK. "Last number + 1" read by two saves in the same second gives
 *     both the same number; the unique index refused the second and the
 *     person saw an error page. `claim()` takes the number inside a SAVEPOINT
 *     and, when the unique index refuses it, tries again with the next free
 *     number (up to three times) instead of failing. Nothing half-written
 *     survives a refused attempt, because the savepoint rolls it back.
 *
 * ⚠ Why the retry BUMPS rather than only re-reading: on MySQL the competing
 * row may be invisible to this transaction's snapshot (REPEATABLE READ), so a
 * re-read can return the very number that just collided. The next attempt is
 * whichever is higher, the fresh read or the collided number plus one.
 */
final class DocumentNumber
{
    public const ATTEMPTS = 3;

    /**
     * `$prefix` followed by one more than the numerically largest number that
     * `$query` holds with that prefix, zero-padded to `$pad` digits (a number
     * past the padding simply grows: 9999, 10000, 10001).
     *
     * @param  string  $column  a trusted column name, never user input
     */
    public static function next(Builder $query, string $column, string $prefix, int $pad = 4): string
    {
        $latest = $query
            ->where($column, 'like', $prefix.'%')
            ->orderByRaw("LENGTH({$column}) DESC")
            ->orderByDesc($column)
            ->value($column);

        $next = $latest === null ? 1 : ((int) substr((string) $latest, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
    }

    /**
     * Creates the document with a number, retrying on a number collision.
     *
     * @template T
     *
     * @param  string  $column  the number column, to tell a number collision from any other unique refusal
     * @param  callable(): string  $nextNumber
     * @param  callable(string): T  $create  writes the row carrying the number it is given
     * @return T
     */
    public static function claim(string $column, callable $nextNumber, callable $create, int $attempts = self::ATTEMPTS): mixed
    {
        $number = $nextNumber();

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn () => $create($number));
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= $attempts || ! self::isAbout($e, $column, $number)) {
                    throw $e;
                }

                $fresh = $nextNumber();
                $bumped = self::bump($number);
                $number = self::sequenceOf($fresh) > self::sequenceOf($bumped) ? $fresh : $bumped;
            }
        }
    }

    /** "INV-2026-27-0042" -> "INV-2026-27-0043", keeping the width. */
    public static function bump(string $number): string
    {
        return (string) preg_replace_callback('/(\d+)$/', function (array $match) {
            $digits = $match[1];

            return str_pad((string) (((int) $digits) + 1), strlen($digits), '0', STR_PAD_LEFT);
        }, $number);
    }

    private static function sequenceOf(string $number): int
    {
        return preg_match('/(\d+)$/', $number, $match) === 1 ? (int) $match[1] : 0;
    }

    /**
     * SQLite names the column ("UNIQUE constraint failed: sales_invoices.invoice_number");
     * MySQL names the value and the index ("Duplicate entry 'INV-...' for key
     * 'sales_invoices_invoice_number_unique'"). Either way, a refusal about
     * some OTHER unique column is not ours to retry.
     */
    private static function isAbout(UniqueConstraintViolationException $e, string $column, string $number): bool
    {
        return str_contains($e->getMessage(), $column) || str_contains($e->getMessage(), $number);
    }
}
