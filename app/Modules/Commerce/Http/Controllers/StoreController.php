<?php

namespace App\Modules\Commerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Commerce\Enums\AvailabilityPolicy;
use App\Modules\Commerce\Enums\StoreStatus;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreDomain;
use App\Modules\Commerce\Support\StandardDeliveryBands;
use App\Modules\Commerce\Themes\Theme;
use App\Modules\Commerce\Themes\ThemeCompatibility;
use App\Modules\Commerce\Themes\ThemeRegistry;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Sales\Models\PriceGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The websites this business runs.
 *
 * ⚠ `key` and `order_prefix` are FROZEN once a store exists, and the update
 * method deliberately does not accept them. The key is what every derived
 * identifier is built from (the referral cookie, the webhook signature header,
 * the coupon storage key), so renaming it would silently invalidate live
 * cookies and detach existing webhook subscribers. The prefix is stamped into
 * order references that are already on customers' invoices and on settlement
 * lines at the payment gateway. Both are the same rule the brand `code` and the
 * supplier code already follow in this ERP.
 */
class StoreController extends Controller
{
    /**
     * Groups a public website may never be priced at. ⚠ Named by CODE because
     * the code is what the seeder fixes and the name is what somebody may
     * rename; a business that invents its own group is offered it, because
     * somebody made that group on purpose.
     */
    private const TRADE_ONLY_GROUPS = ['DEALER', 'DISTRIBUTOR', 'CORPORATE'];

    public function index(): Response
    {
        return Inertia::render('commerce/stores/index', [
            'stores' => Store::with(['brand', 'domains'])->orderBy('name')->get()
                ->map(fn (Store $store) => $this->summary($store)),
            'brands' => Brand::where('is_active', true)->orderBy('name')->get()
                ->map(fn (Brand $brand) => ['id' => $brand->id, 'name' => $brand->name]),
            'priceGroups' => $this->priceGroupOptions(),
            'availabilityPolicies' => $this->availabilityPolicyOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'key' => [
                'required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9-]{1,39}$/',
                Rule::unique('stores')->where('business_id', $this->businessId()),
            ],
            'name' => ['required', 'string', 'max:120'],
            // ⛔ Unique across EVERY business, with no scope, because LaLaDia and
            // Bionic share one payment merchant and the reference is the only
            // thing on a settlement line that says whose money it is.
            'order_prefix' => ['required', 'string', 'max:10', 'regex:/^[A-Z0-9]{2,10}$/', Rule::unique('stores', 'order_prefix')],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->where('business_id', $this->businessId())],
            // ⛔ A PRICE GROUP, NOT A CUSTOMER TYPE. Those are three different
            // concepts by Joy's instruction of 10 Aug 2026, and this column used
            // to hold the wrong one - which joined to nothing, so a store's
            // pricing silently fell back to the default. Nullable because null
            // means RETAIL, decided in one place by the price resolver.
            'price_group_id' => ['nullable', 'integer', Rule::exists('price_groups', 'id')->where('business_id', $this->businessId())],
            'availability_policy' => ['required', Rule::enum(AvailabilityPolicy::class)],
        ], [
            'key.regex' => 'The key becomes this store\'s cookie and header names: lowercase letters, digits and hyphens, e.g. bionic.',
            'order_prefix.regex' => 'The prefix is stamped on every order reference: 2 to 10 capitals or digits, e.g. BNC.',
            'order_prefix.unique' => 'Another store already uses that prefix. Prefixes are shared across the whole group because the payment merchant is.',
        ]);

        $store = Store::create($data);

        // ⭐ Joy, 13 September: a shop starts with the group's standard delivery
        // bands, changed on its Shipping screen. A store with no rates cannot
        // quote delivery, and nobody could check out until somebody noticed.
        StandardDeliveryBands::applyTo($store);

