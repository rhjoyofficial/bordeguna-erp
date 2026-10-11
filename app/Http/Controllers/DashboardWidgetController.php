<?php

namespace App\Http\Controllers;

use App\Modules\Foundation\Support\BusinessContext;
use App\Support\Dashboards\DashboardAccess;
use App\Support\Dashboards\DashboardBrand;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\DateRange;
use App\Support\Dashboards\WidgetContext;
use App\Support\Dashboards\WidgetRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /dashboard/widgets/{key}?dashboard=owner&range=... : one widget's payload,
 * JSON, for the refresh a socket event or the fallback poll asks for.
 *
 * ⚠⚠ RE-AUTHORISED ON EVERY CALL (ruling 10). The dashboard must be one the
 * user may open, the widget must belong to it, and the widget's own policy must
 * pass, or the answer is 403; an unknown key is 404. A self widget reads the
 * signed-in user and nothing from the query string, so it cannot be asked for
 * another employee's data.
 */
class DashboardWidgetController extends Controller
{
    public function show(Request $request, string $widget, WidgetRegistry $registry, DashboardAccess $access, BusinessContext $context): JsonResponse
    {
        $found = $registry->find($widget);
        abort_if($found === null, 404);

        $key = DashboardKey::tryFrom((string) $request->query('dashboard', ''));
        abort_if($key === null, 422, 'Say which dashboard the widget is on.');

        $user = $request->user();

        abort_unless(
            $access->canOpen($user, $key)
                && in_array($key, $found->dashboards(), true)
                && $found->authorize($user),
            403,
        );

        $range = DateRange::fromQuery($request->query());
        $businessId = (int) $context->id();
        $widgetContext = new WidgetContext($user, $businessId, $range, $key, DashboardBrand::fromQuery($request->query('brand'), $businessId));

        return response()->json($registry->resolve($found, $widgetContext))
            ->header('Cache-Control', 'private, no-store');
    }
}
