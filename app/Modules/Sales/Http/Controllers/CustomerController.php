<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
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
            'address' => ['nullable', 'string'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'price_group' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string'],
        ]);
    }
}
