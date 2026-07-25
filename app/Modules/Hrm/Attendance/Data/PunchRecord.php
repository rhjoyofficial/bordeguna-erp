<?php

namespace App\Modules\Hrm\Attendance\Data;

use App\Modules\Hrm\Enums\PunchDirection;
use App\Modules\Hrm\Enums\PunchSource;
use Illuminate\Support\Carbon;

/**
 * A punch as the engine sees it — a plain value, detached from Eloquent.
 *
 * The engine never touches the AttendancePunch model: keeping the input a value
 * object is what makes the pipeline reproducible from a fixture and impossible
 * to accidentally write through.
 */
final readonly class PunchRecord
{
    public function __construct(
        public int $id,
        public Carbon $at,
        public ?PunchDirection $direction,
        public PunchSource $source,
    ) {}
}
