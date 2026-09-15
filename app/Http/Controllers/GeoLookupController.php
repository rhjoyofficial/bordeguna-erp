<?php

namespace App\Http\Controllers;

use App\Support\BdGeo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * District, thana and area pickers for the ERP's own screens.
 *
 * ⚠ The storefront has its own copy of these three answers (Commerce
 * GeoController), which deliberately refuses on anything but a shop hostname.
 * The ERP needed the same lists for a field officer placing a shop on the map
 * (CRM lead slice 2b), and a storefront endpoint answering on the ERP's domain
 * is exactly what that controller exists to prevent - so this is a second,
 * signed-in door onto the same dataset, not a loosening of the first.
 *
 * ⚠ An unrecognised district or thana answers an EMPTY list with 200, the same
 * contract as the storefront: the form then asks rather than guesses.
 *
 * ⚠ No postcode goes to the browser here either. Nothing on an ERP screen asks
 * for one, and a value a page never receives is one it cannot post back.
 */
class GeoLookupController extends Controller
{
    public function thanas(Request $request): JsonResponse
    {
        return $this->answer(array_column(BdGeo::thanas($request->string('district')->toString()), 'name'));
    }

    public function areas(Request $request): JsonResponse
    {
        return $this->answer(array_column(
            BdGeo::areas($request->string('district')->toString(), $request->string('thana')->toString()),
            'name',
        ));
    }

    /**
     * @param  list<string>  $names
     */
    private function answer(array $names): JsonResponse
    {
        return response()
            ->json(['data' => array_values($names)])
            // ⚠ `private`: a public fact, but served behind a sign-in, so no
            // shared cache should hold a copy of an authenticated response.
            ->header('Cache-Control', 'private, max-age=86400');
    }
}
