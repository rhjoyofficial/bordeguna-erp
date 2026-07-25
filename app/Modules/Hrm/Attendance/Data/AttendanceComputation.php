<?php

namespace App\Modules\Hrm\Attendance\Data;

use App\Modules\Hrm\Enums\AttendanceConsequence;
use App\Modules\Hrm\Enums\AttendanceStatus;
use Illuminate\Support\Carbon;

/**
 * The value that travels through the pipeline, accumulating what each stage
 * derived. Immutable: a stage returns a new instance rather than mutating, so
 * the sequence of stages is the only thing that can change the outcome and any
 * stage can be run, inspected or reordered in isolation.
 *
 * `notes` is not decoration. Every stage that decides something records why, so
 * the finished day can explain each number back to the person it affects —
 * "explain every number" is a design mandate here, and an attendance figure
 * nobody can account for is one HR cannot defend.
 */
final readonly class AttendanceComputation
{
    /**
     * @param  array<int, PunchPair>  $pairs
     * @param  array<int, string>  $notes
     * @param  array<int, string>  $flags
     */
    public function __construct(
        public DayContext $context,
        public array $pairs = [],
        public ?Carbon $firstIn = null,
        public ?Carbon $lastOut = null,
        public int $workedMinutes = 0,
        public int $breakMinutes = 0,
        public int $payableMinutes = 0,
        public int $lateMinutes = 0,
        public int $earlyLeaveMinutes = 0,
        public ?AttendanceStatus $status = null,
        public ?string $graceWindowKey = null,
        public ?string $graceWindowLabel = null,
        public ?int $occurrenceNumber = null,
        public AttendanceConsequence $consequence = AttendanceConsequence::None,
        public bool $hasOpenPair = false,
        public array $notes = [],
        public array $flags = [],
    ) {}

    public static function start(DayContext $context): self
    {
        return new self($context);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        return new self(
            $changes['context'] ?? $this->context,
            $changes['pairs'] ?? $this->pairs,
            array_key_exists('firstIn', $changes) ? $changes['firstIn'] : $this->firstIn,
            array_key_exists('lastOut', $changes) ? $changes['lastOut'] : $this->lastOut,
            $changes['workedMinutes'] ?? $this->workedMinutes,
            $changes['breakMinutes'] ?? $this->breakMinutes,
            $changes['payableMinutes'] ?? $this->payableMinutes,
            $changes['lateMinutes'] ?? $this->lateMinutes,
            $changes['earlyLeaveMinutes'] ?? $this->earlyLeaveMinutes,
            array_key_exists('status', $changes) ? $changes['status'] : $this->status,
            array_key_exists('graceWindowKey', $changes) ? $changes['graceWindowKey'] : $this->graceWindowKey,
            array_key_exists('graceWindowLabel', $changes) ? $changes['graceWindowLabel'] : $this->graceWindowLabel,
            array_key_exists('occurrenceNumber', $changes) ? $changes['occurrenceNumber'] : $this->occurrenceNumber,
            $changes['consequence'] ?? $this->consequence,
            $changes['hasOpenPair'] ?? $this->hasOpenPair,
            $changes['notes'] ?? $this->notes,
            $changes['flags'] ?? $this->flags,
        );
    }

    public function note(string $note): self
    {
        return $this->with(['notes' => [...$this->notes, $note]]);
    }

    public function flag(string $flag): self
    {
        return $this->with(['flags' => [...$this->flags, $flag]]);
    }

    /**
     * Conditional step, so a stage can branch without breaking the chain.
     *
     * @param  callable(self): self  $callback
     */
    public function when(bool $condition, callable $callback): self
    {
        return $condition ? $callback($this) : $this;
    }

    public function workedHours(): float
    {
        return round($this->workedMinutes / 60, 2);
    }

    public function payableHours(): float
    {
        return round($this->payableMinutes / 60, 2);
    }
}
