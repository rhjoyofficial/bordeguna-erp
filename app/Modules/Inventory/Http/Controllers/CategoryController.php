<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('inventory/categories/index', [
            'categories' => Category::orderBy('name')->get(['id', 'name', 'slug', 'code']),
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
                $query = Category::where('slug', Str::slug((string) $value));

                if ($ignore !== null) {
                    $query->whereKeyNot($ignore->getKey());
                }

                if ($query->exists()) {
                    $fail('A category with this name already exists.');
                }
            }],
            'code' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9]{2,8}(-[A-Za-z0-9]{2,8})*$/'],
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules());

        Category::create([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']),
            'code' => self::code($data['code'] ?? null),
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

    public function destroy(Category $category): RedirectResponse
    {
        $category->delete();

        return back()->with('success', 'Category deleted.');
    }
}
