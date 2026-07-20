<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->toString(),
            'type' => $request->string('type')->toString(),
            'category_id' => $request->string('category_id')->toString(),
        ];

        $products = Product::query()
            ->with(['category', 'unitOfMeasure'])
            ->when($filters['search'], fn ($query, $search) => $query->where(
                fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")
            ))
            ->when($filters['type'], fn ($query, $type) => $query->where('type', $type))
            ->when($filters['category_id'], fn ($query, $categoryId) => $query->where('category_id', $categoryId))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Product $product) => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'type' => $product->type->value,
                'category' => $product->category?->name,
                'unit' => $product->unitOfMeasure->abbreviation,
                'low_stock_threshold' => $product->low_stock_threshold,
                'is_active' => $product->is_active,
                'current_quantity' => $product->type->isStocked() ? $product->currentStockQuantity() : null,
                'average_unit_cost' => $product->type->isStocked() ? $product->averageUnitCost() : null,
                'is_below_threshold' => $product->isBelowLowStockThreshold(),
            ]);

        return Inertia::render('inventory/products/index', [
            'products' => $products,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'units' => UnitOfMeasure::orderBy('name')->get(['id', 'name', 'abbreviation']),
            'filters' => $filters,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        Product::create($data + ['is_active' => true]);

        return back()->with('success', 'Product created.');
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $this->validated($request, $product);

        $product->update($data);

        return back()->with('success', 'Product updated.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->update(['is_active' => false]);

        return back()->with('success', 'Product deactivated.');
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        return $request->validate([
            'sku' => [
                'required', 'string', 'max:100',
                Rule::unique('products', 'sku')->where('business_id', app(BusinessContext::class)->id())->ignore($product),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', new Enum(ProductType::class)],
            'category_id' => ['nullable', 'exists:categories,id'],
            'unit_of_measure_id' => ['required', 'exists:units_of_measure,id'],
            'description' => ['nullable', 'string'],
            'low_stock_threshold' => ['nullable', 'numeric', 'min:0'],
        ]);
    }
}
