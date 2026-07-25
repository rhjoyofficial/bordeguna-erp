<?php

namespace App\Modules\Hrm\Enums;

/**
 * What a correction does to a day's punches (ATT-03 / COR-01).
 *
 * A correction never edits a raw punch — it is a separate record describing an
 * adjustment, and the processing pipeline applies it when it gathers the day.
 * So "amend" does not rewrite the original: the original stays exactly as the
 * device recorded it, and the correction sits alongside it saying what should
 * be used instead.
 */
enum CorrectionType: string
{
    case AddPunch = 'add_punch';
    case AmendPunch = 'amend_punch';
    case IgnorePunch = 'ignore_punch';

    public function label(): string
    {
        return match ($this) {
            self::AddPunch => 'Add a missing punch',
            self::AmendPunch => 'Correct a punch time',
            self::IgnorePunch => 'Disregard a punch',
        };
    }

    /**
     * Whether this correction points at an existing punch.
     */
    public function needsPunch(): bool
    {
        return $this !== self::AddPunch;
    }

    /**
     * Whether this correction supplies a time of its own.
     */
    public function needsTime(): bool
    {
        return $this !== self::IgnorePunch;
    }
}
