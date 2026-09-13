<?php

use App\Models\User;
use App\Modules\Commerce\database\seeders\CommercePermissionsSeeder;
use App\Modules\Commerce\Enums\PageType;
use App\Modules\Commerce\Models\Page;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreMedia;
use App\Modules\Commerce\Sections\SectionReferences;
use App\Modules\Commerce\Sections\SectionTypeRegistry;
use App\Modules\Commerce\Themes\ThemeRegistry;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 1, slice 1.6: the media library.
 *
 * ⭐ The slice exists so that "a missing image renders the theme's placeholder,
 * never a broken image" can be true. Most of that is enforced BEFORE a page goes
 * out rather than papered over afterwards, so most of these tests are about
 * refusals.
 */
beforeEach(function () {
    Storage::fake('local');

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

function mediaUser(User $user, Business $business)
{
    return test()->actingAs($user)->withSession(['current_business_id' => $business->id]);
}

function uploadImage(Store $store, User $owner, Business $business, array $extra = [])
{
    return mediaUser($owner, $business)->post(route('commerce.media.store'), array_merge([
        'store_id' => $store->id,
        'file' => UploadedFile::fake()->image('jar.jpg', 800, 600),
    ], $extra));
}

/* -------------------------------------------------------------------------- */
/* Uploading */
/* -------------------------------------------------------------------------- */

it('shows the media library', function () {
    mediaUser($this->owner, $this->business)
        ->get(route('commerce.media.index'))
        ->assertOk();
});

it('stores an upload against the store and records its dimensions', function () {
    uploadImage($this->store, $this->owner, $this->business)->assertRedirect();

    $media = StoreMedia::sole();

    // ⚠ Dimensions are read from the file that was actually stored, so the
    // storefront can reserve the space before the bytes arrive.
    expect($media->store_id)->toBe($this->store->id)
        ->and($media->business_id)->toBe($this->business->id)
        ->and($media->width)->toBe(800)
        ->and($media->height)->toBe(600)
        ->and($media->original_name)->toBe('jar.jpg')
        ->and(Storage::disk('local')->exists($media->path))->toBeTrue();
});

it('⛔ refuses an SVG, because it is a script that ends in .svg', function () {
    // Served from the shop's own origin to the public, an SVG is a cross-site
    // scripting payload with a picture's file extension.
    mediaUser($this->owner, $this->business)
        ->post(route('commerce.media.store'), [
            'store_id' => $this->store->id,
            'file' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'),
        ])
        ->assertSessionHasErrors('file');

    expect(StoreMedia::count())->toBe(0);
});

it('gives every image a uuid so an unlaunched library cannot be walked', function () {
    uploadImage($this->store, $this->owner, $this->business);

    $media = StoreMedia::sole();

    // ⛔ The id must not be the public handle. Compared as the whole last path
    // segment: "does not contain /media/1" failed whenever the random uuid
    // happened to start with the id's digits, about one run in sixteen.
    $handle = basename((string) parse_url($media->url(), PHP_URL_PATH));

    expect($media->uuid)->toBeString()->toHaveLength(36)
        ->and($handle)->toBe($media->uuid)
        ->and($handle)->not->toBe((string) $media->id);
});

it('keeps a user with no commerce permission out of the library', function () {
    $outsider = User::factory()->create();
    $outsider->businesses()->attach($this->business->id);

    mediaUser($outsider, $this->business)
        ->get(route('commerce.media.index'))
        ->assertForbidden();
});

/* -------------------------------------------------------------------------- */
/* Serving */
/* -------------------------------------------------------------------------- */

it('serves an image to a visitor with no session at all', function () {
    uploadImage($this->store, $this->owner, $this->business);
    $media = StoreMedia::sole();

    // ⛔ Anonymous by design, and outside the business scope: a storefront
    // visitor has no session and no tenant context.
    app(BusinessContext::class)->forget();

    $response = test()->get(route('commerce.media.show', ['uuid' => $media->uuid]))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    // ⚠ The DIRECTIVES, not the exact string. Symfony normalises Cache-Control
    // into alphabetical order, and asserting its ordering would be testing
    // somebody else's implementation detail rather than our own intent.
    $cacheControl = $response->headers->get('Cache-Control');

    expect($cacheControl)->toContain('public')
        ->and($cacheControl)->toContain('max-age=31536000')
        ->and($cacheControl)->toContain('immutable');
});

it('answers 404 for a uuid nothing is stored under', function () {
    test()->get(route('commerce.media.show', ['uuid' => '00000000-0000-4000-8000-000000000000']))
        ->assertNotFound();
});

it('⚠ answers 404, not 500, when the row survives but the file is gone', function () {
    uploadImage($this->store, $this->owner, $this->business);
    $media = StoreMedia::sole();

    // The state a database-only restore leaves behind. A missing picture is not
    // a broken website: the theme puts its placeholder there.
    Storage::disk('local')->delete($media->path);

    test()->get(route('commerce.media.show', ['uuid' => $media->uuid]))->assertNotFound();
});

/* -------------------------------------------------------------------------- */
/* Alt text as a first-class field */
/* -------------------------------------------------------------------------- */

it('treats "no alt text yet" and "deliberately none" as different facts', function () {
    $undecided = StoreMedia::create([
        'store_id' => $this->store->id, 'disk' => 'local', 'path' => 'a.jpg',
        'original_name' => 'a.jpg', 'mime_type' => 'image/jpeg',
    ]);
    $decorative = StoreMedia::create([
        'store_id' => $this->store->id, 'disk' => 'local', 'path' => 'b.jpg',
        'original_name' => 'b.jpg', 'mime_type' => 'image/jpeg', 'is_decorative' => true,
    ]);
    $described = StoreMedia::create([
        'store_id' => $this->store->id, 'disk' => 'local', 'path' => 'c.jpg',
        'original_name' => 'c.jpg', 'mime_type' => 'image/jpeg', 'alt_text' => 'A jar of ghee',
    ]);

    // ⚠ An empty alt is CORRECT for a flourish and WRONG for a product photo,
    // so the question is whether somebody decided, not whether text exists.
    expect($undecided->altTextIsDecided())->toBeFalse()
        ->and($decorative->altTextIsDecided())->toBeTrue()
        ->and($decorative->altText())->toBe('')
        ->and($described->altTextIsDecided())->toBeTrue()
        ->and($described->altText())->toBe('A jar of ghee');
});

it('clears the alt text when an image is marked decorative', function () {
    uploadImage($this->store, $this->owner, $this->business, ['alt_text' => 'A jar of ghee']);
    $media = StoreMedia::sole();

    mediaUser($this->owner, $this->business)
        ->put(route('commerce.media.update', $media), ['is_decorative' => true]);

    // ⚠ Keeping both would leave a sentence nobody reads and nobody maintains.
    expect($media->fresh()->alt_text)->toBeNull()
        ->and($media->fresh()->is_decorative)->toBeTrue();
});

/* -------------------------------------------------------------------------- */
/* The publish gate, extended */
/* -------------------------------------------------------------------------- */

function mediaPage(Store $store): Page
{
    return Page::create([
        'store_id' => $store->id, 'type' => PageType::Custom,
        'slug' => 'our-story', 'title' => 'Our story',
    ]);
}

it('⛔ refuses to publish a page whose image has no alt text decided', function () {
    uploadImage($this->store, $this->owner, $this->business);
    $media = StoreMedia::sole();

    $page = mediaPage($this->store);
    $page->sections()->create([
        'type_key' => 'story.figure', 'type_version' => 1,
        'payload' => ['image' => $media->id, 'caption' => 'The jar'],
    ]);

    $problems = $page->publishProblems(app(ThemeRegistry::class)->get('bionic-classic'));

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('jar.jpg')
        ->and($problems[0])->toContain('no alt text');

    mediaUser($this->owner, $this->business)
        ->post(route('commerce.pages.publish', $page))
        ->assertSessionHasErrors('publish');
});

it('publishes once the alt text has been decided', function () {
    uploadImage($this->store, $this->owner, $this->business, ['alt_text' => 'A jar of ghee on a wooden table']);
    $media = StoreMedia::sole();

    $page = mediaPage($this->store);
    $page->sections()->create([
        'type_key' => 'story.figure', 'type_version' => 1,
        'payload' => ['image' => $media->id, 'caption' => 'The jar'],
    ]);

    mediaUser($this->owner, $this->business)
        ->post(route('commerce.pages.publish', $page))
        ->assertSessionHasNoErrors();

    expect($page->fresh()->isPublished())->toBeTrue();
});

it('⛔ refuses to publish a section pointing at an image that does not exist', function () {
    $page = mediaPage($this->store);
    $page->sections()->create([
        'type_key' => 'story.figure', 'type_version' => 1,
        'payload' => ['image' => 4242, 'caption' => 'The jar'],
    ]);

    // laladia.com carried a hero image that 404'd on production for weeks,
    // because nothing ever asked whether the file behind the reference existed.
    $problems = $page->publishProblems(app(ThemeRegistry::class)->get('bionic-classic'));

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain("not in this store's library");
});

it('⛔ refuses a section on one store pointing at another store\'s image', function () {
    $other = Store::create(['key' => 'econic', 'name' => 'Econic', 'order_prefix' => 'ECN']);
    $theirs = StoreMedia::create([
        'store_id' => $other->id, 'disk' => 'local', 'path' => 'theirs.jpg',
        'original_name' => 'theirs.jpg', 'mime_type' => 'image/jpeg', 'alt_text' => 'Described',
    ]);

    $page = mediaPage($this->store);
    $page->sections()->create([
        'type_key' => 'story.figure', 'type_version' => 1,
        'payload' => ['image' => $theirs->id, 'caption' => 'The jar'],
    ]);

    // ⚠ Same class of defect as a fork inheriting the original shop's pixel id.
    $problems = $page->publishProblems(app(ThemeRegistry::class)->get('bionic-classic'));

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain("not in this store's library");
});

it('finds images nested inside a list field, not only at the top level', function () {
    $registry = app(SectionTypeRegistry::class);
    $type = $registry->get('story.steps', 1);

    $ids = app(SectionReferences::class)->media($type, [
        'heading' => 'How it is made',
        'steps' => [
            ['title' => 'Churn', 'image' => 11],
            ['title' => 'Simmer', 'image' => 12],
            ['title' => 'Strain'],
        ],
    ]);

    // ⚠ A gallery's images live inside repeated entries. A check that only
    // looked at the top level would pass a page whose every picture was missing.
    sort($ids);
    expect($ids)->toBe([11, 12]);
});

/* -------------------------------------------------------------------------- */
/* Deleting */
/* -------------------------------------------------------------------------- */

it('⛔ refuses to delete an image a page still uses, and names the page', function () {
    uploadImage($this->store, $this->owner, $this->business, ['alt_text' => 'A jar']);
    $media = StoreMedia::sole();

    $page = mediaPage($this->store);
    $page->sections()->create([
        'type_key' => 'story.figure', 'type_version' => 1,
        'payload' => ['image' => $media->id],
    ]);

    // The "never a broken image" rule enforced at the only moment it can be:
    // before the file goes.
    mediaUser($this->owner, $this->business)
        ->delete(route('commerce.media.destroy', $media))
        ->assertSessionHasErrors('media');

    expect(StoreMedia::count())->toBe(1)
        ->and(Storage::disk('local')->exists($media->path))->toBeTrue();
});

it('deletes an unused image and removes the file with it', function () {
    uploadImage($this->store, $this->owner, $this->business, ['alt_text' => 'A jar']);
    $media = StoreMedia::sole();
    $path = $media->path;

    mediaUser($this->owner, $this->business)
        ->delete(route('commerce.media.destroy', $media))
        ->assertSessionHasNoErrors();

    expect(StoreMedia::count())->toBe(0)
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

/* -------------------------------------------------------------------------- */
/* Tenancy and the composer */
/* -------------------------------------------------------------------------- */

it('⛔ does not show one business the media of another', function () {
    uploadImage($this->store, $this->owner, $this->business);

    $other = Business::create(['name' => 'Azmion', 'slug' => 'azmion']);
    app(BusinessContext::class)->set($other->id);

    expect(StoreMedia::count())->toBe(0);
});

it('offers the composer only the media of the page\'s own store', function () {
    uploadImage($this->store, $this->owner, $this->business, ['alt_text' => 'Ours']);

    $other = Store::create(['key' => 'econic', 'name' => 'Econic', 'order_prefix' => 'ECN']);
    StoreMedia::create([
        'store_id' => $other->id, 'disk' => 'local', 'path' => 'theirs.jpg',
        'original_name' => 'theirs.jpg', 'mime_type' => 'image/jpeg',
    ]);

    $page = mediaPage($this->store);

    mediaUser($this->owner, $this->business)
        ->get(route('commerce.pages.edit', $page))
        ->assertOk()
        ->assertInertia(function ($inertia) {
            $names = collect($inertia->toArray()['props']['media'])->pluck('original_name');

            // ⚠ The surest way to stop a section reaching for another shop's
            // photography is never to offer it.
            expect($names)->toContain('jar.jpg')->and($names)->not->toContain('theirs.jpg');
        });
});
