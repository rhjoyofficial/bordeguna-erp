<?php

namespace App\Modules\Foundation\database\seeders;

use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Models\Business;
use Illuminate\Database\Seeder;

/**
 * Who approves a correction (PHASE-0-CONTRACT v2, 1.8).
 *
 *   correction                 above the Tk 20,000 limit: ONE stage, the second person,
 *                              the Owner role or anyone holding accounting.correct.approve
 *   correction_closed_period   a closed month asked for by somebody who is not the
 *                              Owner: ONE stage, the Owner role only
 *
 * ⚠⚠ The stage says who MAY act; CorrectionRequest::refusesApprovalBy says who
 * may NEVER act on this one request (the requester, and for a second-person
 * request the original's maker and last editor). The refusal is checked before
 * the stage, so it holds for a super admin too: Joy can never be his own
 * second person.
 *
 * Idempotent: an existing workflow and its stages are left untouched. Run by
 * migration 2026_10_17_100400 for existing businesses and by a fresh install.
 */
class CorrectionWorkflowSeeder extends Seeder
{
    public const WORKFLOWS = [
        'correction' => [
            'name' => 'Correction above the approval limit',
            'stage' => 'Second person',
            'roles' => ['Owner'],
            'permissions' => ['accounting.correct.approve'],
        ],
        'correction_closed_period' => [
            'name' => 'Correction in a closed month',
            'stage' => 'Owner',
            'roles' => ['Owner'],
            'permissions' => [],
        ],
    ];

    public function run(): void
    {
        foreach (Business::withoutGlobalScopes()->get() as $business) {
            foreach (self::WORKFLOWS as $key => $definition) {
                $workflow = ApprovalWorkflow::withoutGlobalScopes()->firstOrCreate(
                    ['business_id' => $business->id, 'key' => $key],
                    ['name' => $definition['name'], 'is_active' => true],
                );

                if ($workflow->stages()->exists()) {
                    continue;
                }

                $workflow->stages()->create([
                    'sequence' => 1,
                    'name' => $definition['stage'],
                    'allowed_roles' => $definition['roles'],
                    'allowed_permissions' => $definition['permissions'],
                ]);
            }
        }
    }
}
