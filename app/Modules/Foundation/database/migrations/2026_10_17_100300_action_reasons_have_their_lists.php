<?php

use App\Modules\Foundation\Corrections\ActionReasons;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The reason lists every correct, reverse, cancel, withdraw, delete, deactivate,
 * dispose, restore and fix-details dialog offers (PHASE-0-CONTRACT 1.6).
 *
 * Data only, per business, idempotent: `reasons` is unique on (business_id,
 * context, name), so a second run inserts nothing and a reason somebody renamed
 * or switched off stays as they left it. The accountant edits the lists under
 * Settings > Reasons.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (DB::table('businesses')->pluck('id') as $businessId) {
            $rows = [];

            foreach (ActionReasons::CONTEXTS as $context => $label) {
                foreach (ActionReasons::DEFAULTS as $name) {
                    $rows[] = [
                        'business_id' => $businessId,
                        'context' => $context,
                        'name' => $name,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            DB::table('reasons')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        // ⚠ Left in place: a reason may already be named on a correction link.
    }
};