        return back()->with('success', 'Store created with the standard delivery bands. It is a draft until it has a theme and a primary domain.');
    }

    public function edit(Store $store): Response
    {
        $store->load(['brand', 'domains']);

        return Inertia::render('commerce/stores/edit', [
            'store' => array_merge($this->summary($store), [
                'referral_cookie_name' => $store->referral_cookie_name,
                'webhook_signature_header' => $store->webhook_signature_header,
                'coupon_storage_key' => $store->coupon_storage_key,
                'features' => $store->features ?? [],
            ]),
            'brands' => Brand::where('is_active', true)->orderBy('name')->get()
                ->map(fn (Brand $brand) => ['id' => $brand->id, 'name' => $brand->name]),
            'priceGroups' => $this->priceGroupOptions(),
            'availabilityPolicies' => $this->availabilityPolicyOptions(),
            'themes' => $this->themeOptions(),
            // ⭐ Computed for every theme the store is NOT already on, so the
            // screen can say what a switch would cost before somebody commits
            // to it. That is the difference between a decision and a gamble,
            // and it is the reason slice 1.2 made a theme declare capabilities.
            'themeReports' => $this->themeReports($store),
        ]);
    }

    public function update(Request $request, Store $store): RedirectResponse
    {
        // ⚠ No `key` and no `order_prefix`. See the class docblock: both are
        // frozen once anything in the outside world has seen them.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->where('business_id', $this->businessId())],
            // ⛔ A PRICE GROUP, NOT A CUSTOMER TYPE. Those are three different
            // concepts by Joy's instruction of 10 Aug 2026, and this column used
            // to hold the wrong one - which joined to nothing, so a store's
            // pricing silently fell back to the default. Nullable because null
            // means RETAIL, decided in one place by the price resolver.
            'price_group_id' => ['nullable', 'integer', Rule::exists('price_groups', 'id')->where('business_id', $this->businessId())],
            'availability_policy' => ['required', Rule::enum(AvailabilityPolicy::class)],
            // ⭐ D24: one delivery window, in hours after confirmation. A month
            // is the ceiling: a promise longer than that is not a promise.
            'delivery_promise_min_hours' => ['sometimes', 'required', 'integer', 'min:1', 'max:720'],
            'delivery_promise_max_hours' => ['sometimes', 'required', 'integer', 'gte:delivery_promise_min_hours', 'max:720'],
            // Fallbacks a page uses when it sets none of its own. ⚠ NOT
            // indexability - see `indexing()`, which is a different decision
            // behind a different permission.
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'referral_cookie_name' => ['nullable', 'string', 'max:60'],
            'webhook_signature_header' => ['nullable', 'string', 'max:60'],
            'coupon_storage_key' => ['nullable', 'string', 'max:60'],
            // ⛔ Validated against the REGISTRY, not against a list of
            // strings. A store stores only the key, so a key nothing is
            // registered under is a storefront that cannot render at all - and
            // it would fail at the visitor rather than here.
            'theme_key' => ['nullable', 'string', 'max:40', function ($attribute, $value, $fail) {
                if ($value !== null && $value !== '' && ! app(ThemeRegistry::class)->has($value)) {
                    $fail('No theme is registered under that key.');
                }
            }],
            'features' => ['nullable', 'array'],
            'features.*' => ['boolean'],
        ]);

        // ⛔⛔ THE REFUSAL. A theme that cannot draw a section on a PUBLISHED
        // page does not error when it renders: it draws nothing, the page
        // answers 200 with a hole where the hero was, and nobody finds out
        // until a customer does. So the question is asked here, once, while
        // the answer can still change something.
        $newTheme = $data['theme_key'] ?? null;

        if ($newTheme !== null && $newTheme !== '' && $newTheme !== $store->theme_key) {
            $refusals = app(ThemeCompatibility::class)
                ->refusals($store, app(ThemeRegistry::class)->get($newTheme));

            if ($refusals !== []) {
                throw ValidationException::withMessages([
                    'theme_key' => array_merge(
                        ['This theme cannot render everything on the published pages of this store. Take those pages down first, or choose another theme.'],
                        $refusals,
                    ),
                ]);
            }
        }

        $store->update($data);

        return back()->with('success', 'Store updated.');
    }

    public function addDomain(Request $request, Store $store): RedirectResponse
    {
        $data = $request->validate([
            'hostname' => [
                'required', 'string', 'max:253',
                'regex:/^(?!-)[a-zA-Z0-9-]{1,63}(?<!-)(\.(?!-)[a-zA-Z0-9-]{1,63}(?<!-))+$/',
                // ⛔ Global, and it has to be: host resolution happens before any
                // tenant context exists, so two rows claiming one hostname would
                // make the answer depend on row order.
                Rule::unique('store_domains', 'hostname'),
            ],
            'is_primary' => ['boolean'],
        ], [
            'hostname.regex' => 'Enter a hostname such as bionic.garden, with no protocol and no path.',
            'hostname.unique' => 'That hostname already belongs to a store.',
        ]);

        $store->domains()->create([
            'hostname' => $data['hostname'],
            'is_primary' => $data['is_primary'] ?? false,
        ]);

        return back()->with('success', 'Domain added.');
    }

    public function removeDomain(Store $store, StoreDomain $domain): RedirectResponse
    {
        abort_unless($domain->store_id === $store->id, 404);

        // ⛔ A live store must not lose the hostname it is being served on. The
        // refusal is here rather than in the UI because the UI is not the only
        // thing that can post to this route.
        if ($domain->is_primary && $store->status === StoreStatus::Live) {
            throw ValidationException::withMessages([
                'hostname' => 'This is the primary domain of a live store. Take the store offline, or make another domain primary first.',
            ]);
        }

        $domain->delete();

        return back()->with('success', 'Domain removed.');
    }

    /**
     * ⛔ Its own permission, and only the Owner holds it. Taking a store live
     * points a real domain at real software and starts taking money from the
     * public, which is a different decision from renaming it.
     */
    public function publish(Store $store): RedirectResponse
    {
        if (! $store->canGoLive()) {
            throw ValidationException::withMessages([
                'status' => 'A store needs a theme and a primary domain before it can go live. There would be nothing to render.',
            ]);
        }

        $store->status = StoreStatus::Live;
        $store->save();

        return back()->with('success', 'Store is live.');
    }

    public function unpublish(Store $store): RedirectResponse
    {
        $store->status = StoreStatus::Draft;
        $store->save();

        return back()->with('success', 'Store taken offline.');
    }

    /**
     * Whether search engines may index this shop.
     *
     * ⭐⭐ BEHIND `commerce.stores.publish`, NOT the ordinary settings
     * permission, because it is the same class of act as taking a store live:
     * the moment the whole internet may find it. And it is the harder of the two
     * to undo - a preview shop indexed under the company's name is removed by
     * asking a third party, long after the wrong URLs entered search results.
     *
     * ⚠ `forceFill`, because `is_indexable` is deliberately not fillable: this
     * is a decision somebody takes, never a field a settings form posts.
     */
    public function indexing(Request $request, Store $store): RedirectResponse
    {
        $this->authorize('commerce.stores.publish');

        $data = $request->validate(['is_indexable' => ['required', 'boolean']]);

        $store->forceFill(['is_indexable' => $data['is_indexable']])->save();

        return back()->with('success', $data['is_indexable']
            ? 'Search engines may now index this shop.'
            : 'This shop is hidden from search engines again. Anything already indexed has to be removed through the search engine itself.');
    }

    private function summary(Store $store): array
    {
        return [
            'id' => $store->id,
            'key' => $store->key,
            'name' => $store->name,
            'order_prefix' => $store->order_prefix,
            'brand_id' => $store->brand_id,
            'brand_name' => $store->brand?->name,
            'price_group_id' => $store->price_group_id,
            'price_group_name' => $store->priceGroup?->name ?? 'Retail (default)',
            'availability_policy' => $store->availability_policy->value,
            'delivery_promise_min_hours' => (int) $store->delivery_promise_min_hours,
            'delivery_promise_max_hours' => (int) $store->delivery_promise_max_hours,
            'theme_key' => $store->theme_key,
            'theme_name' => $store->theme_key !== null && app(ThemeRegistry::class)->has($store->theme_key)
                ? app(ThemeRegistry::class)->get($store->theme_key)->name()
                // ⚠ Says so rather than showing a blank: a store pointing at a
                // theme that is no longer registered is a real state after a
                // deploy, and it must be visible.
                : ($store->theme_key !== null ? $store->theme_key.' (not registered)' : null),
            'seo_title' => $store->seo_title,
            'seo_description' => $store->seo_description,
            'is_indexable' => $store->is_indexable,
            'status' => $store->status->value,
            'status_label' => $store->status->label(),
            'can_go_live' => $store->canGoLive(),
            // ⭐ Shown on the screen because "what will this store call its own
            // cookie" should be visible before it is live, not discovered from a
            // browser inspector afterwards.
            'derived' => [
                'referral_cookie' => $store->referralCookieName(),
                'webhook_header' => $store->webhookSignatureHeader(),
                'coupon_key' => $store->couponStorageKey(),
            ],
            'domains' => $store->domains->map(fn (StoreDomain $domain) => [
                'id' => $domain->id,
                'hostname' => $domain->hostname,
                'is_primary' => $domain->is_primary,
            ])->values(),
        ];
    }

    /**
     * What switching to each other theme would do to this store.
     *
     * ⚠ Only themes the store is not already on. Reporting on the current one
     * would be reporting on the state it is already in, and a screen that says
     * "your current theme is compatible with your current pages" teaches the
     * reader to ignore the panel.
     *
     * @return array<int, array<string, mixed>>
     */
    private function themeReports(Store $store): array
    {
        $compatibility = app(ThemeCompatibility::class);

        return collect(app(ThemeRegistry::class)->all())
            ->reject(fn (Theme $theme) => $theme->key() === $store->theme_key)
            ->map(fn (Theme $theme) => $compatibility->report($store, $theme))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{value: string, label: string, description: string}>
     */
    private function themeOptions(): array
    {
        return array_map(fn (Theme $theme) => [
            'value' => $theme->key(),
            'label' => $theme->name(),
            'description' => $theme->description(),
        ], app(ThemeRegistry::class)->all());
    }

    /**
     * ⚠ Websites are B2C only (ADR-005), so the trade tiers are not offered here
     * at all. Wholesale and dealer selling stays manual in the ERP, and a
     * dropdown offering "dealer" would invite somebody to put trade pricing on a
     * public website.
     *
     * ⚠ Real price-group rows now, not customer types. The two are different
     * concepts and this list used to hold the wrong one, which joined to nothing
     * - so whatever was chosen here, the storefront would have quoted the
     * default price.
     */
    private function priceGroupOptions(): array
    {
        return PriceGroup::query()
            ->where('is_active', true)
            ->whereNotIn('code', self::TRADE_ONLY_GROUPS)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (PriceGroup $group) => ['value' => $group->id, 'label' => $group->name])
            ->values()->all();
    }

    private function availabilityPolicyOptions(): array
    {
        return collect(AvailabilityPolicy::cases())
            ->map(fn (AvailabilityPolicy $policy) => ['value' => $policy->value, 'label' => $policy->label()])
            ->values()->all();
    }

    private function businessId(): ?int
    {
        return app(BusinessContext::class)->id();
    }
}
