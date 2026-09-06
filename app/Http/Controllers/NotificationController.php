<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The notification inbox and the bell's read actions.
 *
 * ⚠⚠ THE BELL IS THE ONLY DELIVERY MECHANISM. Until 2026-08-23 every approval
 * also sent an email; Joy switched that off ("do not send mail to request
 * correction, ERP notification is enough"), so a notification that cannot be
 * found is a message nobody ever receives.
 *
 * ⚠⚠ WHY THERE IS NOW AN INDEX. This class used to carry a note saying an
 * index was "a page nobody asked for". Production disagreed on 6 Sep 2026:
 * 112 notifications sent, 99 of them unread, and a bell that renders five.
 * Ninety-four notifications were unreachable through any URL in the
 * application, and a read one was gone forever. Joy asked for the upgrade
 * after living with exactly that.
 *
 * ⚠ Scoped to the signed-in user's own notifications by construction: every
 * query is read off `$request->user()`, so there is no id to tamper with that
 * could reach somebody else's row. The read/unread writes keep that shape.
 */
class NotificationController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $user = $request->user();

        $filters = $request->validate([
            'state' => ['nullable', 'in:all,unread,read'],
            'kind' => ['nullable', 'string', 'max:60'],
        ]);

        $state = $filters['state'] ?? 'unread';
        $kind = $filters['kind'] ?? null;

        $query = $user->notifications()->getQuery();

        if ($state === 'unread') {
            $query->whereNull('read_at');
        }

        if ($state === 'read') {
            $query->whereNotNull('read_at');
        }

        // ⚠ `kind` lives inside the JSON payload, not in a column. Filtering
        // on it with a LIKE over `data` rather than a JSON path keeps this
        // working on both MySQL and the SQLite the suite runs on.
        if ($kind) {
            $query->where('data', 'like', '%"kind":"'.$kind.'"%');
        }

        $notifications = $query
            ->latest()
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (DatabaseNotification $n) => [
                'id' => $n->id,
                'summary' => $n->data['summary'] ?? 'Something needs your attention.',
                'url' => $n->data['url'] ?? null,
                'kind' => $n->data['kind'] ?? 'general',
                'at' => $n->created_at?->diffForHumans(),
                'on' => $n->created_at?->format('d M Y, g:i a'),
                'read' => $n->read_at !== null,
            ]);

        return Inertia::render('notifications/index', [
            'notifications' => $notifications,
            'filters' => ['state' => $state, 'kind' => $kind],
            // Built from what this person has actually received, so the filter
            // never offers a type that would return an empty page.
            'kinds' => $this->kindsFor($request),
            'counts' => [
                'all' => $user->notifications()->count(),
                'unread' => $user->unreadNotifications()->count(),
            ],
        ]);
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()?->unreadNotifications->markAsRead();

        return back();
    }

    public function markRead(Request $request, string $notification): RedirectResponse
    {
        $request->user()?->unreadNotifications()
            ->whereKey($notification)
            ->get()
            ->markAsRead();

        return back();
    }

    /**
     * Putting one back to unread, because "read" is often "seen on a phone
     * while doing something else" and the bell is the only reminder there is.
     */
    public function markUnread(Request $request, string $notification): RedirectResponse
    {
        $request->user()?->notifications()
            ->whereKey($notification)
            ->update(['read_at' => null]);

        return back();
    }

    /**
     * The distinct `kind` values this user has ever been sent.
     *
     * @return array<int, string>
     */
    private function kindsFor(Request $request): array
    {
        return $request->user()
            ->notifications()
            ->get(['data'])
            ->map(fn (DatabaseNotification $n) => $n->data['kind'] ?? 'general')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
