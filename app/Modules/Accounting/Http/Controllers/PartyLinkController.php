<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Actions\LinkSupplierCustomer;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ⭐ "Also our customer / Also our supplier" (decision D-F, 18 Oct 2026): the
 * buttons on the supplier page and the customer page.
 *
 * ⚠ Gated on BOTH manage permissions (route group): linking changes what the
 * supplier list and the customer list mean, so it needs a person allowed to
 * change both.
 */
class PartyLinkController extends Controller
{
    public function link(Request $request, LinkSupplierCustomer $action): RedirectResponse
    {
        $data = $request->validate(['supplier_id' => ['required', 'integer'], 'customer_id' => ['required', 'integer']]);

        // Tenant-scoped finds: another business's row is simply not found.
        $supplier = Supplier::query()->findOrFail($data['supplier_id']);
        $customer = Customer::query()->listable()->findOrFail($data['customer_id']);

        $action->link($supplier, $customer, $request->user());

        return back()->with('success', "{$supplier->name} and {$customer->name} are now one party. Their accounts stay separate.");
    }

    public function unlink(Request $request, Customer $customer, LinkSupplierCustomer $action): RedirectResponse
    {
        $action->unlink($customer, $request->user());

        return back()->with('success', "{$customer->name} is no longer linked to a supplier.");
    }

    public function customerFromSupplier(Request $request, Supplier $supplier, LinkSupplierCustomer $action): RedirectResponse
    {
        $customer = $action->customerFor($supplier, $request->user());

        return redirect()->route('sales.customers.show', $customer)
            ->with('success', "Customer {$customer->name} created from the supplier and linked. Check its type and price group.");
    }

    public function supplierFromCustomer(Request $request, Customer $customer, LinkSupplierCustomer $action): RedirectResponse
    {
        $supplier = $action->supplierFor($customer, $request->user());

        return redirect()->route('purchasing.suppliers.show', $supplier)
            ->with('success', "Supplier {$supplier->name} created from the customer and linked. Add its bank details and type.");
    }
}
