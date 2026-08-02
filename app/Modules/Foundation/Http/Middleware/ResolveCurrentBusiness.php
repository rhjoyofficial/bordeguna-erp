<?php

namespace App\Modules\Foundation\Http\Middleware;

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves "current business" for the authenticated user and sets it in two
 * places together, deliberately, per the plan's risk #3: the BusinessContext
 * (drives row-level scoping via BusinessScope) and spatie's permissions team id
 * (drives per-business roles). Keeping these in one place stops them drifting apart.
 */
class ResolveCurrentBusiness
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $businessId = $request->session()->get('current_business_id');
        $business = $businessId ? $this->findAccessible($user, $businessId) : null;

        if (! $business) {
            // W2: this used to be `orderBy('name')`, so the tenant somebody
            // landed in was decided by a display string - the super admin landed
            // in an empty Azmion, and a rename would have moved everyone.
            // `inDefaultOrder()` is the one rule, shared by both branches.
            $business = $user->is_super_admin
                ? Business::where('is_active', true)->inDefaultOrder()->first()
                : $user->businesses()->where('businesses.is_active', true)->inDefaultOrder()->first();

            if ($business) {
                $request->session()->put('current_business_id', $business->id);
            }
        }

        if ($business) {
            // Both halves, through the one entry point that cannot do half the
            // job. This middleware always did set both; queued jobs did not,
            // and that gap was a live ACL-02 bypass until 2026-08-01. Routing
            // every caller through `TenantScope` is what stops it recurring.
            TenantScope::enter($business->id);

            return $next($request);
        }

        // W5b. With no business, BusinessScope correctly fails closed on 1 = 0
        // and every screen renders EMPTY AND CORRECT with nothing to explain
        // why - the same misleading shape as W2's empty Azmion. Send them
        // somewhere that says so out loud. The switcher page already carries
        // the sentence; nothing ever sent anybody there, which was the gap.
        if ($this->shouldExplainMissingBusiness($request)) {
            return redirect()->route('businesses.switch')
                ->with('error', 'Your account is not attached to any business yet. Ask an administrator to add you to one.');
        }

        return $next($request);
    }

    /**
     * ⚠ The exemptions are what stop this looping forever: the page we send
     * them to is itself served with no business resolved, and a user who cannot
     * be helped must still be able to sign out.
     */
    private function shouldExplainMissingBusiness(Request $request): bool
    {
        return $request->isMethod('GET')
            && ! $request->expectsJson()
            && ! in_array($request->route()?->getName(), [
                'businesses.switch',
                'businesses.switch.store',
                'logout',
            ], true);
    }

    private function findAccessible($user, int $businessId): ?Business
    {
        $business = Business::where('is_active', true)->find($businessId);

        if (! $business) {
            return null;
        }

        if ($user->is_super_admin) {
            return $business;
        }

        return $user->businesses()->where('businesses.id', $businessId)->exists() ? $business : null;
    }
}
