<?php

use App\Models\User;
use App\Modules\Commerce\database\seeders\CommercePermissionsSeeder;
use App\Modules\Commerce\Enums\PageType;
use App\Modules\Commerce\Models\Page;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Themes\SectionSupport;
use App\Modules\Commerce\Themes\Theme;
use App\Modules\Commerce\Themes\ThemeCompatibility;
use App\Modules\Commerce\Themes\ThemeRegistry;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 1, slice 1.5: theme switching with the compatibility report.
 *
 * ⭐⭐ The capability declaration from slice 1.2 cashed in. A theme that cannot
 * draw a section does not throw when it renders: it draws nothing, the page
 * answers 200 with a hole where the hero was, and the first person to find out
 * is a customer. So the question is asked once, before anything is saved.
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

function switchUser(User $user, Business $business)
{
    return test()->actingAs($user)->withSession(['current_business_id' => $business->id]);
}

/**
 * A theme that renders passages and nothing else, and declares no fallback for
 * anything. The deliberately incompatible second theme the plan asks for.
 */
function narrowTheme(): Theme
{
    return new class extends Theme
    {
        public function key(): string
        {
            return 'narrow';
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
}

/**
 * Renders passages directly, and answers "I will draw a split panel as a
 * passage instead" for the one type it cannot.
 */
function downgradingTheme(): Theme
{
    return new class extends Theme
    {
        public function key(): string
        {
            return 'downgrading';
        }

        public function name(): string
        {
            return 'Downgrading';
        }

        public function sections(): array
        {
            return [
                new SectionSupport('story.prose', 1),
                new SectionSupport('story.split', 0, fallback: 'story.prose'),
            ];
        }
    };
}

function switchPage(Store $store, array $attributes = []): Page
{
    return Page::create(array_merge([
        'store_id' => $store->id, 'type' => PageType::Custom,
        'slug' => 'our-story', 'title' => 'Our story',
    ], $attributes));
}

/* -------------------------------------------------------------------------- */
/* The refusal */
/* -------------------------------------------------------------------------- */

it('⛔ refuses a switch that would blank a section on a PUBLISHED page', function () {
    app(ThemeRegistry::class)->register(narrowTheme());

    $page = switchPage($this->store);
    $page->sections()->create([
        'type_key' => 'story.notes', 'type_version' => 1,
        'payload' => ['notes' => [
            ['title' => 'Serve cold', 'body' => 'Straight from the fridge.'],
            ['title' => 'Keep sealed', 'body' => 'Close the jar after each use.'],
        ]],
    ]);
    $page->publish();

    switchUser($this->owner, $this->business)
        ->put(route('commerce.stores.update', $this->store), [
            'name' => 'Bionic Garden',
            'price_group' => 'retail',
            'availability_policy' => 'status_based',
            'theme_key' => 'narrow',
        ])
        ->assertSessionHasErrors('theme_key');

    // ⛔ The store keeps the theme that can actually draw its pages.
    expect($this->store->fresh()->theme_key)->toBe('bionic-classic');
});

it('names the page and the section rather than counting them', function () {
    app(ThemeRegistry::class)->register(narrowTheme());

    $page = switchPage($this->store, ['title' => 'Royal Beef Pickle', 'slug' => 'royal-beef-pickle']);
    $page->sections()->create(['type_key' => 'story.figure', 'type_version' => 1, 'position' => 3]);
    $page->publish();

    $refusals = app(ThemeCompatibility::class)->refusals($this->store, narrowTheme());

    // "3 sections would go dark" tells nobody which three, and the person
    // reading it is about to decide whether to take pages down.
    expect($refusals)->toHaveCount(1)
        ->and($refusals[0])->toContain('Royal Beef Pickle')
        ->and($refusals[0])->toContain('/royal-beef-pickle')
        ->and($refusals[0])->toContain('section 3');
});

it('allows the switch once the offending page is taken down', function () {
    app(ThemeRegistry::class)->register(narrowTheme());

    $page = switchPage($this->store);
    $page->sections()->create(['type_key' => 'story.figure', 'type_version' => 1]);
    $page->publish();

    expect(app(ThemeCompatibility::class)->refusals($this->store, narrowTheme()))->toHaveCount(1);

    // ⚠ The escape hatch is deliberately unpublishing, not a force flag. Forcing
    // would leave a live page with a hole in it, which is the exact outcome the
    // refusal exists to prevent.
    $page->unpublish();

    switchUser($this->owner, $this->business)
        ->put(route('commerce.stores.update', $this->store), [
            'name' => 'Bionic Garden', 'price_group' => 'retail',
            'availability_policy' => 'status_based', 'theme_key' => 'narrow',
        ])
        ->assertSessionHasNoErrors();

    expect($this->store->fresh()->theme_key)->toBe('narrow');
});

/* -------------------------------------------------------------------------- */
/* What blocks and what merely warns */
/* -------------------------------------------------------------------------- */

it('⚠ warns about a DRAFT page rather than blocking the whole store on it', function () {
    app(ThemeRegistry::class)->register(narrowTheme());

    $draft = switchPage($this->store, ['slug' => 'a-draft']);
    $draft->sections()->create(['type_key' => 'story.figure', 'type_version' => 1]);

    $report = app(ThemeCompatibility::class)->report($this->store, narrowTheme());

    // An abandoned draft must not hold a whole store hostage, and slice 1.4's
    // publish gate will refuse it later anyway.
    expect($report['can_switch'])->toBeTrue()
        ->and($report['blocking'])->toHaveCount(0)
        ->and($report['draft_warnings'])->toHaveCount(1);
});

it('ignores a hidden section, because it is not on the page', function () {
    app(ThemeRegistry::class)->register(narrowTheme());

    $page = switchPage($this->store);
    $page->sections()->create(['type_key' => 'story.prose', 'type_version' => 1, 'payload' => ['body' => 'Copy.']]);
    $page->sections()->create(['type_key' => 'story.figure', 'type_version' => 1, 'is_hidden' => true]);
    $page->publish();

    // Blocking a switch over something nobody can see would be theatre.
    expect(app(ThemeCompatibility::class)->report($this->store, narrowTheme())['can_switch'])->toBeTrue();
});

it('⭐ treats a declared fallback as a downgrade and names it, not as a failure', function () {
    app(ThemeRegistry::class)->register(downgradingTheme());

    $page = switchPage($this->store);
    $page->sections()->create([
        'type_key' => 'story.split', 'type_version' => 1,
        'payload' => ['body' => 'Copy.', 'media_side' => 'left'],
    ]);
    $page->publish();

    $report = app(ThemeCompatibility::class)->report($this->store, downgradingTheme());

    // The words stay on the page and the look changes. That is the difference
    // between a redesign and a data loss, so it is reported rather than refused.
    expect($report['can_switch'])->toBeTrue()
        ->and($report['blocking'])->toHaveCount(0)
        ->and($report['downgraded'])->toHaveCount(1)
        ->and($report['downgraded'][0]['reason'])->toContain('story.prose');
});

it('blames a retired section type on the ERP and not on the candidate theme', function () {
    app(ThemeRegistry::class)->register(narrowTheme());

    $page = switchPage($this->store);
    $page->sections()->create(['type_key' => 'story.retired', 'type_version' => 1]);
    $page->publish();

    $report = app(ThemeCompatibility::class)->report($this->store, narrowTheme());

    // ⚠ Broken whichever theme is chosen. Counting it against the candidate
    // would send somebody hunting for a fault in the wrong place.
    expect($report['already_broken'])->toHaveCount(1)
        ->and($report['blocking'])->toHaveCount(0)
        ->and($report['can_switch'])->toBeTrue();
});

/* -------------------------------------------------------------------------- */
/* The report on the screen */
/* -------------------------------------------------------------------------- */

it('shows the store screen a report for every theme it is not already on', function () {
    app(ThemeRegistry::class)->register(narrowTheme());

    switchUser($this->owner, $this->business)
        ->get(route('commerce.stores.edit', $this->store))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $keys = collect($page->toArray()['props']['themeReports'])->pluck('theme_key');

            // ⚠ Not the current one. "Your current theme is compatible with your
            // current pages" teaches the reader to ignore the panel.
            expect($keys)->toContain('narrow')->and($keys)->not->toContain('bionic-classic');
        });
});

