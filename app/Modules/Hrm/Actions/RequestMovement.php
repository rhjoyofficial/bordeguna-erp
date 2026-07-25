<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Hrm\Enums\MovementType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\Movement;
use Illuminate\Support\Facades\DB;

/**
 * Raises a movement request — field duty, official work, or work on a holiday
 * (POL-09 / POL-10).
 *
 * Separate from leave by LEV-04's design: a movement draws on no balance and is
 * confirmed by a manager rather than charged to a quota.
 */
class RequestMovement
{
    public function __construct(private readonly NotifyStageApprovers $notify) {}

    /**
     * @param  array<string, mixed>  $data  from_time/to_time/place/reason
     */
    public function handle(Employee $employee, MovementType $type, string $date, array $data, User $requestedBy): Movement
    {
        return DB::transaction(function () use ($employee, $type, $date, $data, $requestedBy) {
            $movement = Movement::create([
                'employee_id' => $employee->id,
                'type' => $type,
                'movement_date' => $date,
                'from_time' => $data['from_time'] ?? null,
                'to_time' => $data['to_time'] ?? null,
                'place' => $data['place'] ?? null,
                'reason' => $data['reason'],
                'requested_by' => $requestedBy->id,
            ]);

            $approval = $movement->submitForApproval($requestedBy);
            $this->notify->handle($approval, $movement->summaryLine());

            return $movement->fresh();
        });
    }
}
