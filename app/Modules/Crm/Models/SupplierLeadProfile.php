<?php

namespace App\Modules\Crm\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a prospective supplier is and where its goods come from (Joy, answer 14).
 *
 * ⚠ Many suppliers are informal (answer 13), so nothing here is required and no
 * document is asked for. Certificates belong to the SUPPLIER record once it
 * exists, only where relevant (answer 18).
 */
class SupplierLeadProfile extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'lead_id', 'supplier_type_id', 'supply_categories', 'origin_district'];

    public function supplierType(): BelongsTo
    {
        return $this->belongsTo(CrmLookup::class, 'supplier_type_id');
    }
}
