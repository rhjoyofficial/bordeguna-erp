<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * Live dashboard channels (Joy, 11 Oct 2026: "use WebSockets now").
 *
 * ⚠⚠ A CHANNEL CARRIES NO FIGURES. Every event on these channels names what
 * happened, record ids and the widget keys to refresh, nothing else. Each
 * widget then re-reads itself through its own authorised endpoint
 * (GET /dashboard/widgets/{key}), so being subscribed to a business never shows
 * anybody a number their permissions do not already allow.
 */

// Every member of the business, and nobody else. An inactive login is refused
// (EnsureUserIsActive signs them out on the next page anyway).
Broadcast::channel('business.{businessId}', function (User $user, int $businessId): bool {
    return $user->is_active !== false
        && ($user->businesses()->where('businesses.id', $businessId)->exists() || $user->is_super_admin);
});

// Personal events: approvals assigned to me, my leave decided, my payslip ready.
Broadcast::channel('user.{userId}', function (User $user, int $userId): bool {
    return (int) $user->id === $userId;
});
