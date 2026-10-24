<?php

namespace App\Modules\Foundation\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Corrections\Contracts\Removable;
use App\Modules\Foundation\Corrections\Recycle\RecyclableMaster;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Office extends Model implements Removable
{
    use BelongsToBusiness, HasFactory, RecyclableMaster;

    protected $fillable = [
        'business_id',
        'name',
        'code',
        'is_active',
        // Optional clock-in geofence (ATT-05). Enforcement is off unless an
        // office opts in; outside-the-fence punches are flagged, never rejected.
        'geofence_latitude',
        'geofence_longitude',
        'geofence_radius_meters',
        'geofence_enforced',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'geofence_latitude' => 'decimal:7',
            'geofence_longitude' => 'decimal:7',
            'geofence_radius_meters' => 'integer',
            'geofence_enforced' => 'boolean',
        ];
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    // --- Recycle bin and Switch off (B24, 24 Oct 2026, Q5 = A) -------------

    /** Named pointers; foreign keys at this table are found on their own. */
    public function usageColumns(): array
    {
        return ['office_id'];
    }

    public function binLabel(): string
    {
        return 'Office '.$this->name;
    }
}
