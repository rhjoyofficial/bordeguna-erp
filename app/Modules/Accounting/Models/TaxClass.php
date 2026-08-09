<?php

namespace App\Modules\Accounting\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * What kind of thing something is, for tax purposes: standard, zero-rated,
 * exempt.
 *
 * ⚠⚠ A CLASS IS NOT A RATE. The class is stable - "this is a food item" is true
 * for years. The rate attached to it changes by government order, so the two are
 * separate tables and a product points at the class, never at a rate. Otherwise
 * every product row would have to be rewritten the day a rate changed, and every
 * historical invoice would start explaining itself with the new number.
 *
 * ⛔ NOTHING CALCULATES WITH THIS YET. VAT-READY-ARCHITECTURE.md, C-VAT-11.
 */
class TaxClass extends Model
{
    use BelongsToBusiness, LogsActivity;

    protected $fillable = ['business_id', 'code', 'name', 'is_active', 'note'];

    /**
     * ⚠ Mirrors the database default in the model. Without it a freshly created
     * instance carries null for is_active while the stored row is true, so a
     * guard reading `! $class->is_active` refuses a perfectly active class until
     * somebody reloads it. This project has met that bug twice already.
     */
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function rates(): HasMany
    {
        return $this->hasMany(TaxRate::class);
    }

    /**
     * The rate that applied on a given day.
     *
     * ⚠⚠ ON A DATE, never "the current rate". An invoice raised in June must
     * still be explicable by June's rate after a July change, which is the whole
     * reason rates are effective-dated rather than edited in place.
     *
     * ⚠ Returns null when no rate was in force yet. The caller decides what that
     * means - and while the engine is dormant, nobody calls this at all.
     */
    public function rateOn(Carbon $date): ?TaxRate
    {
        return $this->rates()
            ->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }
}
