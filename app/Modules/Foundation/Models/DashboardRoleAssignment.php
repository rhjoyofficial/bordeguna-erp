<?php

namespace App\Modules\Foundation\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

/**
 * Which dashboard holders of one role land on, in one business
 * (Settings > Dashboards). See App\Support\Dashboards\DashboardAccess.
 */
class DashboardRoleAssignment extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'role_id', 'dashboard'];
}
