<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    /**
     * ⚠ Bank details, BIN and TIN are only sent to users who may manage
     * suppliers. Viewing the list is open to the whole business, but an
     * account number is not something everyone needs in their page source.
     */
    private const SENSITIVE = [
        'bin', 'tin', 'bank_name', 'bank_branch',
        'bank_account_name', 'bank_account_number', 'mfs_number', 'notes',
    ];

    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->toString(),
            'status' => $request->string('status')->toString(),
        ];

        $suppliers = Supplier::query()
            ->when($filters['search'], fn ($query, $search) => $query->where(
                fn ($q) => $q->where('name', 'like', "%{$search}%")
                    // ⚠ The code is how purchasing refers to a supplier out loud,
                    // so it has to be searchable or it is not really an identifier.
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('contact_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
            ))
            ->when($filters['status'] === 'active', fn ($query) => $query->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $mayManage = $request->user()?->can('purchasing.suppliers.manage') ?? false;

        if (! $mayManage) {
            $suppliers->getCollection()->transform(function (Supplier $supplier) {
                foreach (self::SENSITIVE as $field) {
                    $supplier->offsetUnset($field);
                }

                return $supplier;
            });
        }

        return Inertia::render('purchasing/suppliers/index', [
            'suppliers' => $suppliers,
            'filters' => $filters,
            'mayManage' => $mayManage,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Supplier::create($this->validated($request) + ['is_active' => true]);

        return back()->with('success', 'Supplier created.');
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($this->validated($request, $supplier));

        return back()->with('success', 'Supplier updated.');
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        $supplier->update(['is_active' => false]);

        return back()->with('success', 'Supplier deactivated.');
    }

    /**
     * ⚠⚠ The screen and the import must produce the SAME supplier. A supplier
     * typed in by hand must not be structurally different from an imported one -
     * otherwise the import's idempotency key is missing on exactly the records
     * a person created in a hurry, and a re-run duplicates them.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Supplier $supplier = null): array
    {
        $businessId = $supplier?->business_id ?? app(BusinessContext::class)->id();

        return $request->validate([
            // ⚠⚠ REQUIRED here, though the column stays nullable: the database
            // tolerates the historical rows, the screen refuses to create new
            // ones. Scoped to the business, because two businesses may both
            // have an ACME.
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('suppliers', 'code')
                    ->where('business_id', $businessId)
                    ->ignore($supplier?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'payment_terms' => ['nullable', 'string', 'max:255'],

            // Tax identity. Optional: a supplier is real before anybody knows
            // their BIN.
            'bin' => ['nullable', 'string', 'max:255'],
            'tin' => ['nullable', 'string', 'max:255'],

            // How we actually pay them. Operational facts, not accounting.
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_branch' => ['nullable', 'string', 'max:255'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:255'],
            'mfs_number' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ]);
    }
}
