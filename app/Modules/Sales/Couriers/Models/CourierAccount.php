<?php

namespace App\Modules\Sales\Couriers\Models;

use App\Models\User;
use App\Modules\Sales\Couriers\Providers\CourierProviderDefinition;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One merchant account at one courier, in one environment.
 *
 * ⚠⚠ `credentials` AND `webhook_secret` ARE ENCRYPTED BY THE CAST, HIDDEN FROM
 * SERIALISATION, KEPT OUT OF THE ACTIVITY LOG, AND NEVER SENT TO A BROWSER. A
 * screen learns only whether each field is set.
 *
 * ⚠⚠ NOT BUSINESS-SCOPED. One Pathao merchant account serves LaLaDia and Bionic.
 * The business, its pickup store and its courier money account live on
 * CourierAccountBusiness (ChatGPT ruling 11).
 *
 * ⚠⚠ AN ACTIVE ACCOUNT ALWAYS PASSED A TEST WITH WHAT IT HOLDS NOW. The test
 * records a keyed fingerprint of the credentials, environment and URL; any save
 * that changes one of those switches the account off (see `booted`), so a new
 * password typed on Friday is never trusted because the old one worked on Monday.
 */
class CourierAccount extends Model
{
    use LogsActivity;

    public const WEBHOOK_PATH = '/webhooks/couriers/';

    protected $fillable = [
        'courier_provider_id', 'label', 'environment', 'base_url', 'credentials', 'options', 'integration_secret',
        'is_active', 'activated_at', 'created_by',
    ];

    protected $hidden = ['credentials', 'webhook_secret', 'integration_secret', 'tested_fingerprint'];

    /** Created switched off; a test then an explicit Activate turns it on. */
    protected $attributes = ['is_active' => false];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'webhook_secret' => 'encrypted',
            'integration_secret' => 'encrypted',
            'auto_post_outcomes' => 'boolean',
            'auto_post_switched_off_at' => 'datetime',
            'options' => 'array',
            'is_active' => 'boolean',
            'activated_at' => 'datetime',
            'last_test_at' => 'datetime',
            'last_test_ok' => 'boolean',
            'last_test_stores' => 'array',
            'last_test_city_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $account) {
            $account->uuid ??= (string) Str::uuid();

            if ($account->getAttributes()['webhook_secret'] ?? null) {
                return;
            }

