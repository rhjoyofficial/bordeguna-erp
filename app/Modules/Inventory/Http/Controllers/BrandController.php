<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Brand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Brands (DreamsPOS gap INV-4). The table has existed since 8 Aug with only a
 * seeder writing to it - Joy could not add a brand himself, which breaks the
 * standing "he enters his own data" rule. This closes that.
 *
 * The `code` is the import identity key and a SKU segment (LLD, BNC), frozen
 * once assigned - the same rule as supplier codes and leave-type codes: a
 * rename would silently detach whatever addresses the brand by code.
 *
 * Retire by deactivation, never delete: products point at brands with
 * RESTRICT, and a retired brand must stay attached to its history.
 */
class BrandController extends Controller
{
    public function index(): Response
    {
        // Viewing is open like categories; writes are gated by the route group.
        return Inertia::render('inventory/brands/index', [
            'brands' => Brand::withCount('products')->orderBy('name')->get()
                ->map(fn (Brand $brand) => [
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'code' => $brand->code,
                    'note' => $brand->note,
                    'is_active' => $brand->is_active,
                    'products_count' => $brand->products_count,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => $this->nameRules(),
            'code' => [
                'required', 'string', 'max:20', 'regex:/^[A-Za-z0-9]{2,8}$/',
                Rule::unique('brands')->where('business_id', $this->businessId()),
            ],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'code.regex' => 'The code becomes a SKU segment: 2 to 8 letters or digits, e.g. LLD.',
        ]);

        Brand::create([
            'name' => $data['name'],
            'code' => strtoupper($data['code']),
            'slug' => Str::slug($data['name']),
            'note' => $data['note'] ?? null,
        ]);

        return back()->with('success', 'Brand created.');
    }

    public function update(Request $request, Brand $brand): RedirectResponse
    {
        // No `code` here: SKUs already carry it and the import addresses the
        // brand by it. Frozen once assigned.
        $data = $request->validate([
            'name' => $this->nameRules($brand),
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $brand->update([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']),
            'note' => $data['note'] ?? null,
        ]);

        return back()->with('success', 'Brand updated.');
    }

    public function toggleActive(Brand $brand): RedirectResponse
    {
        $brand->update(['is_active' => ! $brand->is_active]);

        return back()->with('success', $brand->is_active ? 'Brand reactivated.' : 'Brand retired. Its products keep pointing at it.');
    }

    /**
     * The table's uniqueness lives on the SLUG, so the honest validation is on
     * what will actually be written: two names that collapse to one slug are
     * one brand as far as the database is concerned.
     *
     * @return array<int, mixed>
     */
    private function nameRules(?Brand $ignore = null): array
    {
        return [
            'required', 'string', 'max:255',
            function (string $attribute, mixed $value, \Closure $fail) use ($ignore) {
                $slug = Str::slug((string) $value);

                if ($slug === '') {
                    $fail('The name must contain at least one letter or digit.');

                    return;
                }

                $taken = Brand::where('slug', $slug)
                    ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
                    ->exists();

                if ($taken) {
                    $fail('A brand with this name already exists.');
                }
            },
        ];
    }

    private function businessId(): int
    {
        return app(BusinessContext::class)->id();
    }
}
