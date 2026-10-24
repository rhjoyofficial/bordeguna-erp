<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Note;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The notes board.
 *
 * ⚠⚠ THE FAILURE THIS MODULE WAS ONCE ADVISED AGAINST is not a bug, it is that
 * nobody ever opens it again. `remind_on` is the answer, and the test that puts
 * a note on the calendar is therefore the most important one in this file: it
 * pins the only mechanism that makes a note come back.
 */
beforeEach(function () {
    // Hidden since 24 Oct 2026 (Joy); these tests prove the feature still works when switched back on.
    config(['features.notes' => true, 'features.calendar' => true]);

    $this->business = Business::create(['name' => 'Note Co', 'slug' => 'note-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['notes.view', 'notes.manage', 'calendar.view'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    Role::firstOrCreate(['name' => 'Staff', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['notes.view', 'notes.manage', 'calendar.view']);

    $this->writer = User::factory()->create();
    $this->writer->businesses()->attach($this->business->id);
    $this->writer->assignRole('Staff');

    $this->colleague = User::factory()->create();
    $this->colleague->businesses()->attach($this->business->id);
    $this->colleague->assignRole('Staff');
});

it('shares a note with the business by default', function () {
    $this->actingAs($this->writer)->post(route('notes.store'), ['title' => 'Mill contact', 'body' => 'Ask for the rate card'])
        ->assertRedirect();

    // ⚠ Shared is the default because a board only some people can read is one
    // nobody writes on. Private is the exception you reach for.
    expect(Note::first()->is_private)->toBeFalse();

    $this->actingAs($this->colleague)->get('/notes')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('notes/index')->where('notes.total', 1));
});

it('keeps a private note out of everybody else"s board', function () {
    Note::create(['business_id' => $this->business->id, 'title' => 'My own', 'is_private' => true, 'created_by' => $this->writer->id]);
    Note::create(['business_id' => $this->business->id, 'title' => 'Shared', 'created_by' => $this->writer->id]);

    $this->actingAs($this->writer)->get('/notes')
        ->assertInertia(fn ($page) => $page->where('notes.total', 2));

    $this->actingAs($this->colleague)->get('/notes')
        ->assertInertia(fn ($page) => $page->where('notes.total', 1)->where('notes.data.0.title', 'Shared'));
});

it('answers 404 rather than 403 when somebody reaches for another person"s private note', function () {
    $note = Note::create(['business_id' => $this->business->id, 'title' => 'My own', 'is_private' => true, 'created_by' => $this->writer->id]);

    // ⚠⚠ 403 would confirm the note exists and belongs to a colleague, which is
    // the whole of what private was protecting.
    $this->actingAs($this->colleague)->post(route('notes.archive', $note->id))->assertNotFound();
});

it('will not let one person silently rewrite another person"s words', function () {
    $note = Note::create(['business_id' => $this->business->id, 'title' => 'Shared', 'created_by' => $this->writer->id]);

    // ⚠ Deliberately stricter than the calendar. A meeting is a fact about the
    // business that anyone may correct; a note is somebody's words, and editing
    // those is not a correction.
    $this->actingAs($this->colleague)->put(route('notes.update', $note->id), ['title' => 'Rewritten'])->assertForbidden();

    expect($note->fresh()->title)->toBe('Shared');

    $this->actingAs($this->colleague)->get('/notes')
        ->assertInertia(fn ($page) => $page->where('notes.data.0.can_edit', false));
});

it('archives instead of deleting, and keeps the note findable afterwards', function () {
    $note = Note::create(['business_id' => $this->business->id, 'title' => 'Old rate card', 'is_pinned' => true, 'created_by' => $this->writer->id]);

    $this->actingAs($this->writer)->post(route('notes.archive', $note->id))->assertRedirect();

    // ⚠⚠ Still there. The one thing anybody asks of a notes system months later
    // is "what was that thing we wrote down".
    expect(Note::count())->toBe(1)
        ->and($note->fresh()->archived_at)->not->toBeNull()
        // ⚠ And unpinned, or an archived note keeps the top of a board it is no
        // longer on.
        ->and($note->fresh()->is_pinned)->toBeFalse();

    $this->actingAs($this->writer)->get('/notes?show=active')
        ->assertInertia(fn ($page) => $page->where('notes.total', 0));

    $this->actingAs($this->writer)->get('/notes?show=archived')
        ->assertInertia(fn ($page) => $page->where('notes.total', 1));

    $this->actingAs($this->writer)->post(route('notes.restore', $note->id))->assertRedirect();

    expect($note->fresh()->archived_at)->toBeNull();
});

it('puts pinned notes above newer ones', function () {
    Note::create(['business_id' => $this->business->id, 'title' => 'Newer', 'created_by' => $this->writer->id]);
    $pinned = Note::create(['business_id' => $this->business->id, 'title' => 'Pinned', 'is_pinned' => true, 'created_by' => $this->writer->id]);
    $pinned->update(['updated_at' => now()->subYear()]);

    // ⚠ A board sorted only by date buries the note somebody pinned precisely
    // so that it would not be buried.
    $this->actingAs($this->writer)->get('/notes')
        ->assertInertia(fn ($page) => $page->where('notes.data.0.title', 'Pinned'));
});

it('finds a note by words inside it, not only by its title', function () {
    Note::create(['business_id' => $this->business->id, 'title' => 'Mill', 'body' => 'Razzak quoted 118 per litre', 'created_by' => $this->writer->id]);
    Note::create(['business_id' => $this->business->id, 'title' => 'Something else', 'created_by' => $this->writer->id]);

    $this->actingAs($this->writer)->get('/notes?search=Razzak')
        ->assertInertia(fn ($page) => $page->where('notes.total', 1)->where('notes.data.0.title', 'Mill'));
});

it('brings a note back on the calendar on the day it asked for', function () {
    Note::create([
        'business_id' => $this->business->id, 'title' => 'Chase the mill document',
        'remind_on' => '2026-09-14', 'created_by' => $this->writer->id,
    ]);

    // ⚠⚠ THE WHOLE REASON THIS MODULE IS NOT A DRAWER. Without this the note is
    // written once and never seen again.
    $this->actingAs($this->writer)->get('/calendar?month=2026-09')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('days', fn ($days) => collect($days)
            ->flatMap(fn ($d) => collect((array) $d)->get('items', []))
            ->contains(fn ($i) => $i['type'] === 'note' && $i['title'] === 'Chase the mill document')));
});