            $account->webhook_secret = self::newWebhookSecret();
        });

        // ⚠ The invariant, enforced where every writer passes: the screen, the
        // import command, tinker. An active account whose credentials, URL or
        // environment no longer match what was tested is switched off.
        static::saving(function (self $account) {
            if ($account->is_active && ! $account->testIsCurrent()) {
                $account->is_active = false;
                $account->activated_at = null;
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        // ⚠⚠ logOnly, never logAll or logFillable: `credentials` is fillable.
        return LogOptions::defaults()
            ->logOnly(['label', 'environment', 'base_url', 'options', 'is_active', 'last_test_ok', 'auto_post_outcomes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('couriers');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(CourierProvider::class, 'courier_provider_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function businessLinks(): HasMany
    {
        return $this->hasMany(CourierAccountBusiness::class);
    }

    public function apiCalls(): HasMany
    {
        return $this->hasMany(CourierApiCall::class);
    }

    public function definition(): CourierProviderDefinition
    {
        return $this->provider->definition();
    }

    public static function newWebhookSecret(): string
    {
        return Str::random(48);
    }

    /**
     * The credentials, or an empty array when none are saved or they no longer
     * decrypt (a database restored under another APP_KEY reads as "not set").
     *
     * @return array<string,string>
     */
    public function credentialValues(): array
    {
        if ($this->getRawOriginal('credentials') === null && ! $this->isDirty('credentials')) {
            return [];
        }

        try {
            $values = $this->credentials;
        } catch (DecryptException) {
            return [];
        }

        return is_array($values) ? array_map(fn ($v) => (string) $v, $values) : [];
    }

    public function credential(string $key): ?string
    {
        $value = $this->credentialValues()[$key] ?? null;

        return $value === null || $value === '' ? null : $value;
    }

    /**
     * Which fields hold a value. The only thing about credentials a screen gets.
     *
     * @return array<string,bool>
     */
    public function credentialsSet(): array
    {
        $values = $this->credentialValues();
        $set = [];

        foreach ($this->definition()->credentialKeys() as $key) {
            $set[$key] = ($values[$key] ?? '') !== '';
        }

        return $set;
    }

    public function hasAllRequiredCredentials(): bool
    {
        $values = $this->credentialValues();

        foreach ($this->definition()->credentialFields() as $field) {
            if ($field['required'] && ($values[$field['key']] ?? '') === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * For a console that must show something: the last four characters only.
     *
     * @return array<string,string>
     */
    public function maskedCredentials(): array
    {
        return array_map(fn (string $v) => self::mask($v), $this->credentialValues());
    }

    public static function mask(string $value): string
    {
        return strlen($value) <= 4 ? str_repeat('*', strlen($value)) : str_repeat('*', 6).substr($value, -4);
    }

    /**
     * Every secret value this account holds, for scrubbing anything about to be
     * written to a log (a provider can echo a field back in an error).
     *
     * @return list<string>
     */
    public function secretValues(): array
    {
        $values = array_values(array_filter($this->credentialValues(), fn ($v) => strlen($v) >= 4));

        try {
            $webhook = $this->webhook_secret;
        } catch (DecryptException) {
            $webhook = null;
        }

        if (is_string($webhook) && $webhook !== '') {
            $values[] = $webhook;
        }

        $echo = $this->integrationSecret();

        if ($echo !== null && strlen($echo) >= 4) {
            $values[] = $echo;
        }

        return $values;
    }

    /**
     * A keyed hash of what a test proves: these credentials, at this URL, in
     * this environment. Keyed with APP_KEY so the stored value says nothing
     * about the secrets themselves.
     */
    public function currentFingerprint(): string
    {
        $credentials = $this->credentialValues();
        ksort($credentials);

        return hash_hmac('sha256', json_encode([$this->environment, $this->base_url, $credentials]), (string) config('app.key'));
    }

    public function testIsCurrent(): bool
    {
        return $this->last_test_ok === true
            && $this->tested_fingerprint !== null
            && hash_equals($this->tested_fingerprint, $this->currentFingerprint());
    }

    public function canActivate(): bool
    {
        return $this->definition()->connected() && $this->testIsCurrent();
    }

    /**
     * Pathao's account-level value the ERP echoes on every webhook answer, or
     * null when none is saved (or it no longer decrypts).
     */
    public function integrationSecret(): ?string
    {
        if ($this->getRawOriginal('integration_secret') === null && ! $this->isDirty('integration_secret')) {
            return null;
        }

        try {
            $value = $this->integration_secret;
        } catch (DecryptException) {
            return null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function webhookSecretValue(): ?string
    {
        try {
            $value = $this->webhook_secret;
        } catch (DecryptException) {
            return null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Every link of this account in one business: one per brand it serves, and
     * at most one for any brand (Joy, 11 Oct 2026).
     *
     * @return Collection<int, CourierAccountBusiness>
     */
    public function linksFor(int $businessId): Collection
    {
        return CourierAccountBusiness::query()->withoutGlobalScopes()
            ->where('courier_account_id', $this->id)->where('business_id', $businessId)
            ->orderByRaw('brand_id IS NULL')->orderBy('id')
            ->get();
    }

    /**
     * The link that serves an order of this brand in this business: the
     * brand's own link, else the any-brand one. With no brand given (an order
     * from before brands were recorded): the any-brand link, else the only
     * link there is. Null when none serves it.
     */
    public function linkFor(int $businessId, ?int $brandId = null): ?CourierAccountBusiness
    {
        return CourierAccountBusiness::serving($this->linksFor($businessId), $brandId);
    }

    public function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/').self::WEBHOOK_PATH.$this->uuid;
    }

    /**
     * @return list<array{id:string,name:string,active:bool}>
     */
    public function testedStores(): array
    {
        return $this->testIsCurrent() ? array_values($this->last_test_stores ?? []) : [];
    }
}
