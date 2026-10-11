<?php

namespace App\Modules\Sales\Couriers\Models;

use App\Modules\Sales\Couriers\Providers\CourierProviderDefinition;
use App\Modules\Sales\Couriers\Providers\CourierProviderRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A courier company. Provider-wide, never business-scoped. What it can do and
 * which fields it needs come from its definition in code.
 */
class CourierProvider extends Model
{
    protected $fillable = ['code', 'name', 'is_connected', 'sort_order'];

    protected function casts(): array
    {
        return ['is_connected' => 'boolean', 'sort_order' => 'integer'];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(CourierAccount::class);
    }

    public function definition(): CourierProviderDefinition
    {
        return app(CourierProviderRegistry::class)->get($this->code);
    }

    public static function forCode(string $code): self
    {
        return static::query()->where('code', $code)->firstOrFail();
    }
}
