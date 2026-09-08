<?php

namespace App\Modules\Commerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Commerce\Enums\PageType;
use App\Modules\Commerce\Models\Page;
use App\Modules\Commerce\Models\PageSection;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreMedia;
use App\Modules\Commerce\Sections\SectionType;
use App\Modules\Commerce\Sections\SectionTypeRegistry;
use App\Modules\Commerce\Themes\Theme;
use App\Modules\Commerce\Themes\ThemeRegistry;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pages, and the composer that builds one out of sections.
 *
 * ⭐⭐ THE SCREEN THIS PROGRAMME EXISTS FOR. Sixteen bespoke Blade templates and
 * 7,044 lines of them are pages whose content is welded to their markup: a copy
 * change needs a deploy, an invalid stylesheet ships silently, and four of those
 * templates could emit an empty title because a NULL check was written as `??`
 * against an admin form that posts an empty string. A page composed here has
 * none of those properties, because it holds no markup at all.
 *
 * ⛔ THE SLUG IS FROZEN ONCE A PAGE HAS BEEN PUBLISHED. A published URL has been
 * crawled, linked and possibly advertised, and renaming it 404s everyone holding
 * the link and throws the page's authority away. That is measured rather than
 * theoretical: eight of laladia.com's 40 known 404s are pages whose URL stopped
 * resolving, and recovering them cost a whole redirect controller.
 */
class PageController extends Controller
{
    public function index(Request $request): Response
    {
        $storeId = $request->integer('store') ?: null;

        $pages = Page::query()
            ->with('store')
            ->withCount('sections')
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->orderBy('store_id')
            ->orderBy('type')
            ->orderBy('slug')
            ->get()
            ->map(fn (Page $page) => $this->summary($page));

        return Inertia::render('commerce/pages/index', [
            'pages' => $pages,
            'stores' => Store::orderBy('name')->get()->map(fn (Store $store) => [
                'id' => $store->id,
                'name' => $store->name,
                'key' => $store->key,
                'status' => $store->status->value,
                'theme_key' => $store->theme_key,
            ])->values(),
            'pageTypes' => PageType::options(),
            'filters' => ['store' => $storeId],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer', Rule::exists('stores', 'id')->where('business_id', $this->businessId())],
            'type' => ['required', Rule::enum(PageType::class)],
            'title' => ['required', 'string', 'max:190'],
            'slug' => ['required', 'string', 'max:190', 'regex:'.self::SLUG_PATTERN],
        ], [
            'slug.regex' => 'A slug is lowercase words joined by hyphens, e.g. royal-beef-pickle. Use a forward slash only to nest, never at the start or the end.',
        ]);

        $type = PageType::from($data['type']);

        // ⛔ Exactly one home page per store. A second would make the front page
        // depend on row order, so the answer could change in production with
        // nothing having been edited.
        if ($type->isSingleton() && Page::where('store_id', $data['store_id'])->where('type', $type->value)->exists()) {
            throw ValidationException::withMessages([
                'type' => 'This store already has a home page. A store serves one page at its root.',
            ]);
        }

        $this->refuseDuplicateSlug($data['store_id'], $data['slug']);

        $page = Page::create($data);

        return redirect()
            ->route('commerce.pages.edit', $page)
            ->with('success', 'Page created. It is a draft until you publish it.');
    }

