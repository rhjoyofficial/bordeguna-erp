<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Corrections\Recycle\BinsFromList;
use App\Modules\Foundation\Support\MasterRecords;
use App\Modules\Inventory\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    use BinsFromList;

    public function index(): Response
    {
        $categories = Category::orderBy('name')->get(['id', 'name', 'slug', 'code', 'market_markup_percent', 'market_min_margin_percent', 'market_rounding_step']);

        return Inertia::render('inventory/categories/index', [
            'categories' => $categories,
            // ⭐ B24 (24 Oct 2026, Q5 = A): each row's Delete / Switch off / Reactivate,
            // greyed with what uses it.
            'recordActions' => MasterRecords::rows('category', $categories, request()->user()),
            'deleteReasons' => MasterRecords::reasons(),
        ]);
    }

    /**
     * C-SKU-1. The code is the SKU segment this category contributes, and it is
     * typed rather than derived: the live catalogue uses MIX for Nuts, SLT for
     * Salts and SED for Seeds, none of which follow from the name.
     *
     * ⚠ A hyphen is allowed so a category can carry its whole family path, as
     * `PK-JAR` does. Each segment is checked on its own, so a stray hyphen
     * cannot produce an empty segment in the middle of a SKU.
     */
    private function rules(?Category $ignore = null): array
    {
        return [
            // ⚠ The slug is derived from the name and is unique per business in
            // the database. Nothing said so, so a second "Nuts" (or two names that
            // slug the same) reached the unique index and came back as a blank 500
            // instead of a sentence under the box - the same defect already fixed
            // for units and brands. Validated on the DERIVED slug, business-scoped
            // through the model's global scope, ignoring the row being edited.
            'name' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, callable $fail) use ($ignore) {
                $query = Category::withTrashed()->where('slug', Str::slug((string) $value));

                if ($ignore !== null) {
                    $query->whereKeyNot($ignore->getKey());
                }

                if ($query->exists()) {
                    $fail('A category with this name already exists.');
                }
            }],
            'code' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9]{2,8}(-[A-Za-z0-9]{2,8})*$/'],
            // ⭐ Phase 2 / 1B (17 Oct 2026): the market pricing defaults every
            // product in the category inherits. Markup ON COST; minimum gross
            // margin ON SELLING price (under 100, or the formula divides by
            // zero); rounding step in taka. Definitions: ProductPricing.
            'market_markup_percent' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'market_min_margin_percent' => ['nullable', 'numeric', 'min:0', 'max:99.99'],
            'market_rounding_step' => ['nullable', 'numeric', 'gt:0', 'max:10000'],
        ];
    }

    /**
     * The three market rule columns from the form, blank as NULL (inherit
     * nothing, so a product under this category has no rule until one is set).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, ?string>
     */
    private static function marketRules(array $data): array
    {
        $rules = [];

        foreach (['market_markup_percent', 'market_min_margin_percent', 'market_rounding_step'] as $column) {
            $value = $data[$column] ?? null;
            $rules[$column] = $value === null || $value === '' ? null : (string) $value;
        }

        return $rules;
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules());

        Category::create([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']),
            'code' => self::code($data['code'] ?? null),
            ...self::marketRules($data),
        ]);

        return back()->with('success', 'Category created.');
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $data = $request->validate($this->rules($category));

        $category->update([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']),
            'code' => self::code($data['code'] ?? null),
            ...self::marketRules($data),
        ]);

        return back()->with('success', 'Category updated.');
    }

    /**
     * Upper-cased, or null when blank. ⚠ Blank must be NULL rather than an
     * empty string: the SKU generator treats null as "this category has not
     * said yet" and declines to propose, which is the honest answer.
     */
    private static function code(?string $value): ?string
    {
        $value = strtoupper(trim((string) $value));

        return $value === '' ? null : $value;
    }

    public function destroy(Request $request, Category $category): RedirectResponse
    {
        // ⭐ B24 (24 Oct 2026, Q5 = A): never used goes to the Recycle bin with a
        // reason (the Owner restores it); used is refused with WHAT uses it,
        // read from the schema, and the row is switched off instead. ⚠ The
        // soft delete no longer meets the RESTRICT keys that used to refuse.
        return $this->binFromList($request, $category);
    }
}
