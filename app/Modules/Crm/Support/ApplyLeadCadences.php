<?php

namespace App\Modules\Crm\Support;

use App\Models\User;
use App\Modules\Crm\Models\CrmTask;
use App\Modules\Crm\Models\Lead;
use Illuminate\Support\Facades\DB;

/**
 * The lead_created cadence: a fresh lead gets its first-call task the moment
 * it exists, due after the standing delay. The queue and the overdue digest
 * already know what to do with a task - this only writes one.
 */
class ApplyLeadCadences
{
    public function handle(Lead $lead, User $creator): void
    {
        $cadences = DB::table('crm_cadences')
            ->where('business_id', $lead->business_id)
            ->where('trigger', 'lead_created')
            ->where('is_active', true)
            ->get();

        foreach ($cadences as $cadence) {
            CrmTask::create([
                'business_id' => $lead->business_id,
                'subject_type' => Lead::class,
                'subject_id' => $lead->id,
                'title' => $cadence->task_title,
                'due_at' => now()->addDays((int) $cadence->delay_days),
                'assigned_to_user_id' => $lead->assigned_to_user_id ?? $creator->id,
                'status' => 'open',
                'created_by' => $creator->id,
            ]);
        }
    }
}
