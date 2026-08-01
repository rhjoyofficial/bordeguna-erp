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
            $business = $user->is_super_admin
                ? Business::where('is_active', true)->orderBy('name')->first()
                : $user->businesses()->where('is_active', true)->orderBy('name')->first();

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
        }

        return $next($request);
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
