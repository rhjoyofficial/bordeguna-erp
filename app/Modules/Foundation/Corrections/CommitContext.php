<?php

namespace App\Modules\Foundation\Corrections;

use App\Models\User;

/** Who, why, when and under which exception a correction is being written. */
final readonly class CommitContext
{
    public function __construct(
        public User $actor,
        public ?User $approver,
        public ActionReason $reason,
        public CorrectionDates $dates,
        public ?ClosedPeriodPass $pass,
        public string $idempotencyKey,
        public string $originalNumber,
    ) {}
}
