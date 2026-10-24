<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\CustomerStatement;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\PartyCard;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Corrections\ValidatesActionReason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\DuplicateKeys;
use App\Modules\Foundation\Support\IdentityDocuments;
use App\Modules\Foundation\Support\PartyRecordActions;
use App\Modules\Foundation\Support\SaveAnyway;
use App\Modules\Sales\Corrections\CustomerDuplicateRule;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\SalesInvoice;
use App\Support\Documents\PartyDocuments;
use App\Support\RecordTimeline;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    use ValidatesActionReason;

    /**
     * The customer 360 (C-360): one screen that answers "tell me about this
     * customer". It OWNS nothing - the balance is the CustomerStatement's own
     * closing under the C-CST-2 identity, open invoices derive from their
     * allocations, and the movement list is the statement's lines - so this
     * page can never disagree with the reports it summarises.
     */
    public function show(Customer $customer): Response
    {
        // ⭐ Phase 2 (17 Oct 2026): a staff customer's page (what an employee
        // bought and owes) opens for Owner, HR authority and Finance only.
        abort_if($customer->isStaff() && ! Customer::staffVisibleTo(request()->user()), 403, 'Staff purchase accounts are visible to the Owner, HR and Finance only.');

        // A window from before the business existed to today: the closing IS
        // the outstanding balance, the charges and settlements ARE lifetime
        // totals, and the ledger tie-out comes along for free.
        $statement = CustomerStatement::for($customer, Carbon::parse('2000-01-01'), Carbon::today());
        $balance = $statement->closing();

        $today = Carbon::today();

        // ⚠ Bug hunt 16 Oct 2026, the SAL-009 cost: this loaded every invoice
        // the customer ever had (the walk-in customer: every counter sale) and
        // ran three SUM queries for each, twice. Settled ones are left out in
        // SQL and what each still owes comes back on the same row.
        $openInvoices = SalesInvoice::query()
            ->where('customer_id', $customer->id)
            ->unsettled()
            ->withOutstandingPaisa()
            ->orderBy('invoice_date')->orderBy('id')
            ->get()
            ->map(fn (SalesInvoice $invoice) => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'total' => $invoice->totalMoney()->format(),
                'outstanding' => Money::ofMinor((int) $invoice->outstanding_paisa)->format(),
                // ⚠ Date-string comparison, the recurring trap.
                'days_overdue' => ($invoice->due_date !== null && $invoice->due_date->toDateString() < $today->toDateString())
                    ? (int) Carbon::parse($invoice->due_date->toDateString())->diffInDays($today)
                    : 0,
            ]);

        $limit = $customer->credit_limit === null ? null : Money::ofTaka((string) $customer->credit_limit);

        // ⭐ PR5 (18 Oct 2026): EVERY document with this customer, each row
        // opening its own page (Tally's drill-down), paged, by type.
        $all = PartyDocuments::page(
            PartyDocuments::forCustomer($customer, request()->user()),
            request()->string('doc_type')->toString(),
            request()->integer('docs_page', 1),
            '/sales/customers/'.$customer->id,
        );

        return Inertia::render('sales/customers/show', [
            'all_documents' => $all,
            'customer' => [
                'id' => $customer->id,
                'code' => $customer->code,
                'name' => $customer->name,
                'type' => $customer->type?->label(),
                'contact_name' => $customer->contact_name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'address' => $customer->address,
                'payment_terms' => $customer->payment_terms,
                'credit_days' => $customer->credit_days,
                'credit_days_effective' => $customer->creditDays(),
                'price_group' => $customer->priceGroup?->name ?? (($customer->type?->defaultPriceGroupCode() ?? 'RETAIL').' (default)'),
                'is_active' => $customer->is_active,
                'note' => $customer->note,
            ],
            'balance' => [
                'amount' => $balance->format(),
                'is_credit' => $balance->minor < 0,
                // Null when AR is unmapped - say nothing rather than show a
                // tick that was never tested (the C-GLA-7 stance).
                'ledger_agrees' => $statement->hasIndependentCheck() ? $statement->isConsistent() : null,
            ],
            'totals' => [
                'invoiced' => $statement->charges->format(),
                'settled' => $statement->settlements->absolute()->format(),
            ],
            'credit' => [
                'limit' => $limit?->format(),
                'over' => $limit !== null && $limit->minor > 0 && $balance->minor > $limit->minor,
            ],
            'open_invoices' => $openInvoices,
            'recent' => $statement->lines->reverse()->take(15)->values()
                ->map(fn ($line) => [
                    'date' => $line->date->toDateString(),
                    'kind' => $line->kind,
                    'label' => $line->label,
                    'reference' => $line->reference,
                    'amount' => $line->amount->format(),
                    'balance' => $line->balance->format(),
                ]),
            'statement_url' => '/accounting/customer-statement?customer='.$customer->id,
            // ⭐ B13 (24 Oct 2026): Reactivate, Delete to the bin (never used).
            'record' => PartyRecordActions::for($customer, request()->user()),
            // The record's own story (Module 1, Slice 4).
            'timeline' => RecordTimeline::for($customer),
            // ⭐ Plan D11: trade licence and NID scans, NID as a count unless the
            // reader may open it.
            'documents' => IdentityDocuments::props($customer, request()->user()),
            // ⭐ D-F (18 Oct 2026): also our supplier? Both sides together.
            'party' => PartyCard::forCustomer($customer, request()->user()),
        ]);
    }

    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->toString(),
            'type' => $request->string('type')->toString(),
            'status' => $request->string('status')->toString(),
        ];

        $customers = Customer::query()->listable()
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
            'types' => collect(CustomerType::pickable())
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
            // ⭐ Credit limit and days are granted through a Credit terms request
            // (26 Sep 2026, Q16); only the authority types them straight in.
            'mayGrantCredit' => request()->user()?->can('sales.credit.approve') ?? false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        // ⭐ B13 (24 Oct 2026; FINAL-PLAN 5): same phone or same name asks
        // "Possible duplicate" first; Save anyway is kept beside the row.
        $ack = SaveAnyway::ask(app(CustomerDuplicateRule::class), $data, $request, (int) app(BusinessContext::class)->id(), 'Possible duplicate. Is this a different customer?');

        DB::transaction(function () use ($data, $ack) {
            SaveAnyway::record($ack, Customer::create($data));
        });

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

        // ⭐ B13: asked only when the phone or the name changes to match another.
        $ack = null;
        if (DuplicateKeys::phone($data['phone'] ?? null) !== DuplicateKeys::phone($customer->phone) || DuplicateKeys::name($data['name'] ?? null) !== DuplicateKeys::name($customer->name)) {
            $ack = SaveAnyway::ask(app(CustomerDuplicateRule::class), $data, $request, (int) $customer->business_id, 'Possible duplicate. Is this a different customer?', [$customer->getMorphClass().':'.$customer->id]);
        }

        DB::transaction(function () use ($customer, $data, $ack) {
            $customer->update($data);
            SaveAnyway::record($ack, $customer);
        });

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
     * ⭐ B13 (24 Oct 2026; FINAL-PLAN 3.2 "Reactivate"): a switched-off customer
     * comes back on screen (before this only the CSV could bring one back).
     * Logged in its History. A merged one is refused: it points at the kept one.
     */
    public function reactivate(Request $request, Customer $customer): RedirectResponse
    {
        if (($why = $customer->whyNotReactivatable()) !== null) {
            throw ValidationException::withMessages(['customer' => $why]);
        }

        $customer->update(['is_active' => true]);
        CorrectionLog::event('reactivated', $request->user(), $customer, ['name' => $customer->name]);

        return back()->with('success', "{$customer->name} is active again.");
    }

    /**
     * ⭐ B13: Delete to the Recycle bin, only when never used (whyNotRemovable),
     * with a reason (5 characters at least, checked here). The Owner restores
     * it from Settings > Recycle bin; nothing is ever purged.
     */
    public function bin(Request $request, Customer $customer): RedirectResponse
    {
        $request->validate($this->actionReasonRules('deletion'), $this->actionReasonMessages());

        DB::transaction(fn () => Customer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail()
            ->recycle($request->user(), $this->actionReason($request)));

        return redirect()->route('sales.customers.index')->with('success', "{$customer->name} moved to the Recycle bin. The Owner can restore it.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Customer $customer = null): array
    {
        $businessId = $customer?->business_id ?? app(BusinessContext::class)->id();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // ⚠ Scoped to the business. A distributor code is unique to us, not
            // globally, and two businesses may both have a DIST-001.
            'code' => [
                'nullable', 'string', 'max:50',
                Rule::unique('customers', 'code')
                    ->where('business_id', $businessId)
                    ->ignore($customer?->id),
            ],
            // ⚠ Staff rows are made by the staff-purchase flow (Phase 2), never typed.
            // ⭐ D-B (18 Oct 2026): a system-made row (staff, owner) keeps its own
            // type when its details are edited; nobody can type one in.
            'type' => ['required', Rule::enum(CustomerType::class)->only(array_values(array_unique(array_filter([...CustomerType::pickable(), $customer?->type]), SORT_REGULAR)))],
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
            // ⭐ Days, not words (26 Sep 2026). Blank means the default, 10.
            'credit_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            // ⚠ Tenant-scoped: a price group from another business must be
            // unreachable (C-PRC-6). Null means RETAIL, the default group.
            'price_group_id' => [
                'nullable', 'integer',
                Rule::exists('price_groups', 'id')->where('business_id', $businessId),
                // ⭐ 26 Sep 2026: a retail SHOP is never priced in RETAIL, the
                // consumer group that falls back to MRP. Blank is fine (it
                // defaults to SHOP); RETAIL by hand is refused in plain words.
                function (string $attribute, mixed $value, \Closure $fail) use ($request, $businessId) {
                    if ($request->input('type') !== CustomerType::Shop->value || $value === null || $value === '') {
                        return;
                    }

                    $code = PriceGroup::query()->withoutGlobalScopes()->where('business_id', $businessId)->whereKey((int) $value)->value('code');

                    if ($code === PriceGroup::DEFAULT_CODE) {
                        $fail('A retail shop never pays MRP. Leave the price group blank for Retail shop, or choose another group; RETAIL is for consumers.');
                    }
                },
            ],
            'note' => ['nullable', 'string'],
        ]);

        // ⭐ Credit terms are granted, not typed (26 Sep 2026, Q16). Without
        // sales.credit.approve the two fields are ignored on create and kept
        // as they are on edit, and the form says so; a Credit terms request
        // is the way to change them.
        if (! $request->user()->can('sales.credit.approve')) {
            unset($validated['credit_limit'], $validated['credit_days']);
        }

        return $validated;
    }
}
