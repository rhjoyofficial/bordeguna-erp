<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Note;
use App\Modules\Foundation\Models\PersonalTodo;
use App\Modules\Foundation\Support\Ai\PersonalItems;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The only writing the data assistant may do: the asker's own notes and to-dos.
 *
 * ⚠⚠ EVERY TEST HERE IS AN ATTEMPT TO REACH SOMEBODY ELSE'S ROW. The owner is
 * taken from the session and there is deliberately no parameter naming a person,
 * so these tests supply ids belonging to another user and assert that the
 * attempt lands nowhere. That is the property worth protecting: a to-do list
 * people suspect their manager can read is one nobody writes honestly.
 */
beforeEach(function () {
    // Notes are hidden since 24 Oct 2026 (Joy); the note tools are tested switched on.
    config(['features.notes' => true]);

    $this->business = Business::create(['name' => 'Personal Co', 'slug' => 'personal-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::firstOrCreate(['name' => 'notes.view', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('notes.view');

    $this->me = User::factory()->create();
    $this->me->businesses()->attach($this->business->id);
    $this->me->assignRole('Owner');

    // ⚠ Deliberately the OWNER, the most privileged role in the system, so the
    // tests below prove that privilege does not open somebody else's list.
    $this->colleague = User::factory()->create();
    $this->colleague->businesses()->attach($this->business->id);
    $this->colleague->assignRole('Owner');

    $this->items = app(PersonalItems::class);
});

// ─── To-dos ─────────────────────────────────────────────────────────────────

it('creates a to-do that belongs to the person who asked', function () {
    $created = $this->items->createTodo($this->me, 'Call the mill about mustard oil', null, '2026-09-02');

    $todo = PersonalTodo::query()->withoutGlobalScopes()->find($created['id']);

    expect($todo->user_id)->toBe($this->me->id)
        ->and($todo->business_id)->toBe($this->business->id)
        ->and($todo->completed_at)->toBeNull();
});

it('shows me only my own list, never a colleague top', function () {
    $this->items->createTodo($this->me, 'Mine');
    $this->items->createTodo($this->colleague, 'Theirs');

    $mine = $this->items->listTodos($this->me);

    expect($mine)->toHaveCount(1)
        ->and($mine[0]['title'])->toBe('Mine');
});

it('refuses to complete a colleague to-do, even for an Owner', function () {
    $theirs = $this->items->createTodo($this->colleague, 'Their private task');

    // ⚠⚠ Both users hold the Owner role. Privilege is not the question - the
    // row simply is not theirs to touch.
    expect(fn () => $this->items->completeTodo($this->me, $theirs['id']))
        ->toThrow(RuntimeException::class, 'You have no to-do with that number.');

    expect(PersonalTodo::query()->withoutGlobalScopes()->find($theirs['id'])->completed_at)->toBeNull();
});

it('refuses to edit or delete a colleague to-do', function () {
    $theirs = $this->items->createTodo($this->colleague, 'Their private task');

    expect(fn () => $this->items->updateTodo($this->me, $theirs['id'], 'Hijacked'))
        ->toThrow(RuntimeException::class);

    expect(fn () => $this->items->deleteTodo($this->me, $theirs['id']))
        ->toThrow(RuntimeException::class);

    $still = PersonalTodo::query()->withoutGlobalScopes()->find($theirs['id']);

    expect($still)->not->toBeNull()
        ->and($still->title)->toBe('Their private task');
});

it('says the same thing for a row that is not mine as for one that never existed', function () {
    // ⚠ Otherwise the assistant becomes a way to discover which ids are real.
    $theirs = $this->items->createTodo($this->colleague, 'Theirs');

    $notMine = null;
    $neverExisted = null;

    try {
        $this->items->completeTodo($this->me, $theirs['id']);
    } catch (RuntimeException $e) {
        $notMine = $e->getMessage();
    }

    try {
        $this->items->completeTodo($this->me, 99999999);
    } catch (RuntimeException $e) {
        $neverExisted = $e->getMessage();
    }

    expect($notMine)->toBe($neverExisted);
});

it('completes and un-completes my own to-do', function () {
    $mine = $this->items->createTodo($this->me, 'Send the payslips');

    expect($this->items->completeTodo($this->me, $mine['id'])['done'])->toBeTrue();
    expect($this->items->completeTodo($this->me, $mine['id'], false)['done'])->toBeFalse();
});

// ─── Notes ──────────────────────────────────────────────────────────────────

it('writes a note as private by default', function () {
    // ⚠ A note the assistant wrote on somebody's behalf must not quietly appear
    // on a board other people read.
    $created = $this->items->createNote($this->me, 'Supplier called', 'Wants payment by Thursday');

    $note = Note::query()->withoutGlobalScopes()->find($created['id']);

    expect($note->is_private)->toBeTrue()
        ->and($note->created_by)->toBe($this->me->id);
});

it('refuses to read, change or delete a colleague note', function () {
    $theirs = $this->items->createNote($this->colleague, 'Their note', 'private thoughts');

    expect($this->items->listNotes($this->me))->toHaveCount(0);

    expect(fn () => $this->items->updateNote($this->me, $theirs['id'], 'Hijacked'))
        ->toThrow(RuntimeException::class, 'You have no note with that number.');

    expect(fn () => $this->items->deleteNote($this->me, $theirs['id']))
        ->toThrow(RuntimeException::class);

    expect(Note::query()->withoutGlobalScopes()->find($theirs['id'])->title)->toBe('Their note');
});

it('deletes my own note when I ask', function () {
    // ⚠ The one destructive capability in the whole design, and its blast
    // radius is one row belonging to the person who asked for it.
    $mine = $this->items->createNote($this->me, 'Throwaway');

    expect($this->items->deleteNote($this->me, $mine['id']))->toBeTrue()
        ->and(Note::query()->withoutGlobalScopes()->find($mine['id']))->toBeNull();
});
