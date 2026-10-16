<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\ActivityLogEntry;
use App\Modules\Foundation\Models\Business;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Inertia\Inertia;
use Inertia\Response;

class BusinessSwitcherController extends Controller
{
    private const LOG = 'foundation.business_switch';

    /**
     * Routes that are never a sensible place to land after switching, even
     * though they take no parameters.
     *
     * @var array<int, string>
     */
    private const EXEMPT_RETURN = ['login', 'logout', 'register'];

    public function index(Request $request): Response
    {
        $user = $request->user();

        $businesses = $user->is_super_admin
            ? Business::where('is_active', true)->orderBy('name')->get(['id', 'name', 'slug'])
            : $user->businesses()->where('is_active', true)->orderBy('name')->get(['businesses.id', 'businesses.name', 'businesses.slug']);

        return Inertia::render('business-switcher', [
            'businesses' => $businesses,
            'current_business_id' => $request->session()->get('current_business_id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'business_id' => ['required', 'integer', 'exists:businesses,id'],
        ]);

        $user = $request->user();
        $business = Business::where('is_active', true)->findOrFail($data['business_id']);

        $from = $request->session()->get('current_business_id');
        $fromBusiness = $from ? Business::find($from) : null;

        $allowed = $user->is_super_admin
            || $user->businesses()->where('businesses.id', $business->id)->exists();

        if (! $allowed) {
            // W4: a refused attempt on the one privileged cross-tenant action is
            // worth more than a successful one, so it is recorded rather than
            // failing silently.
            //
            // ⚠ Owned by where the actor ALREADY IS, never by the target. The
            // obvious reading is "log it in the tenant they tried to enter", but
            // that lets a denied action write into the very thing that denied
            // it, and the dashboard feed shows only the latest few entries - so
            // a rejected user could push a tenant's visible history off screen
            // without ever being let in. The target is named in the properties.
            $this->record($user, $fromBusiness?->id, [
                'result' => 'denied',
                'from_business_id' => $fromBusiness?->id,
                'from_business_name' => $fromBusiness?->name,
                'target_business_id' => $business->id,
                'target_business_name' => $business->name,
            ], "Denied switch into {$business->name}");

            abort(403);
        }

        // A no-op changes nothing and says nothing. Logging it would put noise in
        // front of the events that matter.
        if ($from !== $business->id) {
            // ⚠⚠ Owned by the DESTINATION, and set EXPLICITLY. Left to inherit
            // from BusinessContext it would land in the business being LEFT,
            // because the session key only takes effect on the next request -
            // so the record of who entered a tenant would live only in the
            // tenant they came from. Worse, a first switch has no source at all,
            // and BusinessScope filters on `business_id = ?`, so a NULL there is
            // invisible from inside EVERY tenant rather than visible from all.
            $this->record($user, $business->id, [
                'result' => 'switched',
                'from_business_id' => $fromBusiness?->id,
                'from_business_name' => $fromBusiness?->name,
                'to_business_id' => $business->id,
                'to_business_name' => $business->name,
                'is_super_admin' => (bool) $user->is_super_admin,
            ], $fromBusiness
                ? "Switched into {$business->name} from {$fromBusiness->name}"
                : "Switched into {$business->name}");
        }

        $request->session()->put('current_business_id', $business->id);

        return redirect($this->returnTo($request))
            ->with('success', "Switched to {$business->name}.");
    }

    /**
     * W5a. Switching used to discard where you were, which is the whole
     * annoyance for anybody comparing the same screen across two tenants.
     *
     * ⚠ Only a route that takes NO parameters comes back. `/hrm/shifts` means
     * the same thing in any business; `/hrm/shifts/3` does not, because shift 3
     * belongs to one of them - carrying it across would 404 or, worse, resolve
     * to an unrelated record with the same id.
     *
     * ⚠ Known limitation, stated in §14.1 rather than hidden: roles are
     * team-scoped, so returning to a page the user cannot access in the NEW
     * business lands on a 403 where the dashboard would have worked. It cannot
     * be pre-checked, because authorisation happens inside the controllers here
     * rather than in route middleware. A 403 is visible and recoverable.
     */
    private function returnTo(Request $request): string
    {
        $referer = $request->headers->get('referer');

        if (! $referer || ! str_starts_with($referer, $request->getSchemeAndHttpHost())) {
            return route('dashboard');
        }

        $route = $this->routeFor($referer);

        if ($route === null
            || $route->parameterNames() !== []
            || in_array($route->getName(), ['businesses.switch', ...self::EXEMPT_RETURN], true)) {
            return route('dashboard');
        }

        // ⚠ Bug hunt 16 Oct 2026: without its query string. `?item=5` names a
        // record of the business being LEFT, so carried across it opened a 404
        // (/assets/maintenance/plans/create) or preselected another business's
        // record. The page itself is the place to return to; its filters and
        // picks belong to the old business.
        return strtok($referer, '?');
    }

    private function routeFor(string $url): ?Route
    {
        try {
            return app('router')->getRoutes()->match(
                Request::create($url, 'GET'),
            );
        } catch (\Throwable) {
            // Anything unmatched - a stale URL, a route since removed - is not
            // worth an exception on a successful switch.
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function record(mixed $causer, ?int $businessId, array $properties, string $description): void
    {
        activity(self::LOG)
            ->causedBy($causer)
            ->withProperties($properties)
            ->tap(fn (ActivityLogEntry $entry) => $entry->business_id = $businessId)
            ->log($description);
    }
}
