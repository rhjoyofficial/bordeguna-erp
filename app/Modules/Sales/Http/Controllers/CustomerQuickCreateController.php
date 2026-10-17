<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ⭐ 27 Sep 2026 (Joy): a customer created from INSIDE a sale — at the counter,
 * on an order, an invoice, a receipt — so a new buyer no longer sends the
 * cashier off to the Customers screen and back mid-sale. The twin of the
 * inventory quick-create: JSON in and out, never an Inertia redirect (which
 * would re-render the page under the open dialog and lose the sale in progress).
 * The picker gets the new customer's id and label and selects it straight away.
 *
 * Only what a sale needs is asked here: name (required), phone, type and price
 * group. Everything else (BIN/TIN, address, credit terms) is filled later on
 * the customer's own page — and credit terms are GRANTED, never typed, so they
 * are deliberately absent here as they are stripped from the full form without
 * `sales.credit.approve`.
 *
 * ⚠⚠ Same permission as managing customers (`sales.customers.manage`), enforced
 * on the route and here. The counter deliberately works WITHOUT it for a Staff
 * cashier (the walk-in row is the default), so the UI hides the "+ New customer"
 * row for anyone who lacks it; a 403 here is the backstop.
 */
class CustomerQuickCreateController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $this->authorize('sales.customers.manage');

        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            // Optional; a plain retail consumer is the sensible default.
            'type' => ['nullable', Rule::enum(CustomerType::class)->only(CustomerType::pickable())],
            'price_group_id' => [
                'nullable', 'integer',
                Rule::exists('price_groups', 'id')->where('business_id', $businessId),
                // ⭐ 26 Sep 2026: a retail SHOP never pays MRP (the RETAIL group).
                // Blank is fine (it defaults to SHOP); RETAIL by hand is refused.
                function (string $attribute, mixed $value, \Closure $fail) use ($request, $businessId) {
                    if ($request->input('type') !== CustomerType::Shop->value || $value === null || $value === '') {
                        return;
                    }

                    $code = PriceGroup::query()->withoutGlobalScopes()
                        ->where('business_id', $businessId)->whereKey((int) $value)->value('code');

                    if ($code === PriceGroup::DEFAULT_CODE) {
                        $fail('A retail shop never pays MRP. Leave the price group blank for Retail shop, or choose another group; RETAIL is for consumers.');
                    }
                },
            ],
        ]);

        $customer = Customer::create([
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'type' => $data['type'] ?? CustomerType::Retail->value,
            'price_group_id' => ($data['price_group_id'] ?? '') === '' ? null : (int) $data['price_group_id'],
            'is_active' => true,
        ]);

        return response()->json([
            'id' => $customer->id,
            'label' => $customer->phone ? "{$customer->name} ({$customer->phone})" : $customer->name,
        ], 201);
    }
}
