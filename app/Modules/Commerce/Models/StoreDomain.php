<?php

namespace App\Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A hostname a store answers on.
 *
 * ⛔ NOT business-scoped, deliberately. Host resolution happens before any
 * tenant context exists, so a scope here would fail closed and every storefront
 * request would 404. The hostname column is globally unique instead, which is
 * the stronger guarantee anyway: one hostname, one store, decided by the
 * database rather than by row order.
 */
class StoreDomain extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'store_id',
        'hostname',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    protected $attributes = [
        'is_primary' => false,
    ];

    protected static function booted(): void
    {
        // Host headers are case-insensitive and may carry a port. Normalising on
        // the way in means the lookup never has to guess.
        static::saving(function (self $domain) {
            $domain->hostname = self::normaliseHostname($domain->hostname ?? '');
        });

        // ⚠ Exactly one primary per store, enforced here because MySQL has no
        // partial unique index to express "unique where is_primary is true".
        // Demoting the others rather than refusing is the kinder behaviour:
        // marking a new hostname primary is exactly how a store moves domain.
        static::saved(function (self $domain) {
            if (! $domain->is_primary) {
                return;
            }

            static::query()
                ->where('store_id', $domain->store_id)
                ->whereKeyNot($domain->getKey())
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        });
    }

    /**
     * Lower-case, trimmed, port removed, trailing dot removed.
     *
     * ⚠ The trailing dot matters: "laladia.com." is a legal fully qualified
     * hostname and some clients send it. Left alone it would miss the lookup and
     * the shop would 404 for exactly those visitors, which is the sort of fault
     * that is never reproducible on the developer's machine.
     */
    public static function normaliseHostname(string $host): string
    {
        $host = trim(strtolower($host));

        // Strip a port, but leave a bare IPv6 literal alone.
        if (! str_starts_with($host, '[') && str_contains($host, ':')) {
            $host = strstr($host, ':', true) ?: $host;
        }

        return rtrim($host, '.');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['hostname', 'is_primary'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
