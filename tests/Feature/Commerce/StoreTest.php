<?php

use App\Modules\Commerce\Enums\AvailabilityPolicy;
use App\Modules\Commerce\Enums\StoreStatus;
use App\Modules\Commerce\Models\Store;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Database\QueryException;

/**
 * Phase 1, slice 1.1: the store and domain spine.
 *
 * A store is one website. The two things this slice has to get right are that a
 * store belongs to exactly one business and cannot be read from another, and
 * that an incoming hostname resolves to exactly one store BEFORE any tenant
 * context exists. Everything else in the programme sits on those two.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna']);
    $this->other = Business::create(['name' => 'Another Co', 'slug' => 'another-co']);

    app(BusinessContext::class)->set($this->business->id);
});

function makeStore(array $attributes = []): Store
{
    return Store::create(array_merge([
        'key' => 'bionic',
        'name' => 'Bionic Garden',
        'order_prefix' => 'BNC',
    ], $attributes));
}

it('carries its database defaults the moment it is created, without a reload', function () {
    $store = makeStore();

    // ⚠ The standing rule in this repository, and it is not decoration: a
    // column default only applies once the row is written, so without the
    // model-side defaults this store would report null and any caller asking
    // `$store->status->isServable()` would fatal on null immediately after
    // creating it.
    expect($store->status)->toBe(StoreStatus::Draft)
        ->and($store->availability_policy)->toBe(AvailabilityPolicy::StatusBased);

    // ⛔⛔ THIS ASSERTION USED TO READ `price_group === CustomerType::Retail`,
    // and it was pinning a defect rather than a rule. Slice 1.1 stored a
    // CUSTOMER TYPE in a column called price group, which joins to nothing:
    // prices resolve through `product_prices.price_group_id`, so whatever was
    // chosen the storefront would have quoted the default price with no error
    // anywhere. Corrected in slice 1.7, where that price is first read.
    //
    // ⚠ Null is not "unset", it is RETAIL - the same rule `customers` already
    // follows, decided in one place by the selling-price resolver.
    expect($store->price_group_id)->toBeNull();
});

it('defaults the web to selling at zero, because the goods are made to order', function () {
    expect(makeStore()->availability_policy->allowsSaleAtZero())->toBeTrue();
    expect(AvailabilityPolicy::Strict->allowsSaleAtZero())->toBeFalse();
});

it('hides a store from another business', function () {
    makeStore();

    app(BusinessContext::class)->set($this->other->id);

    expect(Store::count())->toBe(0);
});

it('returns no stores at all when there is no business context, rather than every store', function () {
    makeStore();

    app(BusinessContext::class)->forget();

    // Fails closed. A forgotten context in a queued job or a console command
    // must return nothing, never one business's data to another.
    expect(Store::count())->toBe(0);
});

it('refuses to let two stores share an order prefix, even across businesses', function () {
    makeStore(['key' => 'laladia', 'name' => 'LaLaDia', 'order_prefix' => 'LLD']);

    app(BusinessContext::class)->set($this->other->id);

    // ⛔ Both shops share one SSLCommerz merchant, so the reference is the only
    // thing on a settlement line that says whose money it is. Per-business
    // uniqueness would not be enough.
    expect(fn () => makeStore(['key' => 'laladia', 'name' => 'Copy', 'order_prefix' => 'LLD']))
        ->toThrow(QueryException::class);
});

it('resolves a store from its hostname with no business context set', function () {
    $store = makeStore();
    $store->domains()->create(['hostname' => 'bionic.garden', 'is_primary' => true]);

    // The storefront has no tenant context at this point in a request: it has a
    // Host header and nothing else. This is the one deliberate cross-business
    // read in the module.
    app(BusinessContext::class)->forget();

    expect(Store::resolveByHost('bionic.garden')?->id)->toBe($store->id);
});

it('resolves a hostname whatever case, port or trailing dot it arrives with', function () {
    $store = makeStore();
    $store->domains()->create(['hostname' => 'Bionic.Garden', 'is_primary' => true]);

    app(BusinessContext::class)->forget();

    // A trailing dot is a legal fully qualified hostname and some clients send
    // it. Untreated it would 404 for exactly those visitors, which is the kind
    // of fault that never reproduces on a developer's machine.
    expect(Store::resolveByHost('bionic.garden')?->id)->toBe($store->id)
        ->and(Store::resolveByHost('BIONIC.GARDEN')?->id)->toBe($store->id)
        ->and(Store::resolveByHost('bionic.garden:8443')?->id)->toBe($store->id)
        ->and(Store::resolveByHost('bionic.garden.')?->id)->toBe($store->id);
});

it('answers with nothing for a hostname it does not serve', function () {
    makeStore();

    expect(Store::resolveByHost('not-ours.example'))->toBeNull()
        ->and(Store::resolveByHost(''))->toBeNull();
});

it('will not let two stores claim the same hostname', function () {
    $bionic = makeStore();
    $bionic->domains()->create(['hostname' => 'bionic.garden', 'is_primary' => true]);

    $laladia = makeStore(['key' => 'laladia', 'name' => 'LaLaDia', 'order_prefix' => 'LLD']);

    expect(fn () => $laladia->domains()->create(['hostname' => 'bionic.garden']))
        ->toThrow(QueryException::class);
});

it('keeps exactly one primary hostname, demoting the old one when a new one is promoted', function () {
    $store = makeStore();
    $old = $store->domains()->create(['hostname' => 'bionic-preview.bordeguna.com', 'is_primary' => true]);
    $new = $store->domains()->create(['hostname' => 'bionic.garden', 'is_primary' => true]);

    // Demoting rather than refusing is deliberate: promoting a new hostname is
    // exactly how a store moves from its preview domain to its real one.
    expect($store->domains()->where('is_primary', true)->count())->toBe(1)
        ->and($new->fresh()->is_primary)->toBeTrue()
        ->and($old->fresh()->is_primary)->toBeFalse();
});

it('derives its own identifiers from its key so a new store cannot inherit another shop is', function () {
    $store = makeStore();

    // ⛔ This is the defence against the defect that had bionic.garden reporting
    // into LaLaDia's pixel and pointing crawlers at laladia.com's sitemap. Doing
    // nothing produces this store's own name, never the other shop's.
    expect($store->referralCookieName())->toBe('bionic_ref')
        ->and($store->webhookSignatureHeader())->toBe('X-Bionic-Signature')
        ->and($store->couponStorageKey())->toBe('bionic_coupon');
});

it('lets the original shop keep the legacy identifier its live cookies already carry', function () {
    $store = makeStore([
        'key' => 'laladia',
        'name' => 'LaLaDia',
        'order_prefix' => 'LLD',
        'referral_cookie_name' => 'laladia_ref',
        'webhook_signature_header' => 'X-Laladia-Signature',
    ]);

    expect($store->referralCookieName())->toBe('laladia_ref')
        ->and($store->webhookSignatureHeader())->toBe('X-Laladia-Signature');
});

it('treats an undecided feature as off', function () {
    $store = makeStore(['features' => ['wishlist' => true]]);

    expect($store->hasFeature('wishlist'))->toBeTrue()
        // A feature nobody has decided about must not appear on a live shop
        // because a key was spelled differently in two places.
        ->and($store->hasFeature('gift_wrap'))->toBeFalse()
        ->and(makeStore(['key' => 'econic', 'name' => 'Econic', 'order_prefix' => 'ECN'])->hasFeature('wishlist'))->toBeFalse();
});

it('refuses to call a store launchable with no theme or no primary hostname', function () {
    $store = makeStore();

    expect($store->canGoLive())->toBeFalse();

    $store->update(['theme_key' => 'bionic-classic']);
    expect($store->fresh()->canGoLive())->toBeFalse();

    $store->domains()->create(['hostname' => 'bionic.garden', 'is_primary' => true]);
    expect($store->fresh()->canGoLive())->toBeTrue();
});

it('cannot be pushed live by mass assignment', function () {
    // ⛔ Taking a store live points a real domain at real software and starts
    // taking money from the public. It must not be reachable from a settings
    // form that happens to post an extra field.
    $store = makeStore(['status' => StoreStatus::Live->value]);

    expect($store->status)->toBe(StoreStatus::Draft);
});

it('is a channel and not a brand, so a multi-brand store carries no brand', function () {
    $econic = makeStore(['key' => 'econic', 'name' => 'Econic', 'order_prefix' => 'ECN']);

    // Econic sells LaLaDia products and Bionic products from one store. A store
    // that had to name one brand could not express that, which is why brand_id
    // is nullable rather than required.
    expect($econic->brand_id)->toBeNull()
        ->and($econic->brand)->toBeNull();
});
