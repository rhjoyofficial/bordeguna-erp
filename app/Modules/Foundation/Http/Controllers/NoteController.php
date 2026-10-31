<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Note;
use App\Modules\Foundation\Support\BusinessContext;
use App\Support\PerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The notes board.
 *
 * ⚠⚠ NOTHING HERE DELETES. Archiving is the only way a note leaves the board,
 * because the single thing anybody ever asks of a notes system months later is
 * "what was that thing we wrote down", and a delete button is exactly how that
 * answer stops existing.
 */
class NoteController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'show' => $request->query('show', 'active'),
        ];

        $notes = Note::query()
            ->visibleTo($user)
            ->with('author:id,name')
            ->when($filters['show'] === 'active', fn (Builder $q) => $q->active())
            ->when($filters['show'] === 'archived', fn (Builder $q) => $q->whereNotNull('archived_at'))
            ->when($filters['search'] !== '', function (Builder $q) use ($filters) {
                $term = $filters['search'];
                $q->where(fn (Builder $inner) => $inner->where('title', 'like', "%{$term}%")->orWhere('body', 'like', "%{$term}%"));
            })
            // ⚠ Pinned first, then newest. A board sorted only by date buries
            // the note somebody pinned precisely so it would not be buried.
            ->orderByDesc('is_pinned')
            ->orderByDesc('updated_at')
            ->paginate(PerPage::from())
            ->withQueryString()
            ->through(fn (Note $note) => [
                'id' => $note->id,
                'title' => $note->title,
                'body' => $note->body,
                'is_private' => $note->is_private,
                'is_pinned' => $note->is_pinned,
                'remind_on' => $note->remind_on?->toDateString(),
                'archived' => $note->isArchived(),
                'author' => $note->author?->name,
                'updated' => $note->updated_at?->diffForHumans(),
                // ⚠ Whether THIS reader may change it, decided on the server.
                // A screen that works it out from the author id would have to be
                // told the author id of every private note it must not show.
                'can_edit' => $this->mayChange($note, $request),
            ]);

        return Inertia::render('notes/index', [
            'notes' => $notes,
            'filters' => $filters,
            'counts' => [
                'active' => Note::query()->visibleTo($user)->active()->count(),
                'pinned' => Note::query()->visibleTo($user)->active()->where('is_pinned', true)->count(),
                'archived' => Note::query()->visibleTo($user)->whereNotNull('archived_at')->count(),
            ],
            'can' => ['manage' => $user->can('notes.manage')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Note::create(array_merge($this->validated($request), [
            'business_id' => app(BusinessContext::class)->id(),
            'created_by' => $request->user()->id,
        ]));

        return back()->with('success', 'Note saved.');
    }

    public function update(Request $request, Note $note): RedirectResponse
    {
        $this->assertMayChange($note, $request);

        $note->update($this->validated($request));

        return back()->with('success', 'Note saved.');
    }

    /**
     * ⚠ Archive, not delete. See the class note.
     */
    public function archive(Request $request, Note $note): RedirectResponse
    {
        $this->assertMayChange($note, $request);

        $note->update(['archived_at' => now(), 'is_pinned' => false]);

        return back()->with('success', 'Note archived. It is still searchable under Archived.');
    }

    public function restore(Request $request, Note $note): RedirectResponse
    {
        $this->assertMayChange($note, $request);

        $note->update(['archived_at' => null]);

        return back()->with('success', 'Note back on the board.');
    }

    public function togglePin(Request $request, Note $note): RedirectResponse
    {
        $this->assertMayChange($note, $request);

        $note->update(['is_pinned' => ! $note->is_pinned]);

        return back();
    }

    /**
     * ⚠⚠ A PRIVATE NOTE IS 404, NOT 403, exactly as a private calendar entry is.
     * Answering 403 confirms that note 41 exists and belongs to a colleague,
     * which is the whole of what private was protecting.
     *
     * ⚠ A SHARED note may only be changed by the person who wrote it. This is
     * deliberately stricter than the calendar, and the reason is that the two
     * things are different: a meeting is a fact about the business that anyone
     * may correct, while a note is somebody's words, and silently editing
     * somebody's words is not a correction.
     */
    private function assertMayChange(Note $note, Request $request): void
    {
        abort_if($note->is_private && $note->created_by !== $request->user()->id, 404);
        abort_unless($note->created_by === $request->user()->id, 403);
    }

    private function mayChange(Note $note, Request $request): bool
    {
        return $note->created_by === $request->user()->id;
    }

    /**
     * @return array<string,mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:20000'],
            'is_private' => ['boolean'],
            'is_pinned' => ['boolean'],
            // ⚠ The date this note should come back. It is what stops the board
            // being a drawer nothing ever comes out of.
            'remind_on' => ['nullable', 'date'],
        ]);
    }
}
