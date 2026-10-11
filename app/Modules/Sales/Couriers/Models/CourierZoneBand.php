<?php

namespace App\Modules\Sales\Couriers\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One of a courier's own price bands ("Inside Dhaka", "Suburbs", ...), as
 * editable data. The fee engine prices by it later.
 *
 * ⚠ `thanas` entries are "District/Thana" and beat a `districts` rule: Pathao
 * puts Keraniganj and Savar (thanas of Dhaka district) in Suburbs while the
 * rest of Dhaka district is Inside Dhaka. Exactly one band per provider is the
 * default, for every place no rule names.
 */
class CourierZoneBand extends Model
{
    use LogsActivity;

    protected $fillable = ['provider', 'code', 'name', 'districts', 'thanas', 'is_default', 'sort_order', 'updated_by'];

    protected function casts(): array
    {
        return ['districts' => 'array', 'thanas' => 'array', 'is_default' => 'boolean', 'sort_order' => 'integer'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'districts', 'thanas', 'is_default'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('couriers');
    }

    /**
     * The band a place falls in for one courier: a thana rule first, then a
     * district rule, then the default. Null only when the provider has no bands.
     */
    public static function for(string $provider, string $district, string $thana): ?self
    {
        $bands = static::query()->where('provider', $provider)->orderBy('sort_order')->orderBy('id')->get();
        $thanaKey = mb_strtolower($district.'/'.$thana);
        $districtKey = mb_strtolower($district);

        foreach ($bands as $band) {
            foreach ($band->thanas ?? [] as $entry) {
                if (mb_strtolower((string) $entry) === $thanaKey) {
                    return $band;
                }
            }
        }

        foreach ($bands as $band) {
            foreach ($band->districts ?? [] as $entry) {
                if (mb_strtolower((string) $entry) === $districtKey) {
                    return $band;
                }
            }
        }

        return $bands->firstWhere('is_default', true);
    }
}
