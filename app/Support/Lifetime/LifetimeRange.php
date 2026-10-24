<?php

namespace App\Support\Lifetime;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * ⭐ B23 lifetime views (25 Oct 2026; R75 to R77): the date window every
 * lifetime page reads, "all time" unless the reader narrows it.
 *
 * Joy: "their will be page from where can be fetch all life time information
 * about a product, supplier, employee, customer". So an empty From and To is
 * not "this month" (the statements' default) but the whole history; a bad
 * date is ignored rather than refused, because a read-only page should never
 * answer a typo with an error.
 *
 * ⚠ Dates compare as Y-m-d strings (C-CST-5, the recurring date-cast trap).
 */
final class LifetimeRange
{
    /** The first day a statement is asked from when the window is "all time". */
    private const BEGINNING = '1900-01-01';

    public function __construct(public readonly ?Carbon $from, public readonly ?Carbon $to) {}

    public static function fromRequest(Request $request): self
    {
        $from = self::date($request->query('from'));
        $to = self::date($request->query('to'));

        if ($from !== null && $to !== null && $from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return new self($from, $to);
    }

    public static function allTime(): self
    {
        return new self(null, null);
    }

    public function isAllTime(): bool
    {
        return $this->from === null && $this->to === null;
    }

    /** Whether a Y-m-d (or longer) date string falls inside the window. A row with no date is kept. */
    public function contains(?string $date): bool
    {
        if ($date === null || $date === '') {
            return true;
        }

        $day = substr($date, 0, 10);

        return ($this->from === null || $day >= $this->from->toDateString())
            && ($this->to === null || $day <= $this->to->toDateString());
    }

    /** The start a statement is built from: the window's, or the beginning of time. */
    public function statementFrom(): Carbon
    {
        return $this->from?->copy() ?? Carbon::parse(self::BEGINNING);
    }

    /** The end a statement is built to: the window's, or today. */
    public function statementTo(): Carbon
    {
        return $this->to?->copy() ?? Carbon::today();
    }

    /** Narrow a query on a date or datetime column (or an expression) to the window. */
    public function apply($query, string|Expression $column)
    {
        return $query
            ->when($this->from !== null, fn ($q) => $q->where($column, '>=', $this->from->toDateString()))
            // A datetime on the last day is still inside: compare against the next day.
            ->when($this->to !== null, fn ($q) => $q->where($column, '<', $this->to->copy()->addDay()->toDateString()));
    }

    /** @return array<string, string> the query string that keeps the window on the next visit */
    public function query(): array
    {
        return array_filter([
            'from' => $this->from?->toDateString(),
            'to' => $this->to?->toDateString(),
        ]);
    }

    /** @return array{from: ?string, to: ?string, all_time: bool} */
    public function toArray(): array
    {
        return [
            'from' => $this->from?->toDateString(),
            'to' => $this->to?->toDateString(),
            'all_time' => $this->isAllTime(),
        ];
    }

    private static function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        return $date->toDateString() === $value ? $date : null;
    }
}
