<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Modules\Foundation\Corrections\Contracts\Removable;
use App\Modules\Foundation\Corrections\Owners;
use App\Modules\Foundation\Corrections\Recycle\Recyclable;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Notifications\SetPasswordNotification;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\UserUsage;
use App\Modules\Hrm\Models\Employee;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * ⭐ Corrections wave 3, B11 (24 Oct 2026): a login nobody ever used can go to
 * the Recycle bin (Delete login, FINAL-PLAN 3.5); every other login is
 * deactivated. A soft-deleted user cannot sign in (the auth provider reads
 * through the soft-delete scope) and keeps its email reserved (the unique
 * index keeps the row). `first_signed_in_at` is written by the Login listener.
 */
class User extends Authenticatable implements Removable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, Recyclable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_super_admin',
        'is_active',
        'receives_hr_notifications',
        // C-I18N-1: the person's own UI language, 'en' or 'bn'.
        'locale',
    ];

    /**
     * ⚠ The set-password email in this ERP's own words (21 Sep 2026). Laravel's
     * stock message says "we received a password reset request" and gives the
     * lifetime in minutes, which reads as an attack to somebody who has just
     * been invited.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new SetPasswordNotification($token));
    }

    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class, 'business_user')->withTimestamps();
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'is_active' => 'boolean',
            'receives_hr_notifications' => 'boolean',
            // Personal settings that follow the person (dashboard sound first).
            'preferences' => 'array',
            'first_signed_in_at' => 'datetime',
            'pending_email_sent_at' => 'datetime',
        ];
    }

    /** Has this login ever been used to sign in? */
    public function hasSignedIn(): bool
    {
        return $this->first_signed_in_at !== null || (bool) $this->is_super_admin;
    }

    /** What the Recycle bin calls it. */
    public function binLabel(): string
    {
        return "Login {$this->name} ({$this->email})";
    }

    /**
     * Delete login: only one that never signed in, belongs to this business
     * alone, is nobody's employee login and nothing points at (FINAL-PLAN 3.5,
     * 6.4). Everyone else is deactivated.
     */
    public function whyNotRemovable(): ?string
    {
        if ($this->hasSignedIn()) {
            return "{$this->name} has signed in before, so the login cannot be deleted. Deactivate it instead.";
        }

        if ($this->businesses()->count() > 1) {
            return "{$this->name} also belongs to another business. Remove them from this team instead.";
        }

        if (($employee = Employee::query()->withoutGlobalScopes()->where('user_id', $this->id)->first()) !== null) {
            return "This is the login of employee {$employee->fullName()}. Fix its email, or deactivate it.";
        }

        if (($where = app(UserUsage::class)->firstUse($this)) !== null) {
            return "Records point at this login ({$where}). Deactivate it instead.";
        }

        return null;
    }

    /** The last active Owner of a business is never switched off. */
    public function whyNotDeactivatable(): ?string
    {
        if ($this->is_active && Owners::is($this) && self::role('Owner')->where('is_active', true)->whereKeyNot($this->id)->doesntExist()) {
            return "{$this->name} is the only active Owner. Make someone else an Owner first.";
        }

        return null;
    }

    /** A login comes back only if its email is still free among live logins. */
    public function whyNotRestorable(): ?string
    {
        if (self::query()->where('email', $this->email)->whereKeyNot($this->id)->exists()) {
            return "Another login now uses {$this->email}.";
        }

        return null;
    }

    /**
     * The Recycle bin of one business shows the logins of its own team only
     * (users are shared across the group; RecycleBin asks for this scope).
     */
    public function scopeVisibleInBin(Builder $query): Builder
    {
        $businessId = app(BusinessContext::class)->id();

        return $query->whereHas('businesses', fn (Builder $q) => $q->where('businesses.id', $businessId));
    }

    /**
     * The picture shown for this user everywhere: the sidebar, the user menu,
     * chat, the assistant panel. It IS the linked employee's HR photo - one
     * picture per person, uploaded once, so HR's employee page and the
     * person's own profile can never disagree.
     *
     * ⚠ Null when no employee record is linked to this login, or when the
     * current business has no photo for them (attachments are business-scoped).
     * The screen falls back to initials in that case; it must never 500 here
     * because this runs on every Inertia response.
     */
    public function avatarUrl(): ?string
    {
        $employee = Employee::forUser($this);

        return $employee?->photo()?->url();
    }
}
