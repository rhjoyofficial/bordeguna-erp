<?php

namespace App\Modules\Assets\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One login a thing or an account carries (asset register slice 3, 13 October
 * 2026): "Windows login", "Router admin", "cPanel".
 *
 * ⚠⚠ `$hidden` IS LOAD-BEARING, exactly as on AiCredential. Every Inertia prop
 * is a serialised model or array, so an attribute that survives `toArray()`
 * reaches the browser. The only way to read the secret is to ask for it by
 * name in server code, and the only server code that does is the reveal.
 *
 * ⚠ The activity log records that a login was added, changed or removed, with
 * its label and username, and NEVER its secret: `logOnly` lists the safe
 * columns, so `secret` and `hint` cannot reach a table built for reading.
 * Each reveal is logged separately by the controller ("X revealed ...").
 */
class AssetCredential extends Model
{
    use BelongsToBusiness, LogsActivity;

    protected $fillable = [
        'business_id',
        'credentialable_type',
        'credentialable_id',
        'label',
        'username',
        'secret',
        'hint',
        'host_or_url',
        'note',
        'created_by',
        'updated_by',
        'last_revealed_at',
        'last_revealed_by',
    ];

    /**
     * ⚠⚠ Removing `secret` from here exposes the password to the browser.
     */
    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'last_revealed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        // ⛔ Never `secret`, never `hint`. The log is read by anyone who may see
        // the thing's history.
        return LogOptions::defaults()
            ->useLogName('assets.secrets')
            ->logOnly(['label', 'username', 'host_or_url'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $event) => match ($event) {
                'created' => 'Login added: '.$this->label,
                'updated' => 'Login changed: '.$this->label,
                'deleted' => 'Login removed: '.$this->label,
                default => $event,
            });
    }

    /**
     * The last four characters, and nothing else, ever. Stored at save time so
     * a list never decrypts anything merely to draw itself.
     */
    public static function hintFor(string $secret): string
    {
        return mb_substr($secret, -4);
    }

    public function credentialable(): MorphTo
    {
        return $this->morphTo();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lastRevealedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_revealed_by');
    }

    /**
     * What a screen may know about this login. An explicit whitelist, so a
     * column added later is invisible until somebody decides it should be seen.
     *
     * @return array<string, mixed>
     */
    public function toSafeArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'username' => $this->username,
            'host_or_url' => $this->host_or_url,
            'hint' => $this->hint,
            'note' => $this->note,
            'last_revealed_at' => $this->last_revealed_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
            'last_revealed_by' => $this->lastRevealedBy?->name,
        ];
    }
}
