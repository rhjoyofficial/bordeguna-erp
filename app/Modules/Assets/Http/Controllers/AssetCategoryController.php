<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Enums\AssetTracking;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The kinds of thing the business keeps (slice A1). Seeded with a default list
 * per business, editable here.
 *
 * ⚠ A category is switched off, never deleted: its items keep pointing at it.
 * ⚠ Its code is fixed once a tag carries it.
 */
class AssetCategoryController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('assets/categories/index', [
            'categories' => AssetCategory::query()->withCount('items')->orderBy('sort')->orderBy('name')->get()
                ->map(fn (AssetCategory $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'code' => $category->code,
                    'default_tracking' => $category->default_tracking->value,
                    'default_tracking_label' => $category->default_tracking->label(),
                    'default_useful_life_months' => $category->default_useful_life_months,
                    'is_active' => $category->is_active,
                    'items_count' => $category->items_count,
                ]),
            'trackings' => AssetTracking::options(),
            'can_manage' => $request->user()->can('assets.items.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        AssetCategory::create($data + [
            'sort' => (int) AssetCategory::query()->max('sort') + 10,
        ]);

        return back()->with('success', "Category {$data['name']} added.");
    }

    public function update(Request $request, AssetCategory $category): RedirectResponse
    {
        $data = $this->validated($request, $category);

        if ($data['code'] !== $category->code && $category->items()->exists()) {
            throw ValidationException::withMessages([
                'code' => "Items already carry tags with {$category->code}, and a printed tag never changes. Keep the code.",
            ]);
        }

        $category->update($data);

        return back()->with('success', "Category {$category->name} saved.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?AssetCategory $category = null): array
    {
        $request->merge(['code' => Str::upper(trim((string) $request->input('code')))]);
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('asset_categories', 'name')
                ->where('business_id', $businessId)->ignore($category?->id)],
            // ⚠ Letters only, short: it is printed on every sticker.
            'code' => ['required', 'string', 'regex:/^[A-Z]{2,6}$/', Rule::unique('asset_categories', 'code')
                ->where('business_id', $businessId)->ignore($category?->id)],
            'default_tracking' => ['required', Rule::enum(AssetTracking::class)],
            'default_useful_life_months' => ['nullable', 'integer', 'between:1,600'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'code.regex' => 'Use 2 to 6 letters, for example IT or FURN.',
        ]);

        return $data;
    }
}
