<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\UnitOfMeasure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * ⭐ 27 Sep 2026 (Joy): "anything needs to select from a drop-down that is
 * missing can be created instant, no need to go to other pages" — units,
 * categories, brands (and suppliers, in the purchasing twin of this).
 *
 * A unit / category / brand created from INSIDE the product form, so a missing
 * option no longer means: close the form, lose what was typed, open Inventory
 * settings, add it, come back, start again. Modelled on QuickAccountController:
 * JSON in and out, never an Inertia redirect (which would re-render the page
 * under the open dialog and throw away the half-filled product). The picker
 * gets the new row's id and label and selects it straight away.
 *
 * ⚠⚠ Each method carries the SAME permission as the full settings screen
 * (`inventory.catalog.manage`). A smaller door into the catalogue is still a
 * door into the catalogue, and these rows shape SKUs and reporting for every
 * business exactly as the settings screen does. The permission is enforced on
 * the route and re-checked here.
 *
 * ⚠ Validation mirrors the settings controllers exactly, so the quick path can
 * never write a row the full screen would reject.
 */
class InventoryQuickCreateController extends Controller
{
    public function unit(Request $request): JsonResponse
    {
        $this->authorize('inventory.catalog.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abbreviation' => [
                'required', 'string', 'max:20',
                Rule::unique('units_of_measure', 'abbreviation')->where('business_id', app(BusinessContext::class)->id()),
            ],
        ]);

        $unit = UnitOfMeasure::create($data);

        return response()->json([
            'id' => $unit->id,
            'label' => "{$unit->name} ({$unit->abbreviation})",
        ], 201);
    }

    public function category(Request $request): JsonResponse
    {
        $this->authorize('inventory.catalog.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Optional SKU segment; the same shape the settings screen accepts.
            'code' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9]{2,8}(-[A-Za-z0-9]{2,8})*$/'],
        ]);

        $code = strtoupper(trim((string) ($data['code'] ?? '')));

        $category = Category::create([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']),
            'code' => $code === '' ? null : $code,
        ]);

        return response()->json([
            'id' => $category->id,
            'label' => $category->name,
        ], 201);
    }

    public function brand(Request $request): JsonResponse
    {
        $this->authorize('inventory.catalog.manage');

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $slug = Str::slug((string) $value);

                    if ($slug === '') {
                        $fail('The name must contain at least one letter or digit.');

                        return;
                    }

                    if (Brand::where('slug', $slug)->exists()) {
                        $fail('A brand with this name already exists.');
                    }
                },
            ],
            'code' => [
                'required', 'string', 'max:20', 'regex:/^[A-Za-z0-9]{2,8}$/',
                Rule::unique('brands')->where('business_id', app(BusinessContext::class)->id()),
            ],
        ], [
            'code.regex' => 'The code becomes a SKU segment: 2 to 8 letters or digits, e.g. LLD.',
        ]);

        $brand = Brand::create([
            'name' => $data['name'],
            'code' => strtoupper($data['code']),
            'slug' => Str::slug($data['name']),
        ]);

        return response()->json([
            'id' => $brand->id,
            'label' => $brand->name,
        ], 201);
    }
}
