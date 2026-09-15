<?php

namespace App\Modules\Crm\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a shop tells an officer about itself (Joy, answers 4, 7 and 9).
 *
 * ⚠ `wants_credit` is three-valued on purpose: NULL means nobody asked, which
 * is not the same fact as a shop that said no. Credit LIMIT and terms are not
 * here - they are set on the customer at conversion (answer 9).
 */
class RetailerLeadProfile extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'lead_id', 'shop_type_id', 'current_supplier', 'wants_credit'];

    protected function casts(): array
    {
        return ['wants_credit' => 'boolean'];
    }

    public function shopType(): BelongsTo
    {
        return $this->belongsTo(CrmLookup::class, 'shop_type_id');
    }
}
