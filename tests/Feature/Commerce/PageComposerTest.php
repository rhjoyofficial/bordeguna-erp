<?php

use App\Models\User;
use App\Modules\Commerce\database\seeders\CommercePermissionsSeeder;
use App\Modules\Commerce\Enums\PageType;
use App\Modules\Commerce\Models\Page;
use App\Modules\Commerce\Models\PageSection;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Themes\SectionSupport;
use App\Modules\Commerce\Themes\Theme;
use App\Modules\Commerce\Themes\ThemeRegistry;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 1, slice 1.4: pages and the section composer.
 *
 * ⭐⭐ The screen that replaces sixteen bespoke Blade templates and 7,044 lines.
 * These tests are mostly about the two gates that make that safe: what a SAVE
 * refuses, and what a PUBLISH refuses. The difference between them is the whole
 * design, so it is tested from both sides.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::firstOrCreate(['name' => $name, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }

    (new CommercePermissionsSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->store = Store::create(['key' => 'bionic', 'name' => 'Bionic Garden', 'order_prefix' => 'BNC']);
    $this->store->update(['theme_key' => 'bionic-classic']);
});

function composerUser(User $user, Business $business)
{
    return test()->actingAs($user)->withSession(['current_business_id' => $business->id]);
}

function composerPage(Store $store, array $attributes = []): Page
{
    return Page::create(array_merge([
        'store_id' => $store->id,
        'type' => PageType::Custom,
        'slug' => 'our-story',
        'title' => 'Our story',
    ], $attributes));
}

/* -------------------------------------------------------------------------- */
/* The screens */
/* -------------------------------------------------------------------------- */

