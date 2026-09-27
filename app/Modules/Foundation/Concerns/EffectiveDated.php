<?php

namespace App\Modules\Foundation\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The temporal pattern used across HRM (employee↔org assignment first;
 * salary structures, attendance policy and shift/calendar later). A record
 * carries `effective_from` / `effective_to`; a change closes the old row and
 * opens a new one rather than overwriting, so "what was true on date X" stays
 * answerable — the foundation of a reproducible historical payroll.
 *
 * A null `effective_to` means "still in effect". Models using this must have
 * `effective_from` and `effective_to` date columns.
 */
trait EffectiveDated
{
    /**
     * Rows in effect on the given date (inclusive of both bounds).
     */
    public function scopeActiveOn(Builder $query, Carbon|string $date): Builder
    {
        $date = $date instanceof Carbon ? $date->toDateString() : $date;

        return $query->whereDate('effective_from', '<=', $date)
            ->where(function (Builder $inner) use ($date) {
                $inner->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date);
            });
    }

    /**
     * Rows in effect at ANY point in a period [from, to] (inclusive). Used to
     * find everyone who worked part of a payroll cycle, including someone who
     * joined or left in the middle of it (Joy, 27 Sep 2026: a mid-cycle leaver
     * is still paid for the days worked).
     */
    public function scopeActiveDuring(Builder $query, Carbon|string $from, Carbon|string $to): Builder
    {
        $from = $from instanceof Carbon ? $from->toDateString() : $from;
        $to = $to instanceof Carbon ? $to->toDateString() : $to;

        return $query->whereDate('effective_from', '<=', $to)
            ->where(function (Builder $inner) use ($from) {
                $inner->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from);
            });
    }

    /**
     * Rows in effect today.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->activeOn(now());
    }

    public function isActiveOn(Carbon|string $date): bool
    {
        $date = Carbon::parse($date instanceof Carbon ? $date->toDateString() : $date);
        $from = Carbon::parse($this->effective_from);

        if ($from->gt($date)) {
            return false;
        }

        return $this->effective_to === null || Carbon::parse($this->effective_to)->gte($date);
    }
}
