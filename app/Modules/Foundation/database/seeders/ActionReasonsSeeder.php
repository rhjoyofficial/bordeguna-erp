<?php

namespace App\Modules\Foundation\database\seeders;

use App\Modules\Foundation\Corrections\ActionReasons;
use App\Modules\Foundation\Models\Business;
use Illuminate\Database\Seeder;

/** The action dialogs' reason lists for every business (fresh install). Idempotent. */
class ActionReasonsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Business::withoutGlobalScopes()->pluck('id') as $businessId) {
            ActionReasons::seedFor((int) $businessId);
        }
    }
}
