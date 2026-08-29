<?php

namespace App\Modules\Crm\database\seeders;

use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Models\Business;
use Illuminate\Database\Seeder;

/**
 * Who says yes before the business spends money on a send.
 *
 * ⚠⚠ THIS ROW SHIPS IN THE SAME COMMIT AS THE CODE, and it is not a courtesy.
 * The HRM expense claim module shipped with a route, a screen, a nav link, a
 * migration and twelve green tests and reached nobody, purely because this row
 * was missing: `submitForApproval` attaches no workflow, the request carries no
 * stage, and the inbox lists only what a stage says the reader may act on.
 * `EveryApprovalReachesSomebodyTest` now fails the build if this is absent.
 *
 * ⚠ ONE STAGE, on `crm.campaigns.approve`. A send is a marketing decision with a
 * bill attached, so the person who approves it is the person who answers for the
 * spend, not a line manager. Owner is named as a role as well, so a business
 * that has not handed the permission out yet is not left with campaigns nobody
 * can action - the same shape the expense claim workflow uses.
 *
 * Idempotent: safe to re-run; an existing workflow and its stages are untouched.
 */
class MessageCampaignWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Business::all() as $business) {
            $workflow = ApprovalWorkflow::withoutGlobalScopes()->firstOrCreate(
                ['business_id' => $business->id, 'key' => 'message_campaign'],
                ['name' => 'Message campaign', 'is_active' => true],
            );

            if ($workflow->stages()->exists()) {
                continue;
            }

            $workflow->stages()->createMany([
                [
                    'sequence' => 1,
                    'name' => 'Approve the spend',
                    'allowed_roles' => ['Owner'],
                    'allowed_permissions' => ['crm.campaigns.approve'],
                ],
            ]);
        }
    }
}
