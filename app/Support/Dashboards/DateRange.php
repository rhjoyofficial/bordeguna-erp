<?php

namespace App\Support\Dashboards;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The dashboard's one date range (ruling 8): shared presets, Asia/Dhaka, a
 * previous period to compare against, carried in the URL query.
 *
 * ⚠ "This month", "this quarter" and "this year" run to TODAY, not to the end of
 * the period: a month-to-date figure compared with a whole previous month would
 * always look like a collapse. Their previous period is the same span one period
 * back (1-11 Oct compares with 1-11 Sep).
 */
final class DateRange
{
    public const TIMEZONE = 'Asia/Dhaka';

    /** The longest custom range accepted, so nobody asks a widget for a decade by day. */
    public const MAX_DAYS = 366;

    public const PRESETS = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'last_7_days' => 'Last 7 days',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'this_quarter' => 'This quarter',
        'this_year' => 'This year',
        'custom' => 'Custom',
    ];

    public const DEFAULT = 'this_month';

    private function __construct(
        public readonly string $preset,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {}

    public static function make(string $preset, CarbonImmutable $from, CarbonImmutable $to): self
    {
        return new self($preset, $from->startOfDay(), $to->endOfDay());
    }

    /**
     * @param  array<string, mixed>  $query  range, from, to
     *
     * @throws ValidationException
     */
    public static function fromQuery(array $query, ?CarbonImmutable $now = null): self
    {
        $now = ($now ?? CarbonImmutable::now(self::TIMEZONE))->setTimezone(self::TIMEZONE);
        $today = $now->startOfDay();
        $preset = is_string($query['range'] ?? null) && array_key_exists($query['range'], self::PRESETS)
            ? $query['range']
            : self::DEFAULT;

        return match ($preset) {
            'today' => self::make($preset, $today, $today),
            'yesterday' => self::make($preset, $today->subDay(), $today->subDay()),
            'last_7_days' => self::make($preset, $today->subDays(6), $today),
            'this_month' => self::make($preset, $today->startOfMonth(), $today),
            'last_month' => self::make($preset, $today->startOfMonth()->subMonthNoOverflow(), $today->startOfMonth()->subDay()),
            'this_quarter' => self::make($preset, $today->firstOfQuarter(), $today),
            'this_year' => self::make($preset, $today->startOfYear(), $today),
            default => self::custom($query),
        };
    }

    /** @throws ValidationException */
    private static function custom(array $query): self
    {
        $parse = function (string $field) use ($query): CarbonImmutable {
            $value = $query[$field] ?? null;

            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                throw ValidationException::withMessages([$field => 'Pick a date for a custom range.']);
            }

            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, self::TIMEZONE);

            if ($date === false || $date->format('Y-m-d') !== $value) {
                throw ValidationException::withMessages([$field => 'That is not a real date.']);
            }

            return $date;
        };

        $from = $parse('from');
        $to = $parse('to');

        if ($to->lt($from)) {
            throw ValidationException::withMessages(['to' => 'The range ends before it starts.']);
        }

        if ((int) $from->diffInDays($to) + 1 > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'A custom range can cover at most '.self::MAX_DAYS.' days.']);
        }

        return self::make('custom', $from, $to);
    }

    /** The period this one is compared with. */
    public function previous(): self
    {
        $from = $this->from->startOfDay();
        $to = $this->to->startOfDay();

        return match ($this->preset) {
            'today', 'yesterday' => self::make($this->preset, $from->subDay(), $to->subDay()),
            'last_7_days' => self::make($this->preset, $from->subDays(7), $to->subDays(7)),
            'this_month' => self::make($this->preset, $from->subMonthNoOverflow(), self::earlier($to->subMonthNoOverflow(), $from->subMonthNoOverflow()->endOfMonth())),
            'last_month' => self::make($this->preset, $from->subMonthNoOverflow(), $from->subDay()),
            'this_quarter' => self::make($this->preset, $from->subMonthsNoOverflow(3), self::earlier($to->subMonthsNoOverflow(3), $from->subDay())),
            'this_year' => self::make($this->preset, $from->subYearNoOverflow(), $to->subYearNoOverflow()),
            default => self::make($this->preset, $from->subDays($this->days()), $from->subDay()),
        };
    }

    private static function earlier(CarbonImmutable $a, CarbonImmutable $b): CarbonImmutable
    {
        return $a->lt($b) ? $a : $b;
    }

    public function days(): int
    {
        return (int) $this->from->startOfDay()->diffInDays($this->to->startOfDay()) + 1;
    }

    /** @return list<string> every date in the range, Y-m-d */
    public function dates(): array
    {
        $dates = [];

        for ($day = $this->from->startOfDay(); $day->lte($this->to); $day = $day->addDay()) {
            $dates[] = $day->format('Y-m-d');
        }

        return $dates;
    }

    public function fromDate(): string
    {
        return $this->from->format('Y-m-d');
    }

    public function toDate(): string
    {
        return $this->to->format('Y-m-d');
    }

    public function label(): string
    {
        return $this->preset === 'custom'
            ? $this->from->format('j M Y').' to '.$this->to->format('j M Y')
            : self::PRESETS[$this->preset];
    }

    public function cacheFragment(): string
    {
        return $this->preset.':'.$this->fromDate().':'.$this->toDate();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $previous = $this->previous();

        return [
            'preset' => $this->preset,
            'from' => $this->fromDate(),
            'to' => $this->toDate(),
            'label' => $this->label(),
            'previous' => ['from' => $previous->fromDate(), 'to' => $previous->toDate()],
        ];
    }

    /** @return array<string, string> the query string that rebuilds this range */
    public function toQuery(): array
    {
        return $this->preset === 'custom'
            ? ['range' => 'custom', 'from' => $this->fromDate(), 'to' => $this->toDate()]
            : ['range' => $this->preset];
    }
}
