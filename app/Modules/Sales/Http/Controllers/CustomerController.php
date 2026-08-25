<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->toString(),
            'type' => $request->string('type')->toString(),
            'status' => $request->string('status')->toString(),
        ];

        $customers = Customer::query()
            ->when($filters['search'], fn ($query, $search) => $query->where(
                fn ($q) => $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
            ))
            ->when($filters['type'], fn ($query, $type) => $query->where('type', $type))
            ->when($filters['status'] === 'active', fn ($query) => $query->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('sales/customers/index', [
            'customers' => $customers,
            'filters' => $filters,
            'types' => collect(CustomerType::cases())
                ->map(fn (CustomerType $type) => ['value' => $type->value, 'label' => $type->label()])
                ->values(),
            // ⚠ Groups come from the table, never typed. RETAIL is deliberately
            // in the list even though "no group" already means RETAIL - picking
            // it records an explicit choice, leaving it blank records a default,
            // and both price identically by construction.
            'priceGroups' => PriceGroup::query()->active()
                ->orderBy('id')->get(['id', 'code', 'name']),
            // Reading the list is open to anyone who may see sales; changing it
            // in bulk is not, so the import panel is hidden rather than left to
            // 403 on press.
            'mayManage' => request()->user()?->can('sales.customers.manage') ?? false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Customer::create($this->validated($request));

        return back()->with('success', 'Customer created.');
    }

    /**
     * ⚠⚠ THE WALK-IN ROW'S CODE IS IMMUTABLE, and this guard exists because the
     * edit form was previously unreachable.
     *
     * `Customer::walkIn()` resolves the counter customer by `code = 'WALK-IN'`.
     * Making edit mode work would otherwise let somebody rename that code and
     * silently break EVERY counter sale - `walkIn()` would return null, and the
     * failure would appear at the till, not on the screen that caused it.
     *
     * ⚠ `type` is frozen for the same reason: a walk-in row reclassified as a
     * distributor is no longer what the counter picks up. Everything else about
     * the row - its name, phone, address - stays editable, because a business
     * may reasonably want to call it "Counter Sale".
     */
    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $data = $this->validated($request, $customer);

        if ($customer->is_system) {
            if (($data['code'] ?? null) !== $customer->code) {
                throw ValidationException::withMessages([
                    'code' => "The walk-in customer's code is part of the system and cannot be changed.",
                ]);
            }

            if (($data['type'] ?? null) !== $customer->type->value) {
                throw ValidationException::withMessages([
                    'type' => "The walk-in customer's type is part of the system and cannot be changed.",
                ]);
            }
        }

        $customer->update($data);

        return back()->with('success', 'Customer updated.');
    }

    /**
     * ⚠ Deactivated, never deleted. Every invoice and receipt in the business's
     * history points at the customer.
     *
     * ⚠⚠ The walk-in row is refused outright: every counter sale ever made
     * points at it, and a business that deactivates it cannot serve a customer.
     */
    public function destroy(Customer $customer): RedirectResponse
    {
        if ($customer->is_system) {
            throw ValidationException::withMessages([
                'customer' => 'The walk-in customer is part of the system and cannot be deactivated.',
            ]);
        }

        $customer->update(['is_active' => false]);

        return back()->with('success', 'Customer deactivated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Customer $customer = null): array
    {
        $businessId = $customer?->business_id ?? app(BusinessContext::class)->id();

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // ⚠ Scoped to the business. A distributor code is unique to us, not
            // globally, and two businesses may both have a DIST-001.
            'code' => [
                'nullable', 'string', 'max:50',
                Rule::unique('customers', 'code')
                    ->where('business_id', $businessId)
                    ->ignore($customer?->id),
            ],
            'type' => ['required', Rule::enum(CustomerType::class)],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            // ⚠ Optional for every customer type and NEVER required. A dealer,
            // distributor or corporate customer has a BIN/TIN; an individual
            // consumer does not and must never be asked. No VAT dependency:
            // storing a tax identifier is not tax treatment, and nothing in the
            // sales engine reads these columns.
            'bin' => ['nullable', 'string', 'max:50'],
            'tin' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            // ⚠ Tenant-scoped: a price group from another business must be
            // unreachable (C-PRC-6). Null means RETAIL, the default group.
            'price_group_id' => [
                'nullable', 'integer',
                Rule::exists('price_groups', 'id')->where('business_id', $businessId),
            ],
            'note' => ['nullable', 'string'],
        ]);
    }
}
