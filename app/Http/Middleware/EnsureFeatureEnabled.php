<?php

namespace App\Http\Middleware;

use App\Support\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `feature:<key>`: a hidden feature's routes answer 404 (Joy, 24 Oct 2026).
 *
 * 404 and not 403 on purpose: a switched-off feature does not exist as far as
 * anybody signed in is concerned, the same answer an unknown address gets.
 */
class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless(Features::on($feature), 404);

        return $next($request);
    }
}
