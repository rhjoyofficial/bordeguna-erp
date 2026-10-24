<?php

namespace App\Modules\Inventory\database\seeders;

use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Models\Business;
use Illuminate\Database\Seeder;

/**
 * ⭐ Farm harvest (B9, 24 Oct 2026): who approves a crop failure write-off.
 *
 * One stage, the Owner (the farm guide, step 8: "with the reason, approved by
 * the Owner"). A Manager raises it and the Owner approves it; an Owner who
 * raises it may approve it himself with a short note, which the approval
 * history marks (Rule #22, CropWriteOff::isSelfApprovalBy).
 *
 * ⚠⚠ WITHOUT THIS ROW A WRITE-OFF WOULD REACH NOBODY, so the raise refuses
 * when it is missing (RaiseCropWriteOff) and EveryApprovalReachesSomebodyTest
 * checks it is seeded.
 *
 * Idempotent: an existing workflow and its stages are left untouched.
 */
class CropWriteOffWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Business::all() as $business) {
            $workflow = ApprovalWorkflow::withoutGlobalScopes()->firstOrCreate(
                ['business_id' => $business->id, 'key' => 'crop_write_off'],
                ['name' => 'Crop failure write-off', 'is_active' => true],
            );

            if ($workflow->stages()->exists()) {
                continue;
            }

            $workflow->stages()->createMany([
                [
                    'sequence' => 1,
                    'name' => 'Owner approval',
                    'allowed_roles' => ['Owner'],
                    'allowed_permissions' => [],
                ],
            ]);
        }
    }
}
