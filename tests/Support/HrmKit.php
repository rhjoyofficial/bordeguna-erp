<?php

namespace Tests\Support;

use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Hrm\database\seeders\LeaveTypeSeeder;
use App\Modules\Hrm\Enums\MovementType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\Movement;
use App\Modules\Hrm\Models\ShowCauseNotice;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Corrections wave 3 (24 Oct 2026): the HR records the policy kit
 * (PolicyKitTest, test 40) walks, in each state the HR policies can reach.
 * Built straight into the correction world's business; the approval states
 * are written by hand because the kit tests the policies, not the workflow.
 */
final class HrmKit
{
    /** @return array<string, list<array{0: Model, 1: string}>> keyed by registry type */
    public static function records(CorrectionWorld $w): array
    {
        $w->enter($w->business);
        (new LeaveTypeSeeder)->run();
        $type = LeaveType::query()->where('code', 'casual')->firstOrFail();

        $employee = Employee::create(['employee_code' => 'KIT-000001', 'first_name' => 'Kit', 'last_name' => 'Person', 'employment_type' => 'permanent', 'status' => 'active']);
        $draft = Employee::create(['employee_code' => 'KIT-000002', 'first_name' => 'Kit', 'last_name' => 'Draft', 'employment_type' => 'permanent', 'status' => 'draft']);
        $separated = Employee::create(['employee_code' => 'KIT-000003', 'first_name' => 'Kit', 'last_name' => 'Gone', 'employment_type' => 'permanent', 'status' => 'separated', 'separation_type' => 'resignation', 'separation_date' => '2026-10-10']);

        $leave = fn (string $start, ?ApprovalStatus $status, bool $applied = false, bool $cancelled = false) => tap(LeaveRequest::create([
            'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'start_date' => $start, 'end_date' => $start,
            'day_portion' => 'full_day', 'days' => 1, 'unpaid_days' => 0, 'reason' => 'Kit', 'covered_dates' => [$start],
            'requested_by' => $w->owner->id, 'applied_at' => $applied ? now() : null, 'cancelled_at' => $cancelled ? now() : null,
        ]), function (LeaveRequest $request) use ($w, $status) {
            $request->submitForApproval($w->owner)->update(['status' => $status]);
        });

        $movement = fn (string $date, bool $cancelled) => tap(Movement::create([
            'employee_id' => $employee->id, 'type' => MovementType::FieldDuty, 'movement_date' => $date, 'reason' => 'Kit',
            'requested_by' => $w->owner->id, 'applied_at' => now(), 'cancelled_at' => $cancelled ? now() : null,
        ]), fn (Movement $m) => $m->submitForApproval($w->owner)->update(['status' => ApprovalStatus::Approved]));

        $notice = fn (bool $withdrawn) => ShowCauseNotice::create([
            'employee_id' => $employee->id, 'issued_by' => $w->owner->id, 'subject' => 'Kit', 'body' => 'Kit', 'issued_at' => now(),
            'withdrawn_at' => $withdrawn ? now() : null, 'withdrawn_on' => $withdrawn ? now()->toDateString() : null,
        ]);

        return [
            'hrm.leave_request' => [
                [$leave('2026-10-05', ApprovalStatus::Pending)->fresh(), 'waiting'],
                [$leave('2026-10-06', ApprovalStatus::Approved, true)->fresh(), 'approved'],
                [$leave('2026-10-07', ApprovalStatus::Approved, true, true)->fresh(), 'cancelled'],
                [$leave('2026-10-08', ApprovalStatus::Rejected)->fresh(), 'rejected'],
            ],
            'hrm.movement' => [
                [$movement('2026-10-06', false)->fresh(), 'approved'],
                [$movement('2026-10-07', true)->fresh(), 'cancelled'],
            ],
            'hrm.employee' => [[$employee, 'active'], [$draft, 'draft'], [$separated, 'separated']],
            'hrm.show_cause_notice' => [[$notice(false), 'issued'], [$notice(true), 'withdrawn']],
        ];
    }
}