it('does not put another person"s private note on your calendar', function () {
    Note::create([
        'business_id' => $this->business->id, 'title' => 'Personal appointment', 'is_private' => true,
        'remind_on' => '2026-09-14', 'created_by' => $this->writer->id,
    ]);

    // ⚠ A reminder shows the note's TITLE, so a private note's reminder is
    // private too. The calendar had to be told; it does not inherit it.
    $this->actingAs($this->colleague)->get('/calendar?month=2026-09')
        ->assertInertia(fn ($page) => $page->where('days', fn ($days) => ! collect($days)
            ->flatMap(fn ($d) => collect((array) $d)->get('items', []))
            ->contains(fn ($i) => $i['type'] === 'note')));
});

it('stops reminding once a note is archived', function () {
    $note = Note::create([
        'business_id' => $this->business->id, 'title' => 'Done with this',
        'remind_on' => '2026-09-14', 'created_by' => $this->writer->id,
    ]);

    $this->actingAs($this->writer)->post(route('notes.archive', $note->id));

    // ⚠ Archiving is how somebody says they are finished with it. A reminder
    // afterwards would make archiving pointless.
    $this->actingAs($this->writer)->get('/calendar?month=2026-09')
        ->assertInertia(fn ($page) => $page->where('days', fn ($days) => ! collect($days)
            ->flatMap(fn ($d) => collect((array) $d)->get('items', []))
            ->contains(fn ($i) => $i['type'] === 'note')));
});

it('never shows one business the notes of another', function () {
    Note::create(['business_id' => $this->business->id, 'title' => 'Ours', 'created_by' => $this->writer->id]);

    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-co']);
    Note::withoutGlobalScopes()->create(['business_id' => $other->id, 'title' => 'Theirs', 'created_by' => $this->writer->id]);

    $this->actingAs($this->writer)->get('/notes')
        ->assertInertia(fn ($page) => $page->where('notes.total', 1)->where('notes.data.0.title', 'Ours'));
});

it('refuses the board to a login that holds no notes permission', function () {
    $nobody = User::factory()->create();
    $nobody->businesses()->attach($this->business->id);

    $this->actingAs($nobody)->get('/notes')->assertForbidden();
});

// Add and edit are one popup over the board since 14 Oct 2026 (the form no
// longer sits above it). The page carries what the popup fills an edit with,
// and whether this person may write at all; the popup saves through the same
// routes.
it('carries what the add and edit popup needs, and saves through the same routes', function () {
    $this->actingAs($this->writer)->post(route('notes.store'), [
        'title' => 'Mill contact', 'body' => 'Ask for the rate card', 'is_private' => false, 'is_pinned' => true, 'remind_on' => '2026-11-02',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $note = Note::query()->sole();

    $this->actingAs($this->writer)->get('/notes')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('notes/index')
            ->where('can.manage', true)
            ->where('notes.data.0.id', $note->id)
            ->where('notes.data.0.body', 'Ask for the rate card')
            ->where('notes.data.0.is_pinned', true)
            ->where('notes.data.0.remind_on', '2026-11-02')
            ->where('notes.data.0.can_edit', true));

    $this->actingAs($this->writer)->put(route('notes.update', $note->id), [
        'title' => 'Mill contact (Rahim)', 'body' => 'Rate card received', 'is_private' => true, 'is_pinned' => false, 'remind_on' => '',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($note->fresh()->title)->toBe('Mill contact (Rahim)')
        ->and($note->fresh()->is_private)->toBeTrue()
        ->and($note->fresh()->remind_on)->toBeNull();

    // A colleague sees the board but not an edit on somebody else's note.
    Note::query()->whereKey($note->id)->update(['is_private' => false]);
    $this->actingAs($this->colleague)->get('/notes')
        ->assertInertia(fn ($page) => $page->where('notes.data.0.can_edit', false));
});
