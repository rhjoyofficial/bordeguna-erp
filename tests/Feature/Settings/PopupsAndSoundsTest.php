<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Popup;
use App\Modules\Foundation\Models\PopupView;
use App\Modules\Foundation\Models\Sound;
use App\Modules\Foundation\Models\SoundSlot;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Popups and sounds (14 Oct 2026): Owner-only CRUD, the built-in Bismillah
 * that can be edited and paused but never deleted, uploads that are checked
 * by their bytes, a sound in use that cannot be deleted, the once-rule claim
 * that only one tab wins, and the feed every page is handed. Asserted on the
 * SHARED PROP where the screen reads it.
 */
beforeEach(function () {
    $this->withoutVite();
    Storage::fake('local');

    $this->business = Business::create(['name' => 'Popup Co', 'slug' => 'popup-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::findOrCreate('system.popups.manage', 'web');
    Role::create(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])->givePermissionTo('system.popups.manage');
    Role::create(['name' => 'Cashier', 'business_id' => $this->business->id, 'guard_name' => 'web']);

    $this->owner = psMember($this->business, 'Owner');
    $this->cashier = psMember($this->business, 'Cashier');
});

function psMember(Business $business, string $role): User
{
    $user = User::factory()->create();
    $user->businesses()->syncWithoutDetaching([$business->id]);
    setPermissionsTeamId($business->id);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function psAs(object $t, User $user)
{
    // ⚠ Like production: no business is resolved until the middleware does
    // it. Setting it in beforeEach and leaving it set hid FND-001 (14 Oct):
    // the screens 403'd for every Owner on the live site and passed here.
    app(BusinessContext::class)->forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $t->actingAs($user->fresh())
        ->withSession(['current_business_id' => $t->business->id])
        ->withHeader('X-Business-Id', (string) $t->business->id);
}

/**
 * A REAL uploaded file with these bytes. ⚠ Not UploadedFile::fake(): a fake
 * reports the type its NAME suggests, so it can never prove the server reads
 * the bytes. This one goes through finfo like a browser upload does.
 */
function psUpload(string $name, string $bytes): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'ps');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, null, null, true);
}

/** Bytes finfo reads as audio/mpeg: one MP3 frame header and silence. */
function psMp3(string $name = 'ding.mp3'): UploadedFile
{
    return psUpload($name, "\xFF\xFB\x90\x00".str_repeat("\0", 600));
}

