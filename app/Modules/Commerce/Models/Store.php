<?php

namespace App\Modules\Commerce\Models;

use App\Modules\Commerce\Enums\AvailabilityPolicy;
use App\Modules\Commerce\Enums\StoreStatus;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Scopes\BusinessScope;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Sales\Models\PriceGroup;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One website managed from the ERP.
 *
 * ⚠⚠ THE THING TO UNDERSTAND FIRST: a store is a sales CHANNEL, not a tenant
 * and not a brand. The business is the tenant. The brand is what the goods are
 * sold under. Econic sells LaLaDia and Bionic products from one store, so its
 * brand is null; laladia.com sells one brand and points at it.
 *
 * ⚠ Business-scoped, so `BusinessScope` fails closed on it exactly as it does
 * for brands and categories. The one place that deliberately steps outside that
 * is `resolveByHost`, and the reason is written on that method.
 */
class Store extends Model
{
    use BelongsToBusiness, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'brand_id',
        'key',
        'name',
        'order_prefix',
        'price_group_id',
        'availability_policy',
        'theme_key',
        'seo_title',
        'seo_description',
        'features',
        'referral_cookie_name',
        'webhook_signature_header',
        'coupon_storage_key',
        // ⚠ `status` is deliberately absent. Taking a store live is not a text
        // field on a settings form: it is the moment a real hostname starts
        // serving the public. It moves through the explicit methods below, so
        // no request path can ever mass-assign a store into production.
    ];

    protected function casts(): array
    {
        return [
            'status' => StoreStatus::class,
            'availability_policy' => AvailabilityPolicy::class,
            'features' => 'array',
            'is_indexable' => 'boolean',
        ];
    }

    /**
     * ⚠ Mirrors the database defaults in the model, as the standing rule in this
     * repository requires. A column default only applies once the row is
     * WRITTEN, so a freshly created Store would report `status` as null while
     * the stored row said 'draft', and code asking `$store->status->isServable()`
     * immediately after creating it would fatal on null.
     */
    protected $attributes = [
        'status' => 'draft',
        // ⚠ Invisible until somebody says otherwise, mirrored from the column
        // default per this repository's standing rule: a default only applies
        // once the row is written, and code asking a fresh Store whether it is
        // indexable must not read null.
        'is_indexable' => false,
        'availability_policy' => 'status_based',
    ];

    /**
     * ⚠⚠ NULL IS NOT "UNSET", IT IS RETAIL. `customers` already works this way
     * and the mapping from "no group" to RETAIL lives in ONE place, the
     * selling-price resolver. A default here would be a second opinion, and two
     * opinions about what a visitor pays is how a shop charges the wrong amount
     * without anybody seeing an error.
     */
    public function priceGroup(): BelongsTo
    {
        return $this->belongsTo(PriceGroup::class, 'price_group_id');
    }

    /**
     * This store's own image library. ⚠ Store-scoped by construction, which is
     * the point: a section or a product picture may only ever come from here,
     * so one shop cannot end up showing another's photography.
     */
    public function media(): HasMany
    {
        return $this->hasMany(StoreMedia::class);
    }

    /**
     * The catalogue published to this store. ⛔ Publishing does not fork the
     * product master: a row here says a product APPEARS here and how it is
     * presented, never what it is or what it costs.
     */
    public function products(): HasMany
    {
        return $this->hasMany(StoreProduct::class);
    }

    /** Delivery bands this store charges (slice 2.3). See `DeliveryQuote`. */
    public function shippingZones(): HasMany
    {
        return $this->hasMany(ShippingZone::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Which district pays which band. A district not listed pays the catch-all. */
    public function districtShippingZones(): HasMany
    {
        return $this->hasMany(DistrictShippingZone::class);
    }

    /** Coupons this store offers (slice 2.5b). See `StoreCoupon`. */
    public function coupons(): HasMany
    {
        return $this->hasMany(StoreCoupon::class);
    }

    /** Bundles this store sells (D25). See `StoreCombo`. */
    public function combos(): HasMany
    {
        return $this->hasMany(StoreCombo::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(StoreDomain::class);
    }

    public function primaryDomain(): HasMany
    {
        return $this->domains()->where('is_primary', true);
    }

    /**
     * Find the store that answers on this hostname.
     *
     * ⛔ THIS IS THE ONE PLACE THAT LEAVES THE BUSINESS SCOPE, and it has to.
     * The storefront resolves an incoming request by Host header BEFORE it knows
     * which business it is serving, so there is no tenant context to scope by
     * yet. `withoutGlobalScope` is used explicitly rather than by branching on a
     * role, so that every cross-tenant read in this codebase stays greppable.
     *
     * ⚠ Returns the store whatever its status. Deciding whether a draft store
     * may be served to the public is the caller's job, because a preview request
     * from an administrator legitimately serves one and an anonymous request
     * must not. Answering null here would make a preview impossible to build.
     */
    public static function resolveByHost(string $host): ?self
    {
        $hostname = StoreDomain::normaliseHostname($host);

        if ($hostname === '') {
            return null;
        }

        $domain = StoreDomain::query()->where('hostname', $hostname)->first();

        if ($domain === null) {
            return null;
        }

        return self::query()
            ->withoutGlobalScope(BusinessScope::class)
            ->find($domain->store_id);
    }

    /**
     * The referral cookie name for this store.
     *
     * ⛔ Derived from the key unless an override is stored. That is the whole
     * defence against the defect that made bionic.garden report into LaLaDia's
     * pixel: a new store cannot inherit an old one's identifier by doing
     * nothing, because doing nothing produces its own name.
     *
     * The override exists only for the original shop, whose live cookies already
     * carry the legacy value and whose commission tracking would break if it
     * changed underneath them.
     */
    public function referralCookieName(): string
    {
        return $this->referral_cookie_name ?: $this->key.'_ref';
    }

    public function webhookSignatureHeader(): string
    {
        return $this->webhook_signature_header ?: 'X-'.str($this->key)->studly().'-Signature';
    }

    public function couponStorageKey(): string
    {
        return $this->coupon_storage_key ?: $this->key.'_coupon';
    }

    /**
     * Is a whole feature switched on for this store?
     *
     * ⚠ Defaults to FALSE for an unknown feature rather than true. A feature
     * nobody has decided about must not appear on a live shop because a key was
     * spelled differently in two places.
     */
    public function hasFeature(string $feature): bool
    {
        return (bool) data_get($this->features ?? [], $feature, false);
    }

    /**
     * ⛔ A store cannot go live without a theme. There would be nothing to
     * render, and the failure would reach a visitor rather than the person who
     * pressed the button.
     */
    public function canGoLive(): bool
    {
        return $this->theme_key !== null
            && $this->domains()->where('is_primary', true)->exists();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['key', 'name', 'order_prefix', 'price_group', 'availability_policy', 'theme_key', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