it('says a switch is safe when the store has no pages at all', function () {
    app(ThemeRegistry::class)->register(narrowTheme());

    $report = app(ThemeCompatibility::class)->report($this->store, narrowTheme());

    expect($report['can_switch'])->toBeTrue()
        ->and($report['counts']['pages'])->toBe(0)
        ->and($report['counts']['sections'])->toBe(0);
});

it('lets a store with no theme at all choose one, subject to the same check', function () {
    app(ThemeRegistry::class)->register(narrowTheme());
    $this->store->forceFill(['theme_key' => null])->save();

    $page = switchPage($this->store);
    $page->sections()->create(['type_key' => 'story.figure', 'type_version' => 1]);
    $page->publish();

    // ⚠ Pages composed before a theme was chosen are a real state: a store
    // starts as a draft with no theme, and slice 1.4 lets pages be built on it.
    switchUser($this->owner, $this->business)
        ->put(route('commerce.stores.update', $this->store), [
            'name' => 'Bionic Garden', 'price_group' => 'retail',
            'availability_policy' => 'status_based', 'theme_key' => 'narrow',
        ])
        ->assertSessionHasErrors('theme_key');

    expect($this->store->fresh()->theme_key)->toBeNull();
});

it('does not re-run the check when the theme is not what changed', function () {
    $page = switchPage($this->store);
    $page->sections()->create(['type_key' => 'story.figure', 'type_version' => 1]);
    $page->publish();

    // The store keeps a theme that renders everything; renaming it must not be
    // dragged into a compatibility argument.
    switchUser($this->owner, $this->business)
        ->put(route('commerce.stores.update', $this->store), [
            'name' => 'Bionic Garden BD', 'price_group' => 'retail',
            'availability_policy' => 'status_based', 'theme_key' => 'bionic-classic',
        ])
        ->assertSessionHasNoErrors();

    expect($this->store->fresh()->name)->toBe('Bionic Garden BD');
});
