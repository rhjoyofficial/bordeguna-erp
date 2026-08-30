<?php

namespace App\Modules\Foundation\Support\Ai;

use App\Models\User;
use App\Modules\Foundation\Models\Note;
use App\Modules\Foundation\Models\PersonalTodo;
use App\Modules\Foundation\Support\BusinessContext;
use RuntimeException;

/**
 * The only thing in the data assistant that may write, and it may write exactly
 * two things: the asker's own notes, and the asker's own to-do list.
 *
 * ⚠⚠ THE OWNER IS ALWAYS THE CALLER, TAKEN FROM THE SESSION. Every method here
 * receives the User from the controller's `$request->user()`, never from
 * anything the model produced. There is deliberately no parameter naming a
 * person, so there is no value the model could supply to reach somebody else's
 * row - not by being clever, and not by being asked nicely.
 *
 * ⚠⚠ THIS DOES NOT USE THE READ-ONLY CONNECTION, and must not. That connection's
 * credential cannot write, which is the whole point of it. These two narrow
 * writes go through the ordinary connection, and their safety comes from the
 * scoping below rather than from the credential.
 *
 * ⚠ Update and delete both re-fetch the row scoped to the owner before touching
 * it. Finding it by id and then checking who owns it would be the same thing
 * written in the order that eventually gets the check dropped.
 */
class PersonalItems
{
    public function __construct(private readonly BusinessContext $context) {}

    // ── Notes ───────────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    public function listNotes(User $user, int $limit = 20): array
    {
        return Note::query()
            ->where('created_by', $user->id)
            ->whereNull('archived_at')
            ->latest('id')
            ->limit(min($limit, 50))
            ->get(['id', 'title', 'body', 'is_pinned', 'remind_on'])
            ->map(fn (Note $n) => $n->only(['id', 'title', 'body', 'is_pinned', 'remind_on']))
            ->all();
    }

    /** @return array<string,mixed> */
    public function createNote(User $user, string $title, ?string $body = null, ?string $remindOn = null): array
    {
        $note = Note::create([
            'business_id' => $this->context->id(),
            'title' => $title,
            'body' => $body ?? '',
            // ⚠ Private by default. A note the assistant wrote on somebody's
            // behalf must not quietly appear on a shared board.
            'is_private' => true,
            'remind_on' => $remindOn ?: null,
            'created_by' => $user->id,
        ]);

        return ['id' => $note->id, 'title' => $note->title];
    }

    /** @return array<string,mixed> */
    public function updateNote(User $user, int $id, ?string $title = null, ?string $body = null): array
    {
        $note = $this->ownNote($user, $id);

        $note->update(array_filter([
            'title' => $title,
            'body' => $body,
        ], fn ($value) => $value !== null));

        return ['id' => $note->id, 'title' => $note->title];
    }

    public function deleteNote(User $user, int $id): bool
    {
        return (bool) $this->ownNote($user, $id)->delete();
    }

    // ── To-dos ──────────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    public function listTodos(User $user, bool $includeDone = false, int $limit = 30): array
    {
        return PersonalTodo::query()
            ->mine($user)
            ->when(! $includeDone, fn ($q) => $q->outstanding())
            ->orderByRaw('due_on is null, due_on asc')
            ->limit(min($limit, 100))
            ->get(['id', 'title', 'notes', 'due_on', 'completed_at'])
            ->map(fn (PersonalTodo $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'notes' => $t->notes,
                'due_on' => $t->due_on?->toDateString(),
                'done' => $t->isDone(),
            ])
            ->all();
    }

    /** @return array<string,mixed> */
    public function createTodo(User $user, string $title, ?string $notes = null, ?string $dueOn = null): array
    {
        $todo = PersonalTodo::create([
            'business_id' => $this->context->id(),
            'user_id' => $user->id,
            'title' => $title,
            'notes' => $notes,
            'due_on' => $dueOn ?: null,
        ]);

        return ['id' => $todo->id, 'title' => $todo->title, 'due_on' => $todo->due_on?->toDateString()];
    }

    /** @return array<string,mixed> */
    public function completeTodo(User $user, int $id, bool $done = true): array
    {
        $todo = $this->ownTodo($user, $id);

        $todo->update(['completed_at' => $done ? now() : null]);

        return ['id' => $todo->id, 'title' => $todo->title, 'done' => $todo->isDone()];
    }

    /** @return array<string,mixed> */
    public function updateTodo(User $user, int $id, ?string $title = null, ?string $notes = null, ?string $dueOn = null): array
    {
        $todo = $this->ownTodo($user, $id);

        $todo->update(array_filter([
            'title' => $title,
            'notes' => $notes,
            'due_on' => $dueOn,
        ], fn ($value) => $value !== null));

        return ['id' => $todo->id, 'title' => $todo->title];
    }

    public function deleteTodo(User $user, int $id): bool
    {
        return (bool) $this->ownTodo($user, $id)->delete();
    }

    // ── The scoping, in one place ───────────────────────────────────────────

    /**
     * ⚠⚠ Scoped in the LOOKUP, not checked afterwards. A row belonging to
     * somebody else is simply not found, and the message says the same thing it
     * would for an id that never existed - so the assistant cannot be used to
     * discover which note ids are real.
     */
    private function ownNote(User $user, int $id): Note
    {
        $note = Note::query()->where('id', $id)->where('created_by', $user->id)->first();

        if ($note === null) {
            throw new RuntimeException('You have no note with that number.');
        }

        return $note;
    }

    private function ownTodo(User $user, int $id): PersonalTodo
    {
        $todo = PersonalTodo::query()->where('id', $id)->mine($user)->first();

        if ($todo === null) {
            throw new RuntimeException('You have no to-do with that number.');
        }

        return $todo;
    }
}
