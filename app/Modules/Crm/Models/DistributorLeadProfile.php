<?php

namespace App\Modules\Crm\Models;

use App\Modules\Crm\Enums\WarehouseOwnership;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

/**
 * What a distributor CLAIMS about its capacity (Joy, answer 17).
 *
 * ⚠⚠ Every number here is the distributor's own statement, recorded with no
 * minimum and checked by nobody unless the ASM records an inspection. The
 * screen says "as they told it", and nothing downstream may treat these as
 * verified facts.
 */
class DistributorLeadProfile extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'lead_id', 'business_nature', 'other_companies',
        'warehouse_count', 'warehouse_ownership', 'vehicle_count', 'sales_staff_count',
    ];

    protected function casts(): array
    {
        return [
            'warehouse_ownership' => WarehouseOwnership::class,
            'warehouse_count' => 'integer',
            'vehicle_count' => 'integer',
            'sales_staff_count' => 'integer',
        ];
    }
}
