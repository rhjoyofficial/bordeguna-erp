<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Modules\Foundation\Models\Business;
use App\Modules\Hrm\Models\Employee;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

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
        ];
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
