<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * The recipe for one packed product: what goes into it, per unit of output.
 *
 * ⚠⚠ C-BOM-1. A TEMPLATE, NOT A DOCUMENT. It posts nothing, moves nothing and
 * creates no batch. Saving a BOM changes what the run screen SUGGESTS and
 * nothing else in the system.
 *
 * ⚠⚠ C-BOM-2. A run COPIES this; it does not read it back. Editing a recipe
 * must never change what a past run says it consumed, which is why each version
 * is its own row and a used version is never mutated.
 *
 * ⚠ C-BOM-4. Line quantities are in each COMPONENT'S own stock unit and nothing
 * here converts. 0.4 kg of bulk pickle per 1 pcs of jar is a stated recipe
 * quantity. Mustard oil, whose two directions are 21% apart and not
 * reciprocals, is the reason that rule is absolute.
 *
 * ⚠ LogsActivity, unlike `Warehouse` and `Supplier`. A recipe decides what a
 * run suggests consuming, so "who changed the pickle recipe" is a question that
 * will be asked.
 */
class BillOfMaterial extends Model
{
    use BelongsToBusiness, HasFactory, LogsActivity;

    protected $table = 'bills_of_material';

    protected $fillable = [
        'business_id',
        'output_product_id',
        'version',
        'name',
        'output_quantity',
        'is_active',
        'effective_from',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'output_quantity' => 'decimal:4',
            'is_active' => 'boolean',
            'effective_from' => 'date:Y-m-d',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['output_product_id', 'version', 'name', 'output_quantity', 'is_active', 'effective_from'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function outputProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'output_product_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BillOfMaterialLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ProductionRun::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Whether any production run was ever built from this exact version.
     *
     * ⚠ The test for whether it may be deleted rather than deactivated. A
     * version a run points at is the answer to "what recipe was this built
     * from", and deleting it erases that answer.
     */
    public function hasBeenUsed(): bool
    {
        return $this->runs()->exists();
    }
}
