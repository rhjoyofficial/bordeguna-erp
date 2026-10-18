<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\Proprietor;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Models\Customer;
use Illuminate\Http\RedirectResponse;

/**
 * ⭐ D-B (Joy, 18 Oct 2026, "Cost price"): "Create Moin's customer account"
 * under Settings > Company. One owner customer per proprietor, type `owner`,
 * the OWNER price group (basis COST). Made on demand and never typed, the
 * same way an employee's staff account is made by the system.
 *
 * Whoever may edit the company settings may press it (the proprietor is
 * named there too). Pressing it twice returns the same account.
 */
class OwnerCustomerController extends Controller
{
    public function store(): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');

        $businessId = app(BusinessContext::class)->id();
        $business = Business::withoutGlobalScopes()->findOrFail($businessId);
        $proprietor = Proprietor::userFor($business);

        if ($proprietor === null) {
            return back()->with('error', 'Name the proprietor first (Proprietor, above), then create his customer account.');
        }

        $existed = Customer::ofOwner($proprietor->id) !== null;
        $customer = Customer::forProprietor($proprietor, $businessId);

        return back()->with('success', $existed
            ? "{$customer->name} already has a customer account ({$customer->code})."
            : "Customer account {$customer->code} created for {$customer->name}. Sell to it at the owner price (batch cost) on the counter or on an order.");
    }
}
