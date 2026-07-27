<?php

namespace App\Modules\Hrm\Reporting;

/**
 * One column of a report: what it is called, what kind of value it holds, and
 * how wide it wants to be.
 *
 * Immutable, and deliberately dumb. A column knows nothing about xlsx, CSV or
 * PDF — the writers ask it what type it is and decide for themselves. That is
 * what makes "the same value rendered three ways" a testable claim rather than
 * a hope.
 */
final class ReportColumn
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ReportColumnType $type = ReportColumnType::Text,
        /** Preferred width in characters; writers that cannot honour it ignore it. */
        public readonly ?int $width = null,
    ) {}

    public static function text(string $key, string $label, ?int $width = null): self
    {
        return new self($key, $label, ReportColumnType::Text, $width);
    }

    public static function money(string $key, string $label, ?int $width = 14): self
    {
        return new self($key, $label, ReportColumnType::Money, $width);
    }

    public static function integer(string $key, string $label, ?int $width = 10): self
    {
        return new self($key, $label, ReportColumnType::Integer, $width);
    }

    public static function decimal(string $key, string $label, ?int $width = 10): self
    {
        return new self($key, $label, ReportColumnType::Decimal, $width);
    }

    public static function date(string $key, string $label, ?int $width = 12): self
    {
        return new self($key, $label, ReportColumnType::Date, $width);
    }

    public static function dateTime(string $key, string $label, ?int $width = 18): self
    {
        return new self($key, $label, ReportColumnType::DateTime, $width);
    }

    public static function time(string $key, string $label, ?int $width = 10): self
    {
        return new self($key, $label, ReportColumnType::Time, $width);
    }

    public static function duration(string $key, string $label, ?int $width = 10): self
    {
        return new self($key, $label, ReportColumnType::Duration, $width);
    }

    public static function boolean(string $key, string $label, ?int $width = 8): self
    {
        return new self($key, $label, ReportColumnType::Boolean, $width);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type->value,
            'align' => $this->type->align(),
            'width' => $this->width,
        ];
    }
}
