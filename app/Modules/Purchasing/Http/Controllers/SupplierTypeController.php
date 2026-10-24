<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Corrections\Recycle\BinsFromList;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\MasterRecords;
use App\Modules\Purchasing\Models\SupplierType;
use App\Modules\Purchasing\Support\SupplierTypes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Purchases > Suppliers > Supplier types (Joy, 17 Oct 2026: more types than
 * the seven fixed ones). Same gate as the Tax rates tab beside it:
 * purchasing.suppliers.manage, on the route.
 *
 * ⚠⚠ Suppliers and TDS/VDS rates store a type's KEY. So the key is frozen once
 * anything uses it, and a used type is switched off, never deleted. The name is
 * free to change at any time.
 */
class SupplierTypeController extends Controller
{
    use BinsFromList;

    public function index(Request $request): Response
    {
        // A business that predates the list gets its seven types written in
        // now, so every row on this screen is a real one that can be edited.
        if (! SupplierType::withTrashed()->exists()) {
            SupplierTypes::seedFor((int) app(BusinessContext::class)->id());
        }

        $types = SupplierType::query()->ordered()->get();

        return Inertia::render('purchasing/supplier-types/index', [
            'canManageTypes' => (bool) $request->user()?->can('purchasing.suppliers.manage'),
            // ⭐ B24 (24 Oct 2026, Q5 = A): each row's Delete / Switch off / Reactivate,
            // greyed with what uses it.
            'recordActions' => MasterRecords::rows('supplier_type', $types, $request->user()),
            'deleteReasons' => MasterRecords::reasons(),
            'types' => $types->map(fn (SupplierType $t) => [
                'id' => $t->id,
                'key' => $t->key,
                'name' => $t->name,
                'is_active' => $t->is_active,
                'suppliers' => $t->suppliersUsing(),
                'rates' => $t->ratesUsing(),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);

        SupplierType::create([
            'key' => $data['key'] ?: SupplierTypes::keyFromName($data['name']),
            'name' => $data['name'],
            'is_active' => true,
            'sort' => (int) SupplierType::query()->max('sort') + 1,
        ]);

        return back()->with('success', "Supplier type {$data['name']} added.");
    }

    public function update(Request $request, SupplierType $supplierType): RedirectResponse
    {
        $data = $this->validated($request, $supplierType);
        $key = $data['key'] ?: $supplierType->key;

        // ⚠ A supplier or a rate stores the key: changing it would leave them
        // pointing at nothing. The name changes freely.
        if ($key !== $supplierType->key && $supplierType->isUsed()) {
            throw ValidationException::withMessages(['key' => "Suppliers or tax rates use this type, so its key stays {$supplierType->key}. You can still change the name."]);
        }

        $supplierType->update(['key' => $key, 'name' => $data['name']]);

        return back()->with('success', "Supplier type {$data['name']} saved.");
    }

    public function deactivate(SupplierType $supplierType): RedirectResponse
    {
        $supplierType->update(['is_active' => false]);

        return back()->with('success', "{$supplierType->name} switched off. Suppliers that have it keep it.");
    }

    public function reactivate(SupplierType $supplierType): RedirectResponse
    {
        $supplierType->update(['is_active' => true]);

        return back()->with('success', "{$supplierType->name} is back on.");
    }

    public function destroy(Request $request, SupplierType $supplierType): RedirectResponse
    {
        if ($supplierType->isUsed()) {
            throw ValidationException::withMessages(['type' => $this->inUseReason($supplierType)]);
        }

        // ⭐ B24 (24 Oct 2026, Q5 = A): to the Recycle bin with a reason.
        return $this->binFromList($request, $supplierType);
    }

    private function inUseReason(SupplierType $type): string
    {
        $parts = array_filter([
            $type->suppliersUsing() > 0 ? Str::plural('supplier', $type->suppliersUsing(), true) : null,
            $type->ratesUsing() > 0 ? Str::plural('tax rate', $type->ratesUsing(), true) : null,
        ]);

        return $type->name.' is used by '.implode(' and ', $parts).', so it cannot be deleted. Switch it off instead.';
    }

    /** @return array{key: string, name: string} */
    private function validated(Request $request, ?SupplierType $type): array
    {
        $businessId = $type?->business_id ?? app(BusinessContext::class)->id();

        $request->merge(['key' => Str::lower(trim((string) $request->input('key', '')))]);

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('supplier_types', 'name')->where('business_id', $businessId)->ignore($type?->id),
            ],
            // Optional: left blank, it is made from the name.
            'key' => [
                'nullable', 'string', 'max:32', 'regex:/^[a-z0-9][a-z0-9_]*$/',
                Rule::unique('supplier_types', 'key')->where('business_id', $businessId)->ignore($type?->id),
            ],
        ], [
            'name.unique' => 'There is already a supplier type with this name.',
            'key.unique' => 'There is already a supplier type with this key.',
            'key.regex' => 'A key may hold only small letters, digits and underscores, e.g. raw_material.',
        ]);

        return ['key' => (string) ($data['key'] ?? ''), 'name' => trim($data['name'])];
    }
}
