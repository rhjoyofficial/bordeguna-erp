<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One supplier type a business keeps (Purchases > Suppliers > Supplier types,
 * 17 Oct 2026). Finance decision C4: the type picks the TDS/VDS rates offered
 * when a supplier is paid.
 *
 * ⚠⚠ Suppliers and withholding rates store the KEY, never the id. The key is
 * frozen once anything uses it; the name may change freely. A type that is
 * used is switched off, never deleted, so an old supplier still says what it
 * was.
 */
class SupplierType extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'key', 'name', 'is_active', 'sort'];

    protected $attributes = ['is_active' => true, 'sort' => 0];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort' => 'integer'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('name');
    }

    /** Every supplier on this type, active or not: a switched-off supplier still says what it was. */
    public function suppliersUsing(): int
    {
        return Supplier::query()->where('business_id', $this->business_id)->where('supplier_type', $this->key)->count();
    }

    public function ratesUsing(): int
    {
        return WithholdingRate::query()->where('business_id', $this->business_id)->where('supplier_type', $this->key)->count();
    }

    public function isUsed(): bool
    {
        return $this->suppliersUsing() > 0 || $this->ratesUsing() > 0;
    }
}