/** Bytes finfo reads as video/mp4: an ftyp box. */
function psMp4(string $name = 'clip.mp4'): UploadedFile
{
    return psUpload($name, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\0", 600));
}

/** @return array<string, mixed> a valid popup form */
function psForm(array $overrides = []): array
{
    return array_merge([
        'title' => 'Office notice',
        'lines' => [['kind' => 'heading', 'text' => 'Door closes at 6'], ['kind' => 'note', 'text' => 'Thanks']],
        'style' => 'notice',
        'on_login' => 1,
        'idle_minutes' => '',
        'audience_roles' => [],
        'frequency' => 'every',
        'starts_on' => '',
        'ends_on' => '',
        'sound_mode' => 'default',
        'sound_id' => '',
        'close_after_seconds' => 6,
        'priority' => 50,
        'is_active' => 1,
        'media_type' => 'none',
        'youtube' => '',
    ], $overrides);
}

it('opens the two screens only for whoever holds system.popups.manage', function () {
    psAs($this, $this->owner)->get('/settings/sounds')->assertOk();
    psAs($this, $this->owner)->get('/settings/popups')->assertOk();
    psAs($this, $this->cashier)->get('/settings/sounds')->assertForbidden();
    psAs($this, $this->cashier)->get('/settings/popups')->assertForbidden();
    psAs($this, $this->cashier)->post('/settings/popups', psForm())->assertForbidden();
});

// ⚠ Its own test: actingAs() sticks to the test case, so a guest check after
// a signed-in request would still be signed in.
it('is not reachable by a guest, the files and the claim included', function () {
    $bismillah = Popup::query()->where('key', Popup::BISMILLAH)->firstOrFail();
    $sound = Sound::create(['name' => 'x', 'disk' => 'local', 'path' => 'sounds/x.mp3', 'mime_type' => 'audio/mpeg', 'size' => 1]);

    $this->get('/settings/popups')->assertRedirect('/login');
    $this->get('/settings/sounds')->assertRedirect('/login');
    $this->get("/sounds/{$sound->id}/file")->assertRedirect('/login');
    $this->get("/popups/{$bismillah->id}/media")->assertRedirect('/login');
    $this->postJson("/popups/{$bismillah->id}/claim")->assertUnauthorized();
});

it('seeds the Bismillah popup and hands it to every signed-in page', function () {
    $bismillah = Popup::query()->where('key', Popup::BISMILLAH)->first();

    expect($bismillah)->not->toBeNull()
        ->and($bismillah->on_login)->toBeTrue()
        ->and($bismillah->idle_minutes)->toBe(60)
        ->and($bismillah->close_after_seconds)->toBe(6);

    psAs($this, $this->cashier)->get('/settings/appearance')->assertInertia(fn ($page) => $page
        ->where('popups.enabled', true)
        ->where('popups.sound', true)
        ->where('popups.quiet_hours', null)
        ->where('popups.sounds.popup', null)
        ->has('popups.items', 1)
        ->where('popups.items.0.key', 'bismillah')
        ->where('popups.items.0.sound', null)
        ->where('popups.items.0.media', null));
});

it('lets the Owner edit and pause the built-in popup but refuses to delete it', function () {
    $bismillah = Popup::query()->where('key', Popup::BISMILLAH)->firstOrFail();

    psAs($this, $this->owner)->post("/settings/popups/{$bismillah->id}", psForm(['title' => 'Bismillah', 'idle_minutes' => 30]))->assertSessionHasNoErrors();
    expect($bismillah->fresh()->idle_minutes)->toBe(30)->and($bismillah->fresh()->key)->toBe('bismillah');

    psAs($this, $this->owner)->patch("/settings/popups/{$bismillah->id}/toggle")->assertRedirect();
    expect($bismillah->fresh()->is_active)->toBeFalse();
    psAs($this, $this->cashier)->get('/settings/appearance')->assertInertia(fn ($page) => $page->has('popups.items', 0));

    psAs($this, $this->owner)->delete("/settings/popups/{$bismillah->id}")->assertSessionHas('error');
    expect(Popup::query()->where('key', Popup::BISMILLAH)->exists())->toBeTrue();
});

it('validates a popup: when it shows, the idle minimum, the date window', function () {
    psAs($this, $this->owner)->post('/settings/popups', psForm(['on_login' => 0, 'idle_minutes' => '']))->assertSessionHasErrors('on_login');
    psAs($this, $this->owner)->post('/settings/popups', psForm(['idle_minutes' => 2]))->assertSessionHasErrors('idle_minutes');
    psAs($this, $this->owner)->post('/settings/popups', psForm(['starts_on' => '2026-10-20', 'ends_on' => '2026-10-10']))->assertSessionHasErrors('ends_on');
    psAs($this, $this->owner)->post('/settings/popups', psForm(['style' => 'blink']))->assertSessionHasErrors('style');
    psAs($this, $this->owner)->post('/settings/popups', psForm(['sound_mode' => 'custom', 'sound_id' => '']))->assertSessionHasErrors('sound_id');
    expect(Popup::query()->count())->toBe(1);
});

it('stores a popup for one role only, and the feed respects the role, the window and the switch', function () {
    psAs($this, $this->owner)->post('/settings/popups', psForm(['audience_roles' => ['Owner'], 'priority' => 200]))->assertSessionHasNoErrors();

    psAs($this, $this->owner)->get('/settings/appearance')->assertInertia(fn ($page) => $page
        ->has('popups.items', 2)
        ->where('popups.items.0.title', 'Office notice'));
    psAs($this, $this->cashier)->get('/settings/appearance')->assertInertia(fn ($page) => $page->has('popups.items', 1));

    $notice = Popup::query()->where('title', 'Office notice')->firstOrFail();
    $notice->update(['audience_roles' => null, 'ends_on' => now()->subDay()->toDateString()]);
    psAs($this, $this->cashier)->get('/settings/appearance')->assertInertia(fn ($page) => $page->has('popups.items', 1));

    $notice->update(['ends_on' => null, 'starts_on' => now()->toDateString()]);
    psAs($this, $this->cashier)->get('/settings/appearance')->assertInertia(fn ($page) => $page->has('popups.items', 2));

    psAs($this, $this->cashier)->patch('/settings/greeting', ['enabled' => false])->assertSessionHasNoErrors();
    psAs($this, $this->cashier)->patch('/settings/popup-sound', ['enabled' => false])->assertSessionHasNoErrors();
    psAs($this, $this->cashier)->get('/settings/appearance')->assertInertia(fn ($page) => $page
        ->where('popups.enabled', false)
        ->where('popups.sound', false));
});

it('claims a once-rule popup for one tab only, and drops it from the feed afterwards', function () {
    psAs($this, $this->owner)->post('/settings/popups', psForm(['frequency' => 'once']))->assertSessionHasNoErrors();
    psAs($this, $this->owner)->post('/settings/popups', psForm(['title' => 'Daily', 'frequency' => 'daily']))->assertSessionHasNoErrors();
    $once = Popup::query()->where('title', 'Office notice')->firstOrFail();
    $daily = Popup::query()->where('title', 'Daily')->firstOrFail();
    $bismillah = Popup::query()->where('key', Popup::BISMILLAH)->firstOrFail();

    psAs($this, $this->cashier)->postJson("/popups/{$once->id}/claim")->assertOk()->assertJson(['shown' => true]);
    psAs($this, $this->cashier)->postJson("/popups/{$once->id}/claim")->assertOk()->assertJson(['shown' => false]);
    psAs($this, $this->cashier)->postJson("/popups/{$daily->id}/claim")->assertJson(['shown' => true]);
    psAs($this, $this->cashier)->postJson("/popups/{$daily->id}/claim")->assertJson(['shown' => false]);
    psAs($this, $this->cashier)->postJson("/popups/{$bismillah->id}/claim")->assertJson(['shown' => true]);
    psAs($this, $this->cashier)->postJson("/popups/{$bismillah->id}/claim")->assertJson(['shown' => true]);
    // Another person has their own claim.
    psAs($this, $this->owner)->postJson("/popups/{$once->id}/claim")->assertJson(['shown' => true]);

    expect(PopupView::query()->where('user_id', $this->cashier->id)->count())->toBe(2);
    psAs($this, $this->cashier)->get('/settings/appearance')->assertInertia(fn ($page) => $page
        ->has('popups.items', 1)
        ->where('popups.items.0.key', 'bismillah'));

    $daily->update(['is_active' => false]);
    psAs($this, $this->cashier)->postJson("/popups/{$daily->id}/claim")->assertNotFound();
});

it('takes a sound by its bytes, serves it to signed-in people only, and refuses what is not audio', function () {
    psAs($this, $this->owner)->post('/settings/sounds', ['name' => 'Ding', 'file' => psMp3(), 'duration_ms' => 1200])->assertSessionHasNoErrors();

    $sound = Sound::query()->firstOrFail();
    expect($sound->name)->toBe('Ding')
        ->and($sound->mime_type)->toBe('audio/mpeg')
        ->and($sound->duration_ms)->toBe(1200)
        ->and($sound->path)->toStartWith('sounds/')
        ->and($sound->path)->not->toContain('ding');
    Storage::disk('local')->assertExists($sound->path);

    psAs($this, $this->cashier)->get("/sounds/{$sound->id}/file")
        ->assertOk()
        ->assertHeader('Content-Type', 'audio/mpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    psAs($this, $this->owner)->post('/settings/sounds', ['file' => psUpload('notes.mp3', 'just text')])->assertSessionHasErrors('file');
    psAs($this, $this->owner)->post('/settings/sounds', ['file' => UploadedFile::fake()->createWithContent('clip.exe', "\xFF\xFB\x90\x00")])->assertSessionHasErrors('file');
    psAs($this, $this->owner)->post('/settings/sounds', ['file' => UploadedFile::fake()->create('big.mp3', 2048, 'audio/mpeg')])->assertSessionHasErrors('file');
    expect(Sound::query()->count())->toBe(1);
});

it('maps events to library sounds, refuses to delete a sound in use, and replaces one keeping its links', function () {
    psAs($this, $this->owner)->post('/settings/sounds', ['name' => 'Ding', 'file' => psMp3()])->assertSessionHasNoErrors();
    $sound = Sound::query()->firstOrFail();

    psAs($this, $this->owner)->put('/settings/sounds/slots', ['slots' => ['success' => $sound->id, 'popup' => $sound->id, 'error' => null]])->assertSessionHasNoErrors();
    expect(SoundSlot::query()->find('success')->sound_id)->toBe($sound->id)
        ->and(SoundSlot::query()->find('attention')->sound_id)->toBeNull();

    psAs($this, $this->cashier)->get('/settings/appearance')->assertInertia(fn ($page) => $page
        ->where('popups.sounds.success', $sound->url())
        ->where('popups.sounds.attention', null)
        ->where('popups.items.0.sound', $sound->url()));

    psAs($this, $this->owner)->delete("/settings/sounds/{$sound->id}")->assertSessionHas('error');
    expect(Sound::query()->count())->toBe(1);

    $before = $sound->path;
    psAs($this, $this->owner)->post("/settings/sounds/{$sound->id}", ['name' => 'Ding 2', 'file' => psMp3('ding2.mp3')])->assertSessionHasNoErrors();
    $sound->refresh();
    expect($sound->name)->toBe('Ding 2')->and($sound->path)->not->toBe($before);
    Storage::disk('local')->assertMissing($before);
    Storage::disk('local')->assertExists($sound->path);
    expect(SoundSlot::query()->find('popup')->sound_id)->toBe($sound->id);

    psAs($this, $this->owner)->put('/settings/sounds/slots', ['slots' => ['success' => null, 'popup' => null]])->assertSessionHasNoErrors();
    psAs($this, $this->owner)->delete("/settings/sounds/{$sound->id}")->assertSessionHas('success');
    expect(Sound::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($sound->path);
});

it('reads a YouTube address down to its id and refuses anything else', function () {
    expect(Popup::youtubeId('https://www.youtube.com/watch?v=dQw4w9WgXcQ'))->toBe('dQw4w9WgXcQ')
        ->and(Popup::youtubeId('https://youtu.be/dQw4w9WgXcQ?t=5'))->toBe('dQw4w9WgXcQ')
        ->and(Popup::youtubeId('https://m.youtube.com/shorts/dQw4w9WgXcQ'))->toBe('dQw4w9WgXcQ')
        ->and(Popup::youtubeId('dQw4w9WgXcQ'))->toBe('dQw4w9WgXcQ')
        ->and(Popup::youtubeId('https://example.com/watch?v=dQw4w9WgXcQ'))->toBeNull()
        ->and(Popup::youtubeId('https://www.youtube.com/watch?v=short'))->toBeNull()
        ->and(Popup::youtubeId('javascript:alert(1)'))->toBeNull();

    psAs($this, $this->owner)->post('/settings/popups', psForm(['media_type' => 'youtube', 'youtube' => 'https://example.com/x']))->assertSessionHasErrors('youtube');
    psAs($this, $this->owner)->post('/settings/popups', psForm(['media_type' => 'youtube', 'youtube' => 'https://youtu.be/dQw4w9WgXcQ']))->assertSessionHasNoErrors();

    $popup = Popup::query()->where('title', 'Office notice')->firstOrFail();
    expect($popup->youtube_id)->toBe('dQw4w9WgXcQ');

    // A video popup: no sound, no auto-close, the person closes it.
    psAs($this, $this->cashier)->get('/settings/appearance')->assertInertia(fn ($page) => $page
        ->where('popups.items.1.media.type', 'youtube')
        ->where('popups.items.1.media.id', 'dQw4w9WgXcQ')
        ->where('popups.items.1.sound', null)
        ->where('popups.items.1.close_after_seconds', null));
});

it('takes an uploaded video by its bytes and streams it with ranges to signed-in people', function () {
    psAs($this, $this->owner)->post('/settings/popups', psForm(['media_type' => 'video', 'video' => psUpload('x.mp4', 'text')]))->assertSessionHasErrors('video');
    psAs($this, $this->owner)->post('/settings/popups', psForm(['media_type' => 'video', 'video' => psMp4()]))->assertSessionHasNoErrors();

    $popup = Popup::query()->where('title', 'Office notice')->firstOrFail();
    expect($popup->media_type)->toBe('video')->and($popup->media_mime)->toBe('video/mp4');
    Storage::disk('local')->assertExists($popup->media_path);

    psAs($this, $this->cashier)->get("/popups/{$popup->id}/media")->assertOk()->assertHeader('Accept-Ranges', 'bytes');
    psAs($this, $this->cashier)->withHeader('Range', 'bytes=0-9')->get("/popups/{$popup->id}/media")->assertStatus(206);

    // Switching the media off removes the file.
    $path = $popup->media_path;
    psAs($this, $this->owner)->post("/settings/popups/{$popup->id}", psForm(['media_type' => 'none']))->assertSessionHasNoErrors();
    expect($popup->fresh()->media_path)->toBeNull();
    Storage::disk('local')->assertMissing($path);
});

it('takes an image by its bytes, slims it, serves it, and keeps the popup sound and auto-close', function () {
    $png = function (): string {
        $image = imagecreatetruecolor(40, 30);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    };

    psAs($this, $this->owner)->post('/settings/popups', psForm(['media_type' => 'image', 'image' => psUpload('x.png', 'not an image')]))->assertSessionHasErrors('image');
    psAs($this, $this->owner)->post('/settings/popups', psForm(['media_type' => 'image', 'image' => psUpload('banner.png', $png())]))->assertSessionHasNoErrors();

    $popup = Popup::query()->where('title', 'Office notice')->firstOrFail();
    expect($popup->media_type)->toBe('image')->and($popup->media_mime)->toStartWith('image/');
    Storage::disk('local')->assertExists($popup->media_path);

    psAs($this, $this->cashier)->get("/popups/{$popup->id}/media")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    psAs($this, $this->cashier)->get('/settings/appearance')->assertInertia(fn ($page) => $page
        ->where('popups.items.1.media.type', 'image')
        ->where('popups.items.1.close_after_seconds', 6));

    // Switching to a video with no file is refused (SWF-003); the image stays.
    $path = $popup->media_path;
    psAs($this, $this->owner)->post("/settings/popups/{$popup->id}", psForm(['media_type' => 'video']))->assertSessionHasErrors('video');
    expect($popup->fresh()->media_type)->toBe('image');
    Storage::disk('local')->assertExists($path);

    // Switching it to no media removes the file.
    psAs($this, $this->owner)->post("/settings/popups/{$popup->id}", psForm(['media_type' => 'none']))->assertSessionHasNoErrors();
    Storage::disk('local')->assertMissing($path);
});

it('refuses an image or video popup saved with no file, and keeps the old file on a later edit (SWF-003)', function () {
    psAs($this, $this->owner)->post('/settings/popups', psForm(['media_type' => 'image']))->assertSessionHasErrors('image');
    psAs($this, $this->owner)->post('/settings/popups', psForm(['media_type' => 'video']))->assertSessionHasErrors('video');
    expect(Popup::query()->count())->toBe(1);

    psAs($this, $this->owner)->post('/settings/popups', psForm(['media_type' => 'video', 'video' => psMp4()]))->assertSessionHasNoErrors();
    $popup = Popup::query()->where('title', 'Office notice')->firstOrFail();

    // Editing the text only keeps the video: no file needed the second time.
    psAs($this, $this->owner)->post("/settings/popups/{$popup->id}", psForm(['title' => 'Edited', 'media_type' => 'video']))->assertSessionHasNoErrors();
    expect($popup->fresh()->media_type)->toBe('video')->and($popup->fresh()->media_path)->not->toBeNull();
});

it('will not let a person claim or watch a popup that is paused, out of its dates or not for them (SWF-005)', function () {
    psAs($this, $this->owner)->post('/settings/popups', psForm(['frequency' => 'once', 'audience_roles' => ['Owner'], 'media_type' => 'video', 'video' => psMp4()]))->assertSessionHasNoErrors();
    $popup = Popup::query()->where('title', 'Office notice')->firstOrFail();

    // Not for a Cashier.
    psAs($this, $this->cashier)->postJson("/popups/{$popup->id}/claim")->assertNotFound();
    expect(PopupView::query()->count())->toBe(0);

    // Out of its window.
    $popup->update(['audience_roles' => null, 'ends_on' => now()->subDay()->toDateString()]);
    psAs($this, $this->cashier)->postJson("/popups/{$popup->id}/claim")->assertNotFound();

    // Paused: the file is for the people who manage popups only.
    $popup->update(['ends_on' => null, 'is_active' => false]);
    psAs($this, $this->cashier)->get("/popups/{$popup->id}/media")->assertNotFound();
    psAs($this, $this->owner)->get("/popups/{$popup->id}/media")->assertOk();
});
