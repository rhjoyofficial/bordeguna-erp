<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use Illuminate\Support\Collection;

/**
 * The next free account code under a heading (Joy, 12 Oct 2026: "each time we
 * need to add the code while creating the head, it's very painful").
 *
 * The standard way, as in every accounting package: choose WHERE the account
 * sits and the chart numbers it from its parent. The code is still the
 * person's to change; this only proposes it. One source for the chart screen,
 * the picker's "New ledger head" dialog and a store that arrives without a code.
 *
 * THE RULE, read on the codes that are plain numbers (a suffixed code such as
 * 2110-5 is skipped for the pattern, but still never reused):
 *
 *  1. The parent's range runs from its code up to the first code AFTER it that
 *     is not inside its own branch (4000 -> up to 5000, 6050 -> up to 6090,
 *     1120 -> up to 1130), and never past its first digit's thousand.
 *  2. The children's length is the length most of them have (1120's child
 *     11201 is five digits). No children yet: the parent's own length when it
 *     ends in 0 (4900 -> 4910), one digit longer when it does not (1145 -> 11451).
 *  3. The step is the round unit the children are numbered in (4100, 4200,
 *     4300 step by 100; 6011, 6012 by 1), widened to the usual gap between
 *     siblings when that is a multiple of it (6010, 6050, 6090 are 40 apart).
 *  4. The proposal is the first slot after the highest child, one step on,
 *     whose whole step-wide band is unused anywhere in the chart and which
 *     still fits the range. A slot of one never ends in 0 (in this chart a
 *     code ending in 0 reads as a heading: 6249 is followed by 6251).
 *  5. No room above the highest child: the first free gap from the bottom of
 *     the range, at the same unit and then finer ones.
 *  6. Top level: the next free thousand in the type's own digits (asset 1,
 *     liability 2, equity 3, income 4, expense 5 to 9), then a free hundred.
 *
 * Real chart: under 4000 -> 4400 (4900 Other Income fills the top, so the gap
 * after 4300 is used); under 5000 -> 5500; under 1120 -> 11202.
 */
final class AccountCodes
{
    /** Root digits per type: the first digit of a top-level code. */
    private const ROOT_DIGITS = [
        'asset' => [1],
        'liability' => [2],
        'equity' => [3],
        'income' => [4],
        'expense' => [5, 6, 7, 8, 9],
    ];

    /** @var Collection<int, object{id: int, code: string, parent_id: int|null, type: string}> */
    private Collection $rows;

    /** Width every numeric code is compared at (right-padded with zeros). */
    private int $width;

    /** @var array<string, true> */
    private array $takenCodes;

    /** @var list<int> numeric codes at, sorted */
    private array $padded;

    public static function nextUnder(?Account $parent, AccountType $type): ?string
    {
        return (new self)->suggest($parent, $type);
    }

    private function __construct()
    {
        $this->rows = Account::query()->get(['id', 'code', 'parent_id', 'type'])
            ->map(fn (Account $a) => (object) [
                'id' => $a->id,
                'code' => (string) $a->code,
                'parent_id' => $a->parent_id,
                'type' => $a->type instanceof AccountType ? $a->type->value : (string) $a->type,
            ]);

        $this->takenCodes = $this->rows->pluck('code')->mapWithKeys(fn (string $c) => [$c => true])->all();
        $numeric = $this->rows->pluck('code')->filter(fn (string $c) => ctype_digit($c));
        $this->width = max(5, (int) $numeric->map(fn (string $c) => strlen($c))->max());
        $this->padded = $numeric->map(fn (string $c) => $this->pad($c))->sort()->values()->all();
    }

    private function suggest(?Account $parent, AccountType $type): ?string
    {
        if ($parent === null) {
            return $this->suggestTopLevel($type);
        }

        $parentCode = (string) $parent->code;

        if (! ctype_digit($parentCode)) {
            return null;
        }

        $children = $this->rows->where('parent_id', $parent->id)->pluck('code')->filter(fn (string $c) => ctype_digit($c));
        $length = $this->childLength($parentCode, $children);
        $children = $children->filter(fn (string $c) => strlen($c) === $length)->map(fn (string $c) => (int) $c)->sort()->values();

        // The parent itself expressed at the children's length: 1120 -> 11200.
        $low = (int) str_pad($parentCode, $length, '0');
        $high = $this->rangeEnd($parent, $length);

        $unit = $this->unit($low, $children, $parentCode, $length);
        $step = $this->step($unit, $children);

        if ($children->isNotEmpty()) {
            for ($slot = $children->last() + $step; $slot + $step <= $high; $slot += $step) {
                if ($this->usable($slot, $step, $length)) {
                    return $this->format($slot, $length);
                }
            }
        }

        return $this->firstGap($low, $high, $unit, $length);
    }

