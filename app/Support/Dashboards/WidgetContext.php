<?php

namespace App\Support\Dashboards;

use App\Models\User;

/**
 * Everything a widget's data provider may know about the request: who is
 * looking, in which business, for which range, on which dashboard.
 *
 * ⚠ The brand is a filter on the business's own sales, never a scope: it can
 * narrow what a widget already shows, never reach another business.
 *
 * ⚠ There is deliberately no "employee id" or "user id" filter in here. A self
 * widget reads `$context->user` and nothing a browser could put in a query
 * string, so it cannot be pointed at somebody else (ruling 3).
 */
final class WidgetContext
{
    public function __construct(
        public readonly User $user,
        public readonly int $businessId,
        public readonly DateRange $range,
        public readonly DashboardKey $dashboard,
        // ⭐ Joy, 11 Oct 2026: one brand of THIS business (checked by the
        // controller), or null for all brands. Read only by brand-aware widgets.
        public readonly ?int $brandId = null,
    ) {}
}
