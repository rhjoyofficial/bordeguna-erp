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

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $customer->update($this->validated($request, $customer));

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
