<?php

namespace App\Http\Middleware;

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant context for the HQ API, derived from the TOKEN, never from the caller.
 *
 * The route carries {business}; the token must hold `hq:business:{id}` for that exact business,
 * and the business must be active. Then TenantScope is entered so BusinessScope filters every
 * query, exactly as the web session does for a logged-in user. A token for business A asking
 * about business B gets 403 and no data. Correlation ids are echoed for cross-system tracing.
 */
final class HqApiContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $businessId = (int) $request->route('business');
        if (! $user || ! $user->currentAccessToken()) {
            return response()->json(['error' => ['code' => 'unauthenticated', 'message' => 'Token required.']], 401);
        }
        if ($businessId <= 0 || ! $user->tokenCan("hq:business:{$businessId}")) {
            return response()->json(['error' => ['code' => 'forbidden', 'message' => 'This token is not issued for that business.']], 403);
        }
        $business = Business::withoutGlobalScopes()->whereKey($businessId)->where('is_active', true)->first();
        if (! $business) {
            return response()->json(['error' => ['code' => 'not_found', 'message' => 'Unknown or inactive business.']], 404);
        }

        TenantScope::enter($business->id);

        $correlation = (string) $request->header('X-Correlation-Id', '');
        $correlation = preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $correlation) ? $correlation : (string) Str::uuid();
        Context::add(['correlation_id' => $correlation, 'hq_business_id' => $business->id, 'hq_token_id' => $user->currentAccessToken()->id]);

        try {
            $response = $next($request);
        } finally {
            TenantScope::leave();
        }
        $response->headers->set('X-Correlation-Id', $correlation);
        $response->headers->set('X-Business-Id', (string) $business->id);

        return $response;
    }
}
