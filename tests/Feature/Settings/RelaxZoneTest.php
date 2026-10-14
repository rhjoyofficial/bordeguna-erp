<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\RelaxItem;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Relax zone (14 Oct 2026): Owners and Managers keep the list, everybody
 * signed in opens the page and plays the files, uploads are judged by their
 * bytes, files stream with Range, a paused item is gone from the page and its
 * file, and replacing or deleting never leaves a file behind.
 */
beforeEach(function () {
    $this->withoutVite();
    Storage::fake('local');

    $this->business = Business::create(['name' => 'Relax Co', 'slug' => 'relax-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::findOrCreate('relax.manage', 'web');
    Role::create(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])->givePermissionTo('relax.manage');
    Role::create(['name' => 'Manager', 'business_id' => $this->business->id, 'guard_name' => 'web'])->givePermissionTo('relax.manage');
    Role::create(['name' => 'Cashier', 'business_id' => $this->business->id, 'guard_name' => 'web']);

    $this->owner = rzMember($this->business, 'Owner');
    $this->manager = rzMember($this->business, 'Manager');
    $this->cashier = rzMember($this->business, 'Cashier');
});

function rzMember(Business $business, string $role): User
{
    $user = User::factory()->create();
    $user->businesses()->syncWithoutDetaching([$business->id]);
    setPermissionsTeamId($business->id);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function rzAs(object $t, User $user)
{
    return $t->actingAs($user->fresh())
        ->withSession(['current_business_id' => $t->business->id])
        ->withHeader('X-Business-Id', (string) $t->business->id);
}

/**
 * A REAL uploaded file with these bytes. ⚠ Not UploadedFile::fake(): a fake
 * reports the type its NAME suggests, so it can never prove the server reads
 * the bytes.
 */
function rzUpload(string $name, string $bytes): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'rz');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, null, null, true);
}

/** Bytes finfo reads as audio/mpeg: one MP3 frame header and silence. */
function rzMp3(string $name = 'rain.mp3', int $padding = 600): UploadedFile
{
    return rzUpload($name, "\xFF\xFB\x90\x00".str_repeat("\0", $padding));
}

