<?php

namespace App\Modules\Foundation\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Business extends Model
{
    use HasAttachments, HasFactory;

    protected $fillable = [
        'name',
        'legal_name',
        'address',
        'phone',
        'email',
        'currency',
        'timezone',
        'fiscal_year_start_month',
        'slug',
        'is_active',
        // ⚠ `is_default` is deliberately absent. Which tenant a person lands in
        // is not a settings field, and leaving it unfillable means no request
        // path can ever mass-assign it. The migration and the seeder set it
        // explicitly, which works regardless of $fillable.
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'fiscal_year_start_month' => 'integer',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'business_user')->withTimestamps();
    }

    /**
     * The order in which a landing business is chosen when the session carries
     * none. W2: this replaced `orderBy('name')`, which let a display string
     * decide whose data somebody saw, and let a rename move every user silently.
     *
     * Total by design. The flag is the instruction, the id is the floor:
     * one row flagged wins; none flagged falls back to the lowest id; several
     * flagged resolves to the lowest flagged id. No state of this table can
     * produce a name-dependent answer.
     *
     * ⚠ Columns are qualified because this scope is also applied to
     * `$user->businesses()`, and the `business_user` pivot has its own `id`.
     * Unqualified, the ORDER BY is ambiguous across that join.
     *
     * ⚠ One caller only, on purpose: `ResolveCurrentBusiness`. Both the
     * super-admin and the member branch go through it so they cannot drift,
     * which is the same failure W1 was.
     */
    public function scopeInDefaultOrder(Builder $query): Builder
    {
        return $query
            ->orderByDesc('businesses.is_default')
            ->orderBy('businesses.id');
    }
}
