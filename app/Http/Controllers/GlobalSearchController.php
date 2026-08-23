<?php

namespace App\Http\Controllers;

use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The header search (Joy's DreamsPOS ask, 23 Aug 2026): type a thing, land on
 * its page. Products, customers, suppliers, employees, invoices.
 *
 * ⚠⚠ PERMISSION-FILTERED SERVER-SIDE, group by group, with the SAME gates the
 * screens themselves use. A search box that lists an employee to somebody who
 * cannot open the employee page is a directory leak with autocomplete.
 *
 * ⚠ Tenancy costs nothing here: every model queried carries the business
 * global scope (employees confine through assignments, same as their pages).
 *
 * ⚠ LIKE with a leading wildcard cannot use an index, and does not need to at
 * this scale (153 products, 9 employees). If the catalogue ever grows to where
 * it hurts, the fix is a search index, not a cleverer LIKE.
 */
class GlobalSearchController extends Controller
{
    private const PER_GROUP = 5;

    public function __invoke(Request $request): JsonResponse
    {
        $q = trim($request->string('q')->toString());

        if (mb_strlen($q) < 2) {
            return response()->json(['groups' => []]);
        }

        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
        $user = $request->user();
        $groups = [];

        $products = Product::query()
            ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('sku', 'like', $like))
            ->orderBy('name')
            ->limit(self::PER_GROUP)
            ->get(['id', 'name', 'sku'])
            ->map(fn (Product $p) => [
                'label' => $p->name,
                'sub' => $p->sku,
                'url' => '/inventory/products?search='.urlencode($q),
            ]);

        if ($products->isNotEmpty()) {
            $groups[] = ['title' => 'Products', 'items' => $products->values()];
        }

        if ($user->can('sales.reports.view')) {
            $customers = Customer::query()
                ->where('name', 'like', $like)
                ->orderBy('name')
                ->limit(self::PER_GROUP)
                ->get(['id', 'name'])
                ->map(fn (Customer $c) => [
                    'label' => $c->name,
                    'sub' => 'Customer',
                    'url' => '/sales/customers?search='.urlencode($q),
                ]);

            if ($customers->isNotEmpty()) {
                $groups[] = ['title' => 'Customers', 'items' => $customers->values()];
            }

            $invoices = SalesInvoice::query()
                ->where('invoice_number', 'like', $like)
                ->orderByDesc('id')
                ->limit(self::PER_GROUP)
                ->get(['id', 'invoice_number'])
                ->map(fn (SalesInvoice $i) => [
                    'label' => $i->invoice_number,
                    'sub' => 'Invoice',
                    'url' => '/sales/invoices/'.$i->id,
                ]);

            if ($invoices->isNotEmpty()) {
                $groups[] = ['title' => 'Invoices', 'items' => $invoices->values()];
            }
        }

        $suppliers = Supplier::query()
            ->where('name', 'like', $like)
            ->orderBy('name')
            ->limit(self::PER_GROUP)
            ->get(['id', 'name'])
            ->map(fn (Supplier $s) => [
                'label' => $s->name,
                'sub' => 'Supplier',
                'url' => '/purchasing/suppliers?search='.urlencode($q),
            ]);

        if ($suppliers->isNotEmpty()) {
            $groups[] = ['title' => 'Suppliers', 'items' => $suppliers->values()];
        }

        if ($user->can('hrm.employees.manage')) {
            $employees = Employee::query()
                ->whereHas('assignments')
                ->where(fn ($w) => $w
                    ->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('employee_code', 'like', $like))
                ->orderBy('first_name')
                ->limit(self::PER_GROUP)
                ->get(['id', 'first_name', 'last_name', 'employee_code'])
                ->map(fn (Employee $e) => [
                    'label' => $e->fullName(),
                    'sub' => $e->employee_code,
                    'url' => '/hrm/employees/'.$e->id,
                ]);

            if ($employees->isNotEmpty()) {
                $groups[] = ['title' => 'Employees', 'items' => $employees->values()];
            }
        }

        return response()->json(['groups' => $groups]);
    }
}