    private function suggestTopLevel(AccountType $type): ?string
    {
        $digits = self::ROOT_DIGITS[$type->value] ?? [];
        $rootLengths = $this->rows->whereNull('parent_id')->pluck('code')->filter(fn (string $c) => ctype_digit($c))
            ->map(fn (string $c) => strlen($c))->countBy()->sortDesc();
        $length = (int) ($rootLengths->keys()->first() ?? 4);
        $thousand = 10 ** ($length - 1);

        // A whole free thousand first, then a free hundred inside the type's digits.

        foreach ([$thousand, intdiv($thousand, 10)] as $unit) {
            foreach ($digits as $digit) {
                $low = $digit * $thousand;

                for ($slot = $low; $slot + $unit <= $low + $thousand; $slot += $unit) {
                    if ($this->usable($slot, $unit, $length)) {
                        return $this->format($slot, $length);
                    }
                }
            }
        }

        return null;
    }

    /**
     * The first slot from the bottom of the range whose band is unused, at the
     * given unit and then at each finer one.
     */
    private function firstGap(int $low, int $high, int $unit, int $length): ?string
    {
        for ($u = $unit; $u >= 1; $u = intdiv($u, 10)) {
            for ($slot = $low + $u; $slot + $u <= $high; $slot += $u) {
                if ($this->usable($slot, $u, $length)) {
                    return $this->format($slot, $length);
                }
            }
        }

        return null;
    }

    /** A slot is usable when nothing in the chart sits in its band and, at unit one, it does not end in 0. */
    private function usable(int $slot, int $step, int $length): bool
    {
        if ($step === 1 && $slot % 10 === 0) {
            return false;
        }

        $code = $this->format($slot, $length);

        if (isset($this->takenCodes[$code])) {
            return false;
        }

        $scale = 10 ** ($this->width - $length);
        $from = $slot * $scale;
        $to = ($slot + $step) * $scale;

        foreach ($this->padded as $taken) {
            if ($taken >= $to) {
                break;
            }

            if ($taken >= $from) {
                return false;
            }
        }

        return true;
    }

    /** @param Collection<int, string> $children */
    private function childLength(string $parentCode, Collection $children): int
    {
        if ($children->isNotEmpty()) {
            return (int) $children->map(fn (string $c) => strlen($c))->countBy()
                ->sortKeysDesc()->sortDesc()->keys()->first();
        }

        return str_ends_with($parentCode, '0') ? strlen($parentCode) : strlen($parentCode) + 1;
    }

    /**
     * Where the parent's range ends, at the children's length: the first code
     * after the parent that is outside its branch, capped at the end of the
     * parent's first-digit thousand.
     */
    private function rangeEnd(Account $parent, int $length): int
    {
        $branch = [$parent->id => true];
        do {
            $before = count($branch);
            foreach ($this->rows as $row) {
                if ($row->parent_id !== null && isset($branch[$row->parent_id])) {
                    $branch[$row->id] = true;
                }
            }
        } while (count($branch) > $before);

        $parentPadded = $this->pad((string) $parent->code);
        $next = $this->rows->reject(fn ($row) => isset($branch[$row->id]))
            ->pluck('code')->filter(fn (string $c) => ctype_digit($c))
            ->map(fn (string $c) => $this->pad($c))
            ->filter(fn (int $c) => $c > $parentPadded)->min();

        $cap = ((int) $parent->code[0] + 1) * 10 ** ($length - 1);

        if ($next === null) {
            return $cap;
        }

        // Back from padded width to the children's length, rounding up so a
        // code that starts part-way through a slot still closes that slot.
        $scale = 10 ** ($this->width - $length);

        return min($cap, intdiv($next + $scale - 1, $scale));
    }

    /**
     * The round unit the children are numbered in: for each child the largest
     * power of ten dividing its distance from the parent, the most common one
     * winning (ties go to the larger, which leaves more room).
     *
     * @param  Collection<int, int>  $children
     */
    private function unit(int $low, Collection $children, string $parentCode, int $length): int
    {
        if ($children->isEmpty()) {
            if ($length > strlen($parentCode)) {
                return 1;
            }

            $zeros = strlen($parentCode) - strlen(rtrim($parentCode, '0'));

            return 10 ** max(0, $zeros - 1);
        }

        return (int) $children->map(function (int $code) use ($low) {
            $distance = $code - $low;
            $unit = 1;
            while ($distance > 0 && $distance % ($unit * 10) === 0) {
                $unit *= 10;
            }

            return $unit;
        })->countBy()->sortKeysDesc()->sortDesc()->keys()->first();
    }

    /**
     * The unit, widened to the usual gap between siblings when that gap is a
     * whole number of units (the lower median, so one odd gap cannot stretch it).
     *
     * @param  Collection<int, int>  $children
     */
    private function step(int $unit, Collection $children): int
    {
        if ($children->count() < 2) {
            return $unit;
        }

        $gaps = $children->sliding(2)->map(fn (Collection $pair) => $pair->last() - $pair->first())->sort()->values();
        $median = $gaps[intdiv($gaps->count() - 1, 2)];

        return max($unit, intdiv($median, $unit) * $unit);
    }

    private function pad(string $code): int
    {
        return (int) str_pad($code, $this->width, '0');
    }

    private function format(int $slot, int $length): string
    {
        return str_pad((string) $slot, $length, '0', STR_PAD_LEFT);
    }
}