    public function edit(Page $page): Response
    {
        $page->load(['store', 'sections']);

        $theme = $this->themeFor($page->store);

        return Inertia::render('commerce/pages/edit', [
            'page' => array_merge($this->summary($page), [
                'sections' => $page->sections->map(fn (PageSection $section) => $this->sectionSummary($section, $theme))->values(),
                'publish_problems' => $page->publishProblems($theme),
            ]),
            'store' => [
                'id' => $page->store->id,
                'name' => $page->store->name,
                'key' => $page->store->key,
                'status' => $page->store->status->value,
                'theme_key' => $page->store->theme_key,
                'theme_name' => $theme?->name(),
            ],
            'catalogue' => $this->catalogue(app(SectionTypeRegistry::class), $theme),
            // ⚠ References are CHOSEN from real ERP records, never typed as raw
            // ids. A section stores the id and nothing else, so a product that
            // is later renamed carries its new name onto every page at once.
            'products' => Product::where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'sku'])
                ->map(fn (Product $product) => ['id' => $product->id, 'label' => $product->name.' ('.$product->sku.')'])
                ->values(),
            'categories' => Category::orderBy('name')->get(['id', 'name'])
                ->map(fn (Category $category) => ['id' => $category->id, 'label' => $category->name])
                ->values(),
            // ⚠ Scoped to THIS store, not the business. A section on Bionic must
            // not be able to point at LaLaDia's photography, and the surest way
            // to stop that is never to offer it.
            'media' => StoreMedia::where('store_id', $page->store_id)
                ->orderByDesc('created_at')->get()
                ->map(fn (StoreMedia $media) => $media->toReference())
                ->values(),
        ]);
    }

    public function update(Request $request, Page $page): RedirectResponse
    {
        $rules = [
            'title' => ['required', 'string', 'max:190'],
            'seo_title' => ['nullable', 'string', 'max:190'],
            'seo_description' => ['nullable', 'string', 'max:320'],
        ];

        // ⛔ The address of a page the public has already seen is not an
        // ordinary text field. See the class docblock.
        if (! $page->slugIsFrozen()) {
            $rules['slug'] = ['required', 'string', 'max:190', 'regex:'.self::SLUG_PATTERN];
        }

        $data = $request->validate($rules, [
            'slug.regex' => 'A slug is lowercase words joined by hyphens. Use a forward slash only to nest.',
        ]);

        if (array_key_exists('slug', $data)) {
            $this->refuseDuplicateSlug($page->store_id, $data['slug'], $page->id);
        }

        $page->update($data);

        return back()->with('success', 'Page saved.');
    }

    public function destroy(Page $page): RedirectResponse
    {
        // ⛔ Taking something the public can read off the internet should be two
        // decisions, never one misplaced click.
        if ($page->published_at !== null) {
            throw ValidationException::withMessages([
                'page' => 'Take the page down before deleting it. Something the public can read should not disappear in one click.',
            ]);
        }

        $storeId = $page->store_id;
        $page->delete();

        return redirect()
            ->route('commerce.pages.index', ['store' => $storeId])
            ->with('success', 'Page deleted.');
    }

    /**
     * ⭐ The gate between unfinished work and a visitor.
     *
     * Every problem is named, one per line, rather than the page going out with
     * a blank hero and somebody discovering it in a browser.
     */
    public function publish(Page $page): RedirectResponse
    {
        $theme = $this->themeFor($page->store);

        // ⚠ A store with no theme renders nothing at all, so publishing a page
        // on one would be publishing into the dark.
        if ($theme === null) {
            throw ValidationException::withMessages([
                'publish' => 'This store has no theme yet, so there is nothing to render the page with. Choose a theme on the store first.',
            ]);
        }

        $problems = $page->publishProblems($theme);

        if ($problems !== []) {
            throw ValidationException::withMessages(['publish' => $problems]);
        }

        $page->publish();

        return back()->with('success', 'Page published.');
    }

    public function unpublish(Page $page): RedirectResponse
    {
        $page->unpublish();

        return back()->with('success', 'Page taken down. It is a draft again.');
    }

    /* ------------------------------------------------------------------ */

    /**
     * Lowercase words joined by hyphens, optionally nested with a slash, with no
     * leading or trailing slash and no double slash.
     */
    private const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\/[a-z0-9]+(?:-[a-z0-9]+)*)*$/';

    private function refuseDuplicateSlug(int $storeId, string $slug, ?int $ignoreId = null): void
    {
        $taken = Page::where('store_id', $storeId)
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'slug' => 'That address is already used by another page on this store.',
            ]);
        }
    }

    private function summary(Page $page): array
    {
        return [
            'id' => $page->id,
            'store_id' => $page->store_id,
            'store_name' => $page->store?->name,
            'type' => $page->type->value,
            'type_label' => $page->type->label(),
            'slug' => $page->slug,
            'title' => $page->title,
            'seo_title' => $page->seo_title,
            'seo_description' => $page->seo_description,
            'path' => $page->path(),
            'published_at' => $page->published_at?->toIso8601String(),
            'is_published' => $page->isPublished(),
            'is_scheduled' => $page->isScheduled(),
            // ⚠ The screen says the slug is frozen where somebody is typing it,
            // rather than in a document nobody opens.
            'slug_locked' => $page->slugIsFrozen(),
            'section_count' => $page->sections_count ?? $page->sections()->count(),
        ];
    }

    private function sectionSummary(PageSection $section, ?Theme $theme): array
    {
        $type = $section->type();

        return [
            'id' => $section->id,
            'type_key' => $section->type_key,
            'type_version' => $section->type_version,
            'identity' => $section->identity(),
            'label' => $type?->label ?? $section->type_key,
            'group' => $type?->group,
            'purpose' => $type?->purpose,
            'position' => $section->position,
            'is_hidden' => $section->is_hidden,
            // ⚠ Cast to an object so an empty payload reaches the browser as {}
            // and not as [], which React would try to index as an array.
            'payload' => (object) ($section->payload ?? []),
            'fields' => $type ? array_map(fn ($field) => $field->toArray(), $type->fields) : [],
            // ⚠ A row whose type is no longer registered is a real state after a
            // deploy. It is shown, and it says so, so that somebody can remove
            // it: a composer that hid it would leave a page nobody could fix.
            'is_known' => $type !== null,
            // ⛔ Reported per section, not only at publish, so an author sees the
            // section their theme cannot draw while they are looking at it.
            'renderable' => $type === null || $theme === null || $theme->canRender($type->key, $type->version),
        ];
    }

    /**
     * @return array<int, array{group: string, types: array<int, array<string, mixed>>}>
     */
    private function catalogue(SectionTypeRegistry $registry, ?Theme $theme): array
    {
        $groups = [];

        // ⚠ `forAuthoring` offers only the newest version of each type. Old
        // majors stay registered so pages already holding them keep rendering;
        // they are not offered, because composing a NEW page against a
        // superseded contract is work that would have to be redone.
        foreach ($registry->forAuthoring() as $group => $types) {
            $groups[] = [
                'group' => $group,
                'types' => array_map(fn (SectionType $type) => [
                    'key' => $type->key,
                    'version' => $type->version,
                    'identity' => $type->identity(),
                    'label' => $type->label,
                    'purpose' => $type->purpose,
                    'fields' => array_map(fn ($field) => $field->toArray(), $type->fields),
                    'renderable' => $theme === null || $theme->canRender($type->key, $type->version),
                ], $types),
            ];
        }

        return $groups;
    }

    private function themeFor(Store $store): ?Theme
    {
        $registry = app(ThemeRegistry::class);

        return $store->theme_key !== null && $registry->has($store->theme_key)
            ? $registry->get($store->theme_key)
            : null;
    }

    private function businessId(): ?int
    {
        return app(BusinessContext::class)->id();
    }
}
