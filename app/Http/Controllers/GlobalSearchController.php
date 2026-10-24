<?php

namespace App\Http\Controllers;

use App\Modules\Accounting\Models\Journal;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Support\LeadVisibility;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Support\Features;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The header search (Joy's DreamsPOS ask, 23 Aug 2026): type a thing, land on
 * its page. Products, customers, suppliers, employees, invoices, documents.
 *
 * ⚠⚠ PERMISSION-FILTERED SERVER-SIDE, group by group, with the SAME gates the
 * screens themselves use. A search box that lists an employee to somebody who
 * cannot open the employee page is a directory leak with autocomplete.
 *
 * ⚠ Tenancy costs nothing for the models: every one carries the business
 * global scope (employees confine through assignments, same as their pages).
 * The three raw-table groups at the bottom have no model, so they scope on
 * `business_id` BY HAND against BusinessContext - the same id their own
 * controllers use. Miss that line and the box shows another company's deals.
 *
 * ⚠ LIKE with a leading wildcard cannot use an index, and does not need to at
 * this scale (153 products, 9 employees). If the catalogue ever grows to where
 * it hurts, the fix is a search index, not a cleverer LIKE.
 *
 * ── 6 Sep 2026, Joy: "the search box not showing enough link to click" ──
 * Three things were wrong and all three are fixed here.
 *
 * 1. Every group was capped at five with NO total and NO way to the rest, so a
 *    word matching forty products showed five and offered no sixth click. Each
 *    group now reports its true `total` and, when there are more than fit, a
 *    `more_url` pointing at the list screen filtered by the same words.
 *    ⚠ A more_url is only ever set to a screen that genuinely READS `search` -
 *    verified controller by controller. A link to a filter that gets ignored
 *    is worse than no link, because it silently lands you on page one of
 *    everything and looks like the search was wrong.
 * 2. Customers and Suppliers pointed at a filtered LIST even though both have
 *    a record page. They now land on the record. Products, Leads, Expenses,
 *    Quotations, Tickets and Opportunities have no record page in this build,
 *    so a list is the honest destination and stays.
 * 3. Quotations, tickets and opportunities shipped in Modules 3-4 and were
 *    never added here, so three whole document types were unsearchable.
 */
class GlobalSearchController extends Controller
{
    /**
     * Eight, not five. Five was chosen when the box held four groups; it now
     * holds up to thirteen, and the common case (a customer's name matching
     * their orders, invoices and tickets at once) was being trimmed hardest
     * exactly where the person was looking.
     */
    private const PER_GROUP = 8;

    /** @var array<int, array<string, mixed>> */
    private array $groups = [];

    private string $q = '';

    public function __invoke(Request $request): JsonResponse
    {
        $this->groups = [];
        $this->q = trim($request->string('q')->toString());

        if (mb_strlen($this->q) < 2) {
            return response()->json(['groups' => [], 'total' => 0]);
        }

        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $this->q).'%';
        $user = $request->user();
        $businessId = app(BusinessContext::class)->id();

        // ⚠ Products and Suppliers were the two ungated groups here - "open to
        // the business" predates the zero-permission Self-service role
        // (24 Aug 2026). Each group wears the permission its destination
        // screen enforces, so search can never show what a click would refuse.
        if ($user->can('inventory.reports.view')) {
            $products = Product::query()
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('sku', 'like', $like));

            $this->add(
                'Products',
                'product',
                $this->take($products, 'name', ['id', 'name', 'sku'], fn (Product $p) => [
                    'label' => $p->name,
                    'sub' => $p->sku,
                    // No product record page exists in this build; the filtered
                    // list IS the destination, and it does read `search`.
                    'url' => '/inventory/products?search='.urlencode($this->q),
                ]),
                (clone $products)->count(),
                '/inventory/products?search='.urlencode($this->q),
            );
        }

        if ($user->can('sales.reports.view')) {
            // A phone number is how the counter remembers a person.
            $customers = Customer::query()
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like));

            $this->add(
                'Customers',
                'customer',
                $this->take($customers, 'name', ['id', 'name', 'phone'], fn (Customer $c) => [
                    'label' => $c->name,
                    'sub' => $c->phone ?: 'Customer',
                    'url' => '/sales/customers/'.$c->id,
                ]),
                (clone $customers)->count(),
                '/sales/customers?search='.urlencode($this->q),
            );

            $invoices = SalesInvoice::query()->where('invoice_number', 'like', $like);

            $this->add(
                'Invoices',
                'invoice',
                $this->take($invoices, null, ['id', 'invoice_number'], fn (SalesInvoice $i) => [
                    'label' => $i->invoice_number,
                    'sub' => 'Invoice',
                    'url' => '/sales/invoices/'.$i->id,
                ]),
                (clone $invoices)->count(),
                '/sales/invoices?search='.urlencode($this->q),
            );

            $orders = SalesOrder::query()->where('order_number', 'like', $like);

            $this->add(
                'Sales orders',
                'order',
                $this->take($orders, null, ['id', 'order_number'], fn (SalesOrder $o) => [
                    'label' => $o->order_number,
                    'sub' => 'Sales order',
                    'url' => '/sales/orders/'.$o->id,
                ]),
                (clone $orders)->count(),
                '/sales/orders?search='.urlencode($this->q),
            );
        }

        // Quotations are gated on recording an order, because accepting one
        // IS taking the order - the same trust their own routes require.
        if ($user->can('sales.orders.record')) {
            $quotations = DB::table('quotations')
                ->where('business_id', $businessId)
                ->where('quotation_number', 'like', $like);

            $this->add(
                'Quotations',
                'quotation',
                $this->takeRaw($quotations, 'id', 'desc', fn ($row) => [
                    'label' => $row->quotation_number,
                    'sub' => ucfirst((string) $row->status),
                    'url' => '/sales/quotations',
                ]),
                (clone $quotations)->count(),
                '/sales/quotations',
            );
        }

        if ($user->can('purchasing.reports.view')) {
            $suppliers = Supplier::query()->where('name', 'like', $like);

            $this->add(
                'Suppliers',
                'supplier',
                $this->take($suppliers, 'name', ['id', 'name'], fn (Supplier $s) => [
                    'label' => $s->name,
                    'sub' => 'Supplier',
                    'url' => '/purchasing/suppliers/'.$s->id,
                ]),
                (clone $suppliers)->count(),
                '/purchasing/suppliers?search='.urlencode($this->q),
            );

            $purchaseOrders = PurchaseOrder::query()->where('po_number', 'like', $like);

            $this->add(
                'Purchase orders',
                'purchase-order',
                $this->take($purchaseOrders, null, ['id', 'po_number'], fn (PurchaseOrder $po) => [
                    'label' => $po->po_number,
                    'sub' => 'Purchase order',
                    'url' => '/purchasing/purchase-orders/'.$po->id,
                ]),
                (clone $purchaseOrders)->count(),
                '/purchasing/purchase-orders?search='.urlencode($this->q),
            );

            // The list is the expenses page; the reference is how a standing
            // cost is remembered (NIKOLA-2026-09).
            // ⚠ That screen had NO search filter at all until today, so this
            // link landed on page one of every expense ever recorded. The
            // filter was added in the same commit as this line.
            $vouchers = ExpenseVoucher::query()
                ->where(fn ($w) => $w->where('voucher_number', 'like', $like)->orWhere('reference', 'like', $like));

            $this->add(
                'Expenses',
                'expense',
                $this->take($vouchers, null, ['id', 'voucher_number', 'reference'], fn (ExpenseVoucher $v) => [
                    'label' => $v->voucher_number,
                    'sub' => $v->reference ?: 'Expense',
                    'url' => '/purchasing/expenses?search='.urlencode($this->q),
                ]),
                (clone $vouchers)->count(),
                '/purchasing/expenses?search='.urlencode($this->q),
            );
        }

        if ($user->can('hrm.employees.manage')) {
            $employees = Employee::query()
                ->whereHas('assignments')
                ->where(fn ($w) => $w
                    ->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('employee_code', 'like', $like));

            $this->add(
                'Employees',
                'employee',
                $this->take($employees, 'employee_code', ['id', 'first_name', 'last_name', 'employee_code'], fn (Employee $e) => [
                    'label' => $e->fullName(),
                    'sub' => $e->employee_code,
                    'url' => '/hrm/employees/'.$e->id,
                ]),
                (clone $employees)->count(),
                // ⚠ No more_url: the employee list does NOT read `search`, and
                // a link to an ignored filter is worse than no link at all.
                null,
            );
        }

        if ($user->can('accounting.view')) {
            $journals = Journal::query()->where('journal_number', 'like', $like);

            $this->add(
                'Journal register',
                'journal',
                $this->take($journals, null, ['id', 'journal_number', 'memo'], fn (Journal $j) => [
                    'label' => $j->journal_number,
                    'sub' => mb_strimwidth((string) $j->memo, 0, 40, '...'),
                    'url' => '/accounting/journals/'.$j->id,
                ]),
                (clone $journals)->count(),
                null,
            );
        }

        if ($user->can('crm.leads.view')) {
            $leads = Lead::query()
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like));

            $this->add(
                'Leads',
                'lead',
                $this->take($leads, null, ['id', 'name', 'phone'], fn (Lead $l) => [
                    'label' => $l->name,
                    'sub' => $l->phone ?: 'Lead',
                    'url' => '/crm/leads?search='.urlencode($this->q),
                ]),
                (clone $leads)->count(),
                '/crm/leads?search='.urlencode($this->q),
            );

            // ⚠ Leads above are narrowed by their model's scope; deals are plain
            // rows, so the same rule is applied by hand (15 Sep 2026).
            $opportunities = DB::table('crm_opportunities')
                ->where('business_id', $businessId)
                ->where('name', 'like', $like)
                ->tap(fn ($q) => app(LeadVisibility::class)->restrict($q, $user, 'crm_opportunities.assigned_to_user_id'));

            $this->add(
                'Opportunities',
                'opportunity',
                $this->takeRaw($opportunities, 'id', 'desc', fn ($row) => [
                    'label' => $row->name,
                    'sub' => ucfirst((string) $row->status),
                    'url' => '/crm/pipeline',
                ]),
                (clone $opportunities)->count(),
                '/crm/pipeline',
            );

            // ⚠ Tickets are hidden since 24 Oct 2026 (Joy): no search group
            // while config/features.php keeps `crm_tickets` off.
            if (Features::on(Features::CRM_TICKETS)) {
                // A complaint is found by its number, its subject, or the name of
                // whoever rang up - which is usually all the counter remembers.
                $tickets = DB::table('crm_tickets')
                    ->where('business_id', $businessId)
                    ->where(fn ($w) => $w
                        ->where('ticket_number', 'like', $like)
                        ->orWhere('subject', 'like', $like)
                        ->orWhere('caller_name', 'like', $like)
                        ->orWhere('caller_phone', 'like', $like));

                $this->add(
                    'Tickets',
                    'ticket',
                    $this->takeRaw($tickets, 'id', 'desc', fn ($row) => [
                        'label' => $row->ticket_number,
                        'sub' => mb_strimwidth((string) $row->subject, 0, 40, '...'),
                        'url' => '/crm/tickets',
                    ]),
                    (clone $tickets)->count(),
                    '/crm/tickets',
                );
            }
        }

        return response()->json([
            'groups' => $this->groups,
            'total' => array_sum(array_column($this->groups, 'total')),
        ]);
    }

    /**
     * One page of an Eloquent group, mapped to the flat item shape the header
     * renders. Ordering by null means "newest first", which is what every
     * document group wants.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  array<int, string>  $columns
     */
    private function take($query, ?string $orderBy, array $columns, callable $map): Collection
    {
        $page = (clone $query);
        $page = $orderBy ? $page->orderBy($orderBy) : $page->orderByDesc('id');

        return $page->limit(self::PER_GROUP)->get($columns)->map($map);
    }

    /**
     * The same, for the three groups whose tables have no Eloquent model.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    private function takeRaw($query, string $orderBy, string $direction, callable $map): Collection
    {
        return (clone $query)
            ->orderBy($orderBy, $direction)
            ->limit(self::PER_GROUP)
            ->get()
            ->map($map);
    }

    /**
     * A group is only added when it has something in it, and only carries a
     * "see the rest" link when there genuinely IS a rest to see.
     */
    private function add(string $title, string $icon, Collection $items, int $total, ?string $moreUrl): void
    {
        if ($items->isEmpty()) {
            return;
        }

        $this->groups[] = [
            'title' => $title,
            'icon' => $icon,
            'items' => $items->values()->all(),
            'total' => $total,
            'more_url' => $total > $items->count() ? $moreUrl : null,
        ];
    }
}
