<?php

namespace App\Modules\Foundation\Corrections;

use Illuminate\Support\Facades\DB;

/**
 * The reason lists of the action dialogs (PHASE-0-CONTRACT 1.6): one context per
 * verb, the same eight defaults in each, editable under Settings > Reasons.
 */
final class ActionReasons
{
    public const CONTEXTS = [
        'correction' => 'Correct a record',
        'reversal' => 'Reverse a record',
        'cancellation' => 'Cancel a record',
        'withdrawal' => 'Withdraw a request',
        'deletion' => 'Delete a draft',
        'deactivation' => 'Switch a record off',
        'disposal' => 'Dispose of an asset',
        'restore' => 'Restore from the Recycle bin',
        'fix_details' => 'Fix details',
    ];

    public const DEFAULTS = [
        'Wrong amount',
        'Wrong date',
        'Wrong head or category',
        'Wrong money account',
        'Wrong party',
        'Entered twice',
        'Entered by mistake',
        'Other',
    ];

    /** Idempotent: the reasons table is unique on (business_id, context, name). */
    public static function seedFor(int $businessId): void
    {
        $now = now();
        $rows = [];

        foreach (array_keys(self::CONTEXTS) as $context) {
            foreach (self::DEFAULTS as $name) {
                $rows[] = ['business_id' => $businessId, 'context' => $context, 'name' => $name, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now];
            }
        }

        DB::table('reasons')->insertOrIgnore($rows);
    }
}
