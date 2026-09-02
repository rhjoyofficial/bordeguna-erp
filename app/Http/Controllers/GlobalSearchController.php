<?php

namespace App\Http\Controllers;

use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Crm\Models\Lead;
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

        // ⚠ Products and Suppliers were the two ungated groups here - "open to
        // the business" predates the zero-permission Self-service role
        // (24 Aug 2026). Each group now wears the permission its destination
        // screen enforces, so search can never show what a click would refuse.
        if ($user->can('inventory.reports.view')) {
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
        }

        if ($user->can('sales.reports.view')) {
            $customers = Customer::query()
                // A phone number is how the counter remembers a person.
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like))
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

        if ($user->can('purchasing.reports.view')) {
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
        }

        if ($user->can('hrm.employees.manage')) {
            $employees = Employee::query()
                ->whereHas('assignments')
                ->where(fn ($w) => $w
                    ->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('employee_code', 'like', $like))
                ->orderBy('employee_code')
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

        // ── Document numbers (Module 1, Slice 2): "PO-2026-00042" lands on
        // the order itself, not on a list to hunt through. Same law as above:
        // each group wears its destination screen's permission.
        if ($user->can('sales.reports.view')) {
            $orders = SalesOrder::query()
                ->where('order_number', 'like', $like)
                ->orderByDesc('id')
                ->limit(self::PER_GROUP)
                ->get(['id', 'order_number'])
                ->map(fn (SalesOrder $o) => [
                    'label' => $o->order_number,
                    'sub' => 'Sales order',
                    'url' => '/sales/orders/'.$o->id,
                ]);

            if ($orders->isNotEmpty()) {
                $groups[] = ['title' => 'Sales orders', 'items' => $orders->values()];
            }
        }

        if ($user->can('purchasing.reports.view')) {
            $purchaseOrders = PurchaseOrder::query()
                ->where('po_number', 'like', $like)
                ->orderByDesc('id')
                ->limit(self::PER_GROUP)
                ->get(['id', 'po_number'])
                ->map(fn (PurchaseOrder $po) => [
                    'label' => $po->po_number,
                    'sub' => 'Purchase order',
                    'url' => '/purchasing/purchase-orders/'.$po->id,
                ]);

            if ($purchaseOrders->isNotEmpty()) {
                $groups[] = ['title' => 'Purchase orders', 'items' => $purchaseOrders->values()];
            }

            // The list is the expenses page; the reference is how a standing
            // cost is remembered (NIKOLA-2026-09), so it lands on the list
            // filtered to exactly that voucher.
            $vouchers = ExpenseVoucher::query()
                ->where(fn ($w) => $w->where('voucher_number', 'like', $like)->orWhere('reference', 'like', $like))
                ->orderByDesc('id')
                ->limit(self::PER_GROUP)
                ->get(['id', 'voucher_number', 'reference'])
                ->map(fn (ExpenseVoucher $v) => [
                    'label' => $v->voucher_number,
                    'sub' => $v->reference ?? 'Expense',
                    'url' => '/purchasing/expenses?search='.urlencode($q),
                ]);

            if ($vouchers->isNotEmpty()) {
                $groups[] = ['title' => 'Expenses', 'items' => $vouchers->values()];
            }
        }

        if ($user->can('accounting.view')) {
            $journals = Journal::query()
                ->where('journal_number', 'like', $like)
                ->orderByDesc('id')
                ->limit(self::PER_GROUP)
                ->get(['id', 'journal_number', 'memo'])
                ->map(fn (Journal $j) => [
                    'label' => $j->journal_number,
                    'sub' => mb_strimwidth((string) $j->memo, 0, 40, '...'),
                    'url' => '/accounting/journals/'.$j->id,
                ]);

            if ($journals->isNotEmpty()) {
                $groups[] = ['title' => 'Journals', 'items' => $journals->values()];
            }
        }

        if ($user->can('crm.leads.view')) {
            $leads = Lead::query()
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like))
                ->orderByDesc('id')
                ->limit(self::PER_GROUP)
                ->get(['id', 'name', 'phone'])
                ->map(fn (Lead $l) => [
                    'label' => $l->name,
                    'sub' => $l->phone ?? 'Lead',
                    'url' => '/crm/leads?search='.urlencode($q),
                ]);

            if ($leads->isNotEmpty()) {
                $groups[] = ['title' => 'Leads', 'items' => $leads->values()];
            }
        }

        return response()->json(['groups' => $groups]);
    }
}
