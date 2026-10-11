<?php

namespace App\Support\Dashboards;

use App\Models\User;

/**
 * Whose data a widget reads. Part of the cache key, so a team or a self widget
 * can never hand one person's figures to another (ruling 10).
 */
enum WidgetScope: string
{
    /** The whole current business: the same figure for everybody allowed to see it. */
    case Business = 'business';

    /** The people the viewer supervises (or the business, for who manages all of it). */
    case Team = 'team';

    /** The viewer alone. */
    case Self = 'self';

    public function cacheFragment(User $user): string
    {
        return $this === self::Business ? 'b' : 'u'.$user->id;
    }
}
