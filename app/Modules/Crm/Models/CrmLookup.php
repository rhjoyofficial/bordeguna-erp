<?php

namespace App\Modules\Crm\Models;

use App\Modules\Crm\Enums\CrmLookupList;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry in an editable CRM list: a shop type, a supplier type or a
 * competitor brand. See CrmLookupList.
 *
 * ⚠ Deactivated, never deleted. A shop recorded as "Pharmacy / health / organic"
 * last year must still say so after somebody retires the entry from the picker.
 */
class CrmLookup extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'list', 'name', 'is_active', 'sort_order'];

    protected $attributes = ['is_active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['list' => CrmLookupList::class, 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function scopeOfList(Builder $query, CrmLookupList $list): Builder
    {
        return $query->where('list', $list->value);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
