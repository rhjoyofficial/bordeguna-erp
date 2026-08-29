<?php

namespace App\Modules\Crm\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One way of sending, and its credentials.
 *
 * ⚠⚠ `credentials` IS ENCRYPTED BY THE CAST and must never be rendered back to a
 * screen. `maskedCredentials()` is what a settings page may show. A page that
 * could display a key is a page that eventually does, in a screenshot, in a
 * support chat, on a shared monitor.
 */
class MessageProvider extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'channel', 'driver', 'name', 'credentials',
        'sender_id', 'is_active', 'priority', 'cost_per_part',
    ];

    /**
     * ⚠ Created INACTIVE. A provider that switched itself on the moment it was
     * saved would start spending before anybody had checked the credentials.
     */
    protected $attributes = ['is_active' => false, 'priority' => 100];

    protected function casts(): array
    {
        return [
            // ⚠⚠ `encrypted:array` - at rest this is ciphertext. Losing
            // APP_KEY makes these unreadable, which is the correct trade.
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
            'cost_per_part' => 'decimal:4',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    /**
     * The last four characters of each secret, and nothing else.
     *
     * @return array<string,string>
     */
    public function maskedCredentials(): array
    {
        $masked = [];

        foreach ($this->credentials ?? [] as $key => $value) {
            $value = (string) $value;
            $masked[$key] = strlen($value) <= 4 ? str_repeat('•', strlen($value)) : str_repeat('•', 6).substr($value, -4);
        }

        return $masked;
    }
}
