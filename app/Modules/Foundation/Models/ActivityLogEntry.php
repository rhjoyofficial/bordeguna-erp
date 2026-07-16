<?php

namespace App\Modules\Foundation\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Spatie\Activitylog\Models\Activity;

/**
 * Overrides spatie/laravel-activitylog's default model so every entry is
 * tenant-scoped like the rest of the app (config('activitylog.activity_model')).
 */
class ActivityLogEntry extends Activity
{
    use BelongsToBusiness;
}