/** Bytes finfo reads as video/mp4: an ftyp box. */
function rzMp4(string $name = 'river.mp4'): UploadedFile
{
    return rzUpload($name, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\0", 600));
}

/** @return array<string, mixed> a valid form */
function rzForm(array $overrides = []): array
{
    return array_merge([
        'title' => 'Rain on the roof',
        'description' => 'Ten quiet minutes.',
        'kind' => 'audio',
        'youtube' => '',
        'sort_order' => 10,
        'is_active' => 1,
    ], $overrides);
}

it('lets the Owner and the Manager manage the list, and nobody else', function () {
    rzAs($this, $this->owner)->get('/settings/relax')->assertOk();
    rzAs($this, $this->manager)->get('/settings/relax')->assertOk();

    rzAs($this, $this->manager)->post('/settings/relax', rzForm(['file' => rzMp3()]))->assertSessionHasNoErrors();
    expect(RelaxItem::count())->toBe(1);

    $item = RelaxItem::firstOrFail();
    rzAs($this, $this->cashier)->get('/settings/relax')->assertForbidden();
    rzAs($this, $this->cashier)->post('/settings/relax', rzForm(['file' => rzMp3()]))->assertForbidden();
    rzAs($this, $this->cashier)->post("/settings/relax/{$item->id}", rzForm())->assertForbidden();
    rzAs($this, $this->cashier)->patch("/settings/relax/{$item->id}/toggle")->assertForbidden();
    rzAs($this, $this->cashier)->patch("/settings/relax/{$item->id}/move", ['direction' => 'up'])->assertForbidden();
    rzAs($this, $this->cashier)->delete("/settings/relax/{$item->id}")->assertForbidden();

    // The page itself is everybody's.
    rzAs($this, $this->cashier)->get('/relax')->assertOk();
    expect(RelaxItem::count())->toBe(1);
});

it('shares canManageRelax with the screens', function () {
    rzAs($this, $this->manager)->get('/relax')->assertInertia(fn (AssertableInertia $page) => $page->where('canManageRelax', true));
    rzAs($this, $this->cashier)->get('/relax')->assertInertia(fn (AssertableInertia $page) => $page->where('canManageRelax', false));
});

// ⚠ Its own test: actingAs() sticks to the test case, so a guest check after
// a signed-in request would still be signed in.
it('is not reachable by a guest, the files included', function () {
    $item = RelaxItem::create(['title' => 'x', 'kind' => 'audio', 'media_path' => 'relax/x.mp3', 'media_mime' => 'audio/mpeg', 'media_size' => 1, 'is_active' => true]);

    $this->get('/relax')->assertRedirect('/login');
    $this->get('/settings/relax')->assertRedirect('/login');
    $this->get("/relax/{$item->id}/media")->assertRedirect('/login');
    $this->post('/settings/relax', rzForm())->assertRedirect('/login');
});

it('judges an upload by its bytes, its extension and its size', function () {
    // Text wearing an .mp3 name.
    rzAs($this, $this->owner)->post('/settings/relax', rzForm(['file' => rzUpload('song.mp3', str_repeat('just some words ', 100))]))
        ->assertSessionHasErrors('file');
    // A program.
    rzAs($this, $this->owner)->post('/settings/relax', rzForm(['file' => rzUpload('calm.exe', 'MZ'.str_repeat("\x90", 600))]))
        ->assertSessionHasErrors('file');
    // Real MP3 bytes, but an extension outside the list.
    rzAs($this, $this->owner)->post('/settings/relax', rzForm(['file' => rzMp3('rain.exe')]))->assertSessionHasErrors('file');
    // Over 20 MB.
    rzAs($this, $this->owner)->post('/settings/relax', rzForm(['file' => rzMp3('long.mp3', 21 * 1024 * 1024)]))->assertSessionHasErrors('file');
    // A video where audio was asked for, by name and by bytes.
    rzAs($this, $this->owner)->post('/settings/relax', rzForm(['file' => rzUpload('clip.webm', "\x1A\x45\xDF\xA3".str_repeat("\0", 600))]))
        ->assertSessionHasErrors('file');
    // An audio item with no file at all.
    rzAs($this, $this->owner)->post('/settings/relax', rzForm())->assertSessionHasErrors('file');

    expect(RelaxItem::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('relax'))->toBe([]);

    rzAs($this, $this->owner)->post('/settings/relax', rzForm(['file' => rzMp3()]))->assertSessionHasNoErrors();
    rzAs($this, $this->owner)->post('/settings/relax', rzForm(['kind' => 'video', 'title' => 'River', 'file' => rzMp4()]))->assertSessionHasNoErrors();

    $audio = RelaxItem::where('kind', 'audio')->firstOrFail();
    $video = RelaxItem::where('kind', 'video')->firstOrFail();

    // The stored type is the normalised one; the name on disk is random.
    expect($audio->media_mime)->toBe('audio/mpeg')
        ->and($video->media_mime)->toBe('video/mp4')
        ->and($audio->media_path)->toStartWith('relax/')->not->toContain('rain')
        ->and(Storage::disk('local')->exists($audio->media_path))->toBeTrue();
});

it('keeps only a YouTube id, and refuses anything that is not YouTube', function () {
    rzAs($this, $this->owner)->post('/settings/relax', rzForm(['kind' => 'youtube', 'youtube' => 'https://vimeo.com/123456789']))
        ->assertSessionHasErrors('youtube');
    rzAs($this, $this->owner)->post('/settings/relax', rzForm(['kind' => 'youtube', 'youtube' => '<script>alert(1)</script>']))
        ->assertSessionHasErrors('youtube');
    rzAs($this, $this->owner)->post('/settings/relax', rzForm(['kind' => 'youtube', 'youtube' => '']))
        ->assertSessionHasErrors('youtube');

    rzAs($this, $this->owner)->post('/settings/relax', rzForm(['kind' => 'youtube', 'youtube' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10']))
        ->assertSessionHasNoErrors();

    $item = RelaxItem::firstOrFail();
    expect($item->youtube_id)->toBe('dQw4w9WgXcQ')->and($item->media_path)->toBeNull();

    rzAs($this, $this->cashier)->get('/relax')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('relax')
        ->where('items.0.youtube_id', 'dQw4w9WgXcQ')
        ->where('items.0.media_url', null));
});

it('streams a file to anybody signed in, with Range', function () {
    rzAs($this, $this->owner)->post('/settings/relax', rzForm(['file' => rzMp3()]));
    $item = RelaxItem::firstOrFail();

    rzAs($this, $this->cashier)->get("/relax/{$item->id}/media")
        ->assertOk()
        ->assertHeader('Accept-Ranges', 'bytes')
        ->assertHeader('Content-Type', 'audio/mpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', 'sandbox');

    rzAs($this, $this->cashier)->withHeader('Range', 'bytes=0-9')->get("/relax/{$item->id}/media")->assertStatus(206);

    rzAs($this, $this->cashier)->get('/relax')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('items', 1)
        ->where('items.0.kind', 'audio')
        ->where('items.0.media_url', fn (string $url) => str_contains($url, "/relax/{$item->id}/media?v=")));
});

it('hides a paused item from the page and its file from everybody', function () {
    rzAs($this, $this->owner)->post('/settings/relax', rzForm(['file' => rzMp3()]));
    $item = RelaxItem::firstOrFail();

    rzAs($this, $this->owner)->patch("/settings/relax/{$item->id}/toggle")->assertRedirect();
    expect($item->fresh()->is_active)->toBeFalse();

    rzAs($this, $this->cashier)->get("/relax/{$item->id}/media")->assertNotFound();
    rzAs($this, $this->cashier)->get('/relax')->assertInertia(fn (AssertableInertia $page) => $page->has('items', 0));
    // Still on the manager's list, marked paused.
    rzAs($this, $this->owner)->get('/settings/relax')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('settings/relax')
        ->where('items.0.is_active', false));

    rzAs($this, $this->owner)->patch("/settings/relax/{$item->id}/toggle");
    rzAs($this, $this->cashier)->get("/relax/{$item->id}/media")->assertOk();
});

it('answers 404 for a YouTube item media address', function () {
    rzAs($this, $this->owner)->post('/settings/relax', rzForm(['kind' => 'youtube', 'youtube' => 'dQw4w9WgXcQ']));

    rzAs($this, $this->cashier)->get('/relax/'.RelaxItem::firstOrFail()->id.'/media')->assertNotFound();
});

it('never leaves an old file behind on replace, kind change or delete', function () {
    rzAs($this, $this->owner)->post('/settings/relax', rzForm(['file' => rzMp3()]));
    $item = RelaxItem::firstOrFail();
    $first = $item->media_path;

    // Saving without a file keeps it.
    rzAs($this, $this->owner)->post("/settings/relax/{$item->id}", rzForm(['title' => 'Renamed']))->assertSessionHasNoErrors();
    expect($item->fresh()->media_path)->toBe($first)->and($item->fresh()->title)->toBe('Renamed');

    // Replacing drops the old one.
    rzAs($this, $this->owner)->post("/settings/relax/{$item->id}", rzForm(['file' => rzMp3('other.mp3')]))->assertSessionHasNoErrors();
    $second = $item->fresh()->media_path;
    expect($second)->not->toBe($first)
        ->and(Storage::disk('local')->exists($first))->toBeFalse()
        ->and(Storage::disk('local')->exists($second))->toBeTrue();

    // Audio to video needs a new file: the audio file never becomes a video.
    rzAs($this, $this->owner)->post("/settings/relax/{$item->id}", rzForm(['kind' => 'video']))->assertSessionHasErrors('file');
    expect(Storage::disk('local')->exists($second))->toBeTrue();

    // Switching to YouTube drops the file.
    rzAs($this, $this->owner)->post("/settings/relax/{$item->id}", rzForm(['kind' => 'youtube', 'youtube' => 'https://youtu.be/dQw4w9WgXcQ']))
        ->assertSessionHasNoErrors();
    $fresh = $item->fresh();
    expect($fresh->media_path)->toBeNull()
        ->and($fresh->media_mime)->toBeNull()
        ->and(Storage::disk('local')->exists($second))->toBeFalse();

    // Deleting a row deletes its file.
    rzAs($this, $this->owner)->post('/settings/relax', rzForm(['kind' => 'video', 'title' => 'River', 'file' => rzMp4()]));
    $video = RelaxItem::where('kind', 'video')->firstOrFail();
    $path = $video->media_path;

    rzAs($this, $this->owner)->delete("/settings/relax/{$video->id}")->assertRedirect();
    expect(RelaxItem::find($video->id))->toBeNull()
        ->and(Storage::disk('local')->exists($path))->toBeFalse()
        ->and(Storage::disk('local')->allFiles('relax'))->toBe([]);
});

it('moves an item one place up or down', function () {
    foreach (['One', 'Two', 'Three'] as $i => $title) {
        RelaxItem::create(['title' => $title, 'kind' => 'youtube', 'youtube_id' => 'dQw4w9WgXcQ', 'sort_order' => 10, 'is_active' => true]);
    }
    $three = RelaxItem::where('title', 'Three')->firstOrFail();

    rzAs($this, $this->owner)->patch("/settings/relax/{$three->id}/move", ['direction' => 'up'])->assertRedirect();
    expect(RelaxItem::orderBy('sort_order')->orderBy('id')->pluck('title')->all())->toBe(['One', 'Three', 'Two']);

    rzAs($this, $this->owner)->patch("/settings/relax/{$three->id}/move", ['direction' => 'up']);
    rzAs($this, $this->owner)->patch("/settings/relax/{$three->id}/move", ['direction' => 'up']);
    expect(RelaxItem::orderBy('sort_order')->orderBy('id')->pluck('title')->all())->toBe(['Three', 'One', 'Two']);

    rzAs($this, $this->owner)->patch("/settings/relax/{$three->id}/move", ['direction' => 'sideways'])->assertSessionHasErrors('direction');
});

it('writes every change to the activity log', function () {
    rzAs($this, $this->owner)->post('/settings/relax', rzForm(['file' => rzMp3()]));
    $item = RelaxItem::firstOrFail();

    rzAs($this, $this->owner)->post("/settings/relax/{$item->id}", rzForm(['title' => 'Renamed']));
    rzAs($this, $this->owner)->patch("/settings/relax/{$item->id}/toggle");
    rzAs($this, $this->owner)->patch("/settings/relax/{$item->id}/toggle");
    rzAs($this, $this->owner)->delete("/settings/relax/{$item->id}");

    $log = Activity::query()->where('log_name', 'system')->orderBy('id')->get();

    expect($log->pluck('description')->all())->toBe([
        'Added a relax zone item',
        'Changed a relax zone item',
        'Paused a relax zone item',
        'Resumed a relax zone item',
        'Removed a relax zone item',
    ])
        ->and($log->every(fn (Activity $a) => $a->causer_id === $this->owner->id))->toBeTrue()
        ->and($log->first()->properties['relax_item_id'])->toBe($item->id);
});

it('grants relax.manage to every Owner and Manager role through the migration', function () {
    $migration = require base_path('app/Modules/Foundation/database/migrations/2026_10_14_200002_relax_manage_permission.php');

    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-co']);
    $owner = Role::create(['name' => 'Owner', 'business_id' => $other->id, 'guard_name' => 'web']);
    $manager = Role::create(['name' => 'Manager', 'business_id' => $other->id, 'guard_name' => 'web']);
    $staff = Role::create(['name' => 'Staff', 'business_id' => $other->id, 'guard_name' => 'web']);

    $migration->up();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($owner->fresh()->hasPermissionTo('relax.manage'))->toBeTrue()
        ->and($manager->fresh()->hasPermissionTo('relax.manage'))->toBeTrue()
        ->and($staff->fresh()->hasPermissionTo('relax.manage'))->toBeFalse();
});
