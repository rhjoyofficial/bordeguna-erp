<?php

namespace App\Modules\Foundation\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An AI provider's API key, encrypted at rest and never readable by the client.
 *
 * ⚠⚠ `$hidden` IS LOAD-BEARING, NOT TIDINESS. Every Inertia prop in this
 * application is a serialised model or array, so an attribute that survives
 * `toArray()` reaches the browser. Hiding `secret` here means the only way to
 * read the key is to ask for it by name in server code, which is exactly the
 * one place that should be able to.
 *
 * ⚠ The `encrypted` cast means the column holds ciphertext. A database dump,
 * a `SELECT *` in a console, or a replica handed to somebody else all show
 * noise rather than a working key. The plaintext exists only in memory, for
 * the length of one request, in `AiCredentialResolver`.
 *
 * ⚠ No `activity_log` here on purpose. Every other master-data model logs its
 * changes, and a diff of this one would write the old and new secret into a
 * table built for reading. The audit answer for a credential is `updated_at`
 * and `created_by`, which say who changed it and when without saying to what.
 */
class AiCredential extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'label',
        'model',
        'secret',
        'hint',
        'is_active',
        'verified_at',
        'verify_error',
        'created_by',
    ];

    /**
     * ⚠⚠ Removing `secret` from here exposes the key to the browser. It is the
     * single line standing between an encrypted column and an Inertia page.
     */
    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'is_active' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The last four characters, and nothing else, ever.
     *
     * ⚠ Called once at save time and stored. Deriving it on read would mean
     * decrypting every secret merely to draw a list, which is the habit that
     * turns a leak from unlikely into routine.
     */
    public static function hintFor(string $secret): string
    {
        return substr(trim($secret), -4);
    }

    /**
     * What the screen is allowed to know about this credential.
     *
     * ⚠ Written as an explicit whitelist rather than `except('secret')`, so a
     * column added later is invisible until somebody decides it should be seen.
     */
    public function toSafeArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'label' => $this->label,
            'model' => $this->model,
            'hint' => $this->hint,
            'is_active' => $this->is_active,
            'verified_at' => $this->verified_at?->toDateTimeString(),
            'verify_error' => $this->verify_error,
            'updated_at' => $this->updated_at?->toDateTimeString(),
            'created_by' => $this->creator?->name,
        ];
    }
}