it('shows the pages screen', function () {
    composerUser($this->owner, $this->business)
        ->get(route('commerce.pages.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('commerce/pages/index')->has('pages')->has('stores'));
});

it('opens the composer with the section catalogue grouped for authoring', function () {
    $page = composerPage($this->store);

    composerUser($this->owner, $this->business)
        ->get(route('commerce.pages.edit', $page))
        ->assertOk()
        ->assertInertia(function (Assert $inertia) {
            $inertia->component('commerce/pages/edit')->has('catalogue');

            $groups = collect($inertia->toArray()['props']['catalogue'])->pluck('group');

            expect($groups)->toContain('Story')->and($groups)->toContain('Hero');
        });
});

it('keeps a user with no commerce permission out of the composer', function () {
    $page = composerPage($this->store);
    $outsider = User::factory()->create();
    $outsider->businesses()->attach($this->business->id);

    composerUser($outsider, $this->business)
        ->get(route('commerce.pages.edit', $page))
        ->assertForbidden();
});

/* -------------------------------------------------------------------------- */
/* Addresses */
/* -------------------------------------------------------------------------- */

it('creates a page as a draft', function () {
    composerUser($this->owner, $this->business)
        ->post(route('commerce.pages.store'), [
            'store_id' => $this->store->id,
            'type' => 'landing',
            'title' => 'Royal Beef Pickle',
            'slug' => 'royal-beef-pickle',
        ])
        ->assertRedirect();

    $page = Page::sole();

    expect($page->slug)->toBe('royal-beef-pickle')
        ->and($page->published_at)->toBeNull()
        ->and($page->isPublished())->toBeFalse();
});

it('⛔ refuses a second home page on one store', function () {
    composerPage($this->store, ['type' => PageType::Home, 'slug' => 'home', 'title' => 'Home']);

    // A second one would make the front page depend on row order.
    composerUser($this->owner, $this->business)
        ->post(route('commerce.pages.store'), [
            'store_id' => $this->store->id,
            'type' => 'home',
            'title' => 'Home again',
            'slug' => 'home-again',
        ])
        ->assertSessionHasErrors('type');

    expect(Page::where('type', PageType::Home)->count())->toBe(1);
});

it('serves the home page at the root and never at /home', function () {
    $home = composerPage($this->store, ['type' => PageType::Home, 'slug' => 'home', 'title' => 'Home']);
    $other = composerPage($this->store, ['slug' => 'our-story']);

    // ⛔ Two addresses for the front page is the duplicate-canonical defect that
    // produced 59 exclusions in Search Console on the live shop.
    expect($home->path())->toBe('/')
        ->and($other->path())->toBe('/our-story');
});

it('refuses a slug already used on the same store but allows it on another', function () {
    composerPage($this->store, ['slug' => 'terms', 'title' => 'Terms']);

    composerUser($this->owner, $this->business)
        ->post(route('commerce.pages.store'), [
            'store_id' => $this->store->id, 'type' => 'legal', 'title' => 'Terms', 'slug' => 'terms',
        ])
        ->assertSessionHasErrors('slug');

    $second = Store::create(['key' => 'econic', 'name' => 'Econic', 'order_prefix' => 'ECN']);

    // ⚠ Two stores may both have `terms`. That is the point of one codebase
    // serving several shops.
    composerUser($this->owner, $this->business)
        ->post(route('commerce.pages.store'), [
            'store_id' => $second->id, 'type' => 'legal', 'title' => 'Terms', 'slug' => 'terms',
        ])
        ->assertSessionHasNoErrors();
});

it('⛔ freezes the address once the page has been published', function () {
    $page = composerPage($this->store, ['slug' => 'our-story']);
    $page->publish();

    composerUser($this->owner, $this->business)
        ->put(route('commerce.pages.update', $page), [
            'title' => 'Our story, retold',
            'slug' => 'a-different-address',
        ])
        ->assertRedirect();

    $page->refresh();

    // The title moves; the address does not. Renaming it would 404 everyone
    // holding the link and throw the page's search authority away.
    expect($page->title)->toBe('Our story, retold')
        ->and($page->slug)->toBe('our-story');
});

it('will not delete a page the public can read', function () {
    $page = composerPage($this->store);
    $page->publish();

    composerUser($this->owner, $this->business)
        ->delete(route('commerce.pages.destroy', $page))
        ->assertSessionHasErrors('page');

    expect(Page::count())->toBe(1);
});

/* -------------------------------------------------------------------------- */
/* Saving a section: unfinished is allowed, malformed is not */
/* -------------------------------------------------------------------------- */

it('adds a section from the catalogue and appends it to the end', function () {
    $page = composerPage($this->store);

    composerUser($this->owner, $this->business)
        ->post(route('commerce.pages.sections.store', $page), ['type_key' => 'story.prose', 'type_version' => 1])
        ->assertRedirect();

    composerUser($this->owner, $this->business)
        ->post(route('commerce.pages.sections.store', $page), ['type_key' => 'story.notes', 'type_version' => 1]);

    $positions = $page->sections()->pluck('position')->all();

    expect($positions)->toBe([1, 2])
        ->and($page->sections()->pluck('type_key')->all())->toBe(['story.prose', 'story.notes']);
});

it('⛔ refuses a section type nothing is registered under', function () {
    $page = composerPage($this->store);

    // A page holding a type nothing defines can neither be validated nor
    // rendered, and the failure would surface at a visitor.
    composerUser($this->owner, $this->business)
        ->post(route('commerce.pages.sections.store', $page), ['type_key' => 'story.splt', 'type_version' => 1])
        ->assertSessionHasErrors('type_key');

    expect($page->sections()->count())->toBe(0);
});

it('⭐ saves a section that is unfinished, because composing takes an afternoon', function () {
    $page = composerPage($this->store);
    $section = $page->sections()->create(['type_key' => 'story.prose', 'type_version' => 1, 'payload' => []]);

    // `body` is REQUIRED by the contract and is deliberately absent here.
    composerUser($this->owner, $this->business)
        ->put(route('commerce.pages.sections.update', [$page, $section]), [
            'payload' => ['heading' => 'Where the honey comes from'],
        ])
        ->assertSessionHasNoErrors();

    expect($section->fresh()->payload)->toBe(['heading' => 'Where the honey comes from']);
});

it('⛔ refuses a section whose rich text carries markup that could run', function () {
    $page = composerPage($this->store);
    $section = $page->sections()->create(['type_key' => 'story.prose', 'type_version' => 1, 'payload' => []]);

    composerUser($this->owner, $this->business)
        ->put(route('commerce.pages.sections.update', [$page, $section]), [
            'payload' => ['body' => '<script>alert(1)</script>'],
        ])
        ->assertSessionHasErrors('payload');

    expect($section->fresh()->payload)->toBe([]);
});

it('refuses a field the contract does not have, rather than dropping it silently', function () {
    $page = composerPage($this->store);
    $section = $page->sections()->create(['type_key' => 'story.prose', 'type_version' => 1, 'payload' => []]);

    // ⚠ Silently discarding it is how a page loses content nobody notices.
    composerUser($this->owner, $this->business)
        ->put(route('commerce.pages.sections.update', [$page, $section]), [
            'payload' => ['body' => 'Real copy.', 'price' => 240],
        ])
        ->assertSessionHasErrors('payload');
});

it('refuses a value outside the choices the contract declares', function () {
    $page = composerPage($this->store);
    $section = $page->sections()->create(['type_key' => 'story.prose', 'type_version' => 1, 'payload' => []]);

    composerUser($this->owner, $this->business)
        ->put(route('commerce.pages.sections.update', [$page, $section]), [
            'payload' => ['body' => 'Real copy.', 'image_position' => 'diagonally'],
        ])
        ->assertSessionHasErrors('payload');
});

it('drops a cleared optional field instead of storing an empty string', function () {
    $page = composerPage($this->store);
    $section = $page->sections()->create([
        'type_key' => 'story.prose', 'type_version' => 1,
        'payload' => ['heading' => 'Old heading', 'body' => 'Real copy.'],
    ]);

    composerUser($this->owner, $this->business)
        ->put(route('commerce.pages.sections.update', [$page, $section]), [
            'payload' => ['heading' => '', 'body' => 'Real copy.'],
        ])
        ->assertSessionHasNoErrors();

    // ⚠ Absent, not present-and-empty. An empty string would fail the contract's
    // own checks while looking like content on screen.
    expect($section->fresh()->payload)->toBe(['body' => 'Real copy.']);
});

/* -------------------------------------------------------------------------- */
/* Order, visibility and ownership */
/* -------------------------------------------------------------------------- */

it('reorders the whole page in one move and renumbers from zero', function () {
    $page = composerPage($this->store);
    $a = $page->sections()->create(['type_key' => 'story.prose', 'type_version' => 1, 'position' => 0]);
    $b = $page->sections()->create(['type_key' => 'story.notes', 'type_version' => 1, 'position' => 1]);
    $c = $page->sections()->create(['type_key' => 'story.figure', 'type_version' => 1, 'position' => 2]);

    composerUser($this->owner, $this->business)
        ->post(route('commerce.pages.sections.reorder', $page), ['order' => [$c->id, $a->id, $b->id]])
        ->assertRedirect();

    expect($page->sections()->pluck('id')->all())->toBe([$c->id, $a->id, $b->id])
        ->and($page->sections()->pluck('position')->all())->toBe([0, 1, 2]);
});

it('⛔ refuses an order that is not exactly this page', function () {
    $page = composerPage($this->store);
    $a = $page->sections()->create(['type_key' => 'story.prose', 'type_version' => 1, 'position' => 0]);
    $page->sections()->create(['type_key' => 'story.notes', 'type_version' => 1, 'position' => 1]);

    // A partial order would silently push every omitted section to the end.
    composerUser($this->owner, $this->business)
        ->post(route('commerce.pages.sections.reorder', $page), ['order' => [$a->id]])
        ->assertSessionHasErrors('order');

    expect($page->sections()->pluck('position')->all())->toBe([0, 1]);
});

it('renumbers what is left when a section is removed', function () {
    $page = composerPage($this->store);
    $a = $page->sections()->create(['type_key' => 'story.prose', 'type_version' => 1, 'position' => 0]);
    $b = $page->sections()->create(['type_key' => 'story.notes', 'type_version' => 1, 'position' => 1]);
    $c = $page->sections()->create(['type_key' => 'story.figure', 'type_version' => 1, 'position' => 2]);

    composerUser($this->owner, $this->business)
        ->delete(route('commerce.pages.sections.destroy', [$page, $b]));

    // ⚠ Gaps are how an ordering silently stops being an ordering.
    expect($page->sections()->pluck('id')->all())->toBe([$a->id, $c->id])
        ->and($page->sections()->pluck('position')->all())->toBe([0, 1]);
});

it('will not let one page edit another page\'s section', function () {
    $mine = composerPage($this->store, ['slug' => 'mine']);
    $theirs = composerPage($this->store, ['slug' => 'theirs']);
    $section = $theirs->sections()->create(['type_key' => 'story.prose', 'type_version' => 1]);

    composerUser($this->owner, $this->business)
        ->delete(route('commerce.pages.sections.destroy', [$mine, $section]))
        ->assertNotFound();

    expect(PageSection::count())->toBe(1);
});

it('hides a section without losing what it says', function () {
    $page = composerPage($this->store);
    $section = $page->sections()->create([
        'type_key' => 'story.prose', 'type_version' => 1, 'payload' => ['body' => 'Kept.'],
    ]);

    composerUser($this->owner, $this->business)
        ->post(route('commerce.pages.sections.visibility', [$page, $section]));

    // ⚠ Hiding is not deleting: the seasonal product comes back in season.
    expect($section->fresh()->is_hidden)->toBeTrue()
        ->and($section->fresh()->payload)->toBe(['body' => 'Kept.']);
});

/* -------------------------------------------------------------------------- */
/* The publish gate */
/* -------------------------------------------------------------------------- */

it('⛔ refuses to publish a page with nothing on it', function () {
    $page = composerPage($this->store);

    composerUser($this->owner, $this->business)
        ->post(route('commerce.pages.publish', $page))
        ->assertSessionHasErrors('publish');

    expect($page->fresh()->published_at)->toBeNull();
});

it('⭐ refuses to publish a page whose section is unfinished, and names the field', function () {
    $page = composerPage($this->store);
    // Saved happily above; refused here. That difference is the whole design.
    $page->sections()->create(['type_key' => 'story.prose', 'type_version' => 1, 'payload' => ['heading' => 'Only a heading']]);

    $problems = $page->publishProblems(app(ThemeRegistry::class)->get('bionic-classic'));

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('[body] is required');

    composerUser($this->owner, $this->business)
        ->post(route('commerce.pages.publish', $page))
        ->assertSessionHasErrors('publish');
});

it('publishes a page once every visible section is complete', function () {
    $page = composerPage($this->store);
    $page->sections()->create([
        'type_key' => 'story.prose', 'type_version' => 1,
        'payload' => ['heading' => 'Where the honey comes from', 'body' => 'Real copy, written by a person.'],
    ]);

    composerUser($this->owner, $this->business)
        ->post(route('commerce.pages.publish', $page))
        ->assertSessionHasNoErrors();

    expect($page->fresh()->isPublished())->toBeTrue();
});

it('does not let an unfinished HIDDEN section block the rest of the page', function () {
    $page = composerPage($this->store);
    $page->sections()->create([
        'type_key' => 'story.prose', 'type_version' => 1,
        'payload' => ['body' => 'Real copy.'],
    ]);
    // ⚠ A hidden section is not on the page, so it cannot make the page wrong.
    $page->sections()->create(['type_key' => 'story.figure', 'type_version' => 1, 'payload' => [], 'is_hidden' => true]);

    composerUser($this->owner, $this->business)
        ->post(route('commerce.pages.publish', $page))
        ->assertSessionHasNoErrors();

    expect($page->fresh()->isPublished())->toBeTrue();
});

it('⛔ refuses to publish a section the store\'s theme cannot draw', function () {
    // A deliberately narrow theme: it renders passages and nothing else, and
    // declares no fallback for anything.
    $narrow = new class extends Theme
    {
        public function key(): string
        {
            return 'narrow-test-theme';
        }

        public function name(): string
        {
            return 'Narrow';
        }

        public function sections(): array
        {
            return [new SectionSupport('story.prose', 1)];
        }
    };

    app(ThemeRegistry::class)->register($narrow);
    $this->store->update(['theme_key' => 'narrow-test-theme']);

    $page = composerPage($this->store);
    $page->sections()->create([
        'type_key' => 'story.notes', 'type_version' => 1,
        'payload' => ['notes' => [
            ['title' => 'Serve cold', 'body' => 'Straight from the fridge.'],
            ['title' => 'Keep sealed', 'body' => 'Close the jar after each use.'],
        ]],
    ]);

    // The section is COMPLETE. It is refused because it would render as nothing,
    // and finding that out from a visitor is the failure this contract exists to
    // prevent.
    composerUser($this->owner, $this->business)
        ->post(route('commerce.pages.publish', $page))
        ->assertSessionHasErrors('publish');

    expect($page->fresh()->published_at)->toBeNull();
});

it('⛔ refuses to publish onto a store that has no theme at all', function () {
    $this->store->forceFill(['theme_key' => null])->save();

    $page = composerPage($this->store);
    $page->sections()->create([
        'type_key' => 'story.prose', 'type_version' => 1, 'payload' => ['body' => 'Real copy.'],
    ]);

    composerUser($this->owner, $this->business)
        ->post(route('commerce.pages.publish', $page))
        ->assertSessionHasErrors('publish');
});

it('treats a future publish date as scheduled and not yet public', function () {
    $page = composerPage($this->store);
    $page->forceFill(['published_at' => now()->addWeek()])->save();

    // ⚠ Asking "is the column filled" and meaning "is it live" is how a
    // post-dated page goes public early.
    expect($page->isPublished())->toBeFalse()
        ->and($page->isScheduled())->toBeTrue();
});

it('names a section whose type this ERP no longer defines, rather than hiding it', function () {
    $page = composerPage($this->store);
    $page->sections()->create(['type_key' => 'story.retired', 'type_version' => 1, 'payload' => []]);

    $problems = $page->publishProblems();

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('story.retired@1');

    // ⚠ And it is still listed on the screen, so somebody can remove it.
    composerUser($this->owner, $this->business)
        ->get(route('commerce.pages.edit', $page))
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia->where('page.sections.0.is_known', false));
});

/* -------------------------------------------------------------------------- */
/* Tenancy */
/* -------------------------------------------------------------------------- */

it('⛔ does not show one business the pages of another', function () {
    composerPage($this->store, ['slug' => 'ours']);

    $other = Business::create(['name' => 'Azmion', 'slug' => 'azmion']);
    app(BusinessContext::class)->set($other->id);
    $otherStore = Store::create(['key' => 'azmion', 'name' => 'Azmion', 'order_prefix' => 'AZM']);
    composerPage($otherStore, ['slug' => 'theirs']);

    app(BusinessContext::class)->set($this->business->id);

    expect(Page::pluck('slug')->all())->toBe(['ours']);
});

it('stamps a page with the business of the store it belongs to', function () {
    $page = composerPage($this->store);

    // ⚠ Both columns are stored so the page fails closed under BusinessScope
    // like everything else. They must agree, and this is what says so.
    expect($page->business_id)->toBe($this->store->business_id);
});
