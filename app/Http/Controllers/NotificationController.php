<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Marking the bell's notifications as read.
 *
 * ⚠ No index screen. The bell shows the unread ones and every notification
 * carries a url to the thing it is about, which for approvals is the approvals
 * screen that already exists. A separate inbox listing read notifications is a
 * page nobody asked for, and the approvals queue is the real work list.
 *
 * ⚠ Scoped to the signed-in user's own notifications by construction: the
 * relation is read off `$request->user()`, so there is no id to tamper with
 * that could reach somebody else's row.
 */
class NotificationController extends Controller
{
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
}
