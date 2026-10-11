<?php

namespace App\Modules\Foundation\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

/**
 * One person's widget order and hidden widgets on one dashboard.
 *
 * ⚠ Presentation only: hiding never changes what the person may see, and a
 * widget key they are not authorised for is ignored when the layout is applied.
 */
class DashboardLayout extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'user_id', 'dashboard', 'widget_order', 'hidden'];

    protected function casts(): array
    {
        return [
            'widget_order' => 'array',
            'hidden' => 'array',
        ];
    }
}
