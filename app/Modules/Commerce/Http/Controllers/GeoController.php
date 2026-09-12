<?php

namespace App\Modules\Commerce\Http\Controllers;

use App\Modules\Commerce\Http\Middleware\ResolveStorefront;
use App\Support\BdGeo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where a customer lives, one question at a time. Website Phase 2, slice 2.2.
 *
 * District, then thana, then area — each list built from the one chosen above
 * it, so the three answers are guaranteed to describe one real place. ⚠ The
 * CONTAINMENT is the point, and it is enforced in BdGeo rather than here: ask
 * for the thanas of a district nobody recognises and the answer is an empty
 * list, never every thana in the country.
 *
 * ⛔ THE AREA LIST DELIBERATELY DOES NOT CARRY THE POSTCODE, although the
 * dataset holds it. The postcode is DERIVED on the server from the place the
 * customer picked, and sending it to the browser would invite a form to post one
 * back — at which point the shop is trusting a number a client supplied for an
 * address it already knows better than the client does. The customer is never
 * asked for a postcode and the browser is never told one.
 *
 * ⚠ This answers on a shop hostname only. On the ERP's own hostname there is no
 * such route, exactly like the basket.
 */
class GeoController
{
    public function districts(Request $request): JsonResponse
    {
        $this->requireStore($request);

        return $this->answer(BdGeo::districts());
    }

    public function thanas(Request $request): JsonResponse
    {
        $this->requireStore($request);

        return $this->answer(BdGeo::thanas($request->query('district')));
    }

    public function areas(Request $request): JsonResponse
    {
        $this->requireStore($request);

        $areas = array_map(
            static fn (array $a) => ['name' => $a['name'], 'bn_name' => $a['bn_name']],
            BdGeo::areas($request->query('district'), $request->query('thana')),
        );

        return $this->answer($areas);
    }

    /**
     * ⚠ An unrecognised place answers an EMPTY list and HTTP 200, never 404.
     * Nothing is wrong: the shop simply has no opinion about that name, and the
     * form's own job is then to ask rather than to guess. A 404 here would make
     * a typed district look like a broken endpoint.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function answer(array $rows): JsonResponse
    {
        return response()
            ->json(['data' => array_values($rows)])
            // Public administrative fact that changes about once a decade, so it
            // is worth caching hard at the edge and in the browser. ⚠ It carries
            // nothing about the customer, the basket or the shop, which is what
            // makes `public` safe here.
            ->header('Cache-Control', 'public, max-age=86400');
    }

    /**
     * ⚠ Geography is not business-scoped and the answer would be identical for
     * every tenant — but the route still refuses off a shop hostname, because a
     * storefront endpoint answering on the ERP's own domain is the beginning of
     * the shop existing in two places.
     */
    private function requireStore(Request $request): void
    {
        abort_if(ResolveStorefront::current($request) === null, 404);
    }
}
