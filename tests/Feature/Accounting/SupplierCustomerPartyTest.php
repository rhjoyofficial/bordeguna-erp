<?php

use App\Models\User;
use App\Modules\Accounting\Actions\LinkSupplierCustomer;
use App\Modules\Accounting\Models\Counterparty;
use App\Modules\Accounting\Support\PartyPosition;
use App\Modules\Accounting\Support\PartyStatement;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PartyWorld;

/**
 * ⭐ A supplier can also be our customer (Joy, 18 Oct 2026, decision D-F): one
 * firm, linked both ways, two ledgers never merged, both sides shown together
 * on both pages, and one combined statement with a running net.
 */
beforeEach(function () {
    // New pages are not in the copied build manifest; the page component is what is asserted.
    $this->withoutVite();
    $this->p = PartyWorld::make();
    $this->w = $this->p->w;
    $this->link = app(LinkSupplierCustomer::class);
});

afterEach(fn () => Carbon::setTestNow());

it('links from the supplier page and from the customer page, one to one', function () {
    $supplier = Supplier::create(['business_id' => $this->w->business->id, 'code' => 'KARIM', 'name' => 'Karim Oil Mill']);
    $customer = Customer::create(['business_id' => $this->w->business->id, 'code' => 'KARIM-C', 'name' => 'Karim Oil Mill', 'type' => 'dealer']);

    // From the supplier page (the Owner may manage both lists).
    $this->actingAs($this->w->owner)->post(route('accounting.parties.link'), ['supplier_id' => $supplier->id, 'customer_id' => $customer->id])
        ->assertRedirect()->assertSessionHas('success');

    expect($customer->fresh()->supplier_id)->toBe($supplier->id)
        ->and($supplier->alsoCustomer->id)->toBe($customer->id)
        ->and($customer->fresh()->alsoSupplier->id)->toBe($supplier->id);

    // Unlinked, then linked again from the customer page.
    $this->actingAs($this->w->owner)->post(route('accounting.parties.unlink', $customer))->assertRedirect();
    expect($customer->fresh()->supplier_id)->toBeNull();

    $this->actingAs($this->w->owner)->post(route('accounting.parties.link'), ['customer_id' => $customer->id, 'supplier_id' => $supplier->id])->assertRedirect();
    expect($customer->fresh()->supplier_id)->toBe($supplier->id);
});

it('keeps the link unique: one supplier, one customer, in the action and in the database', function () {
    $second = Customer::create(['business_id' => $this->w->business->id, 'code' => 'RAHMAN-2', 'name' => 'Rahman Traders Branch', 'type' => 'shop']);
    $otherSupplier = Supplier::create(['business_id' => $this->w->business->id, 'code' => 'OTHER', 'name' => 'Other Mill']);

    expect(fn () => $this->link->link($this->p->supplier, $second, $this->w->owner))
        ->toThrow(ValidationException::class, 'already linked to the customer Rahman Traders')
        ->and(fn () => $this->link->link($otherSupplier, $this->p->customer->fresh(), $this->w->owner))
        ->toThrow(ValidationException::class, 'already linked to another supplier');

    expect(fn () => DB::table('customers')->where('id', $second->id)->update(['supplier_id' => $this->p->supplier->id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('refuses a staff or walk-in account, and a link that contradicts a lender counterparty', function () {
    $staff = Customer::create(['business_id' => $this->w->business->id, 'code' => 'STAFF-1', 'name' => 'Musa', 'type' => 'staff']);
    $supplier = Supplier::create(['business_id' => $this->w->business->id, 'code' => 'SALAM', 'name' => 'Salam Store']);

    expect(fn () => $this->link->link($supplier, $staff, $this->w->owner))->toThrow(ValidationException::class, 'staff or walk-in');

    $customer = Customer::create(['business_id' => $this->w->business->id, 'code' => 'SALAM-C', 'name' => 'Salam Store', 'type' => 'shop']);
    $elsewhere = Customer::create(['business_id' => $this->w->business->id, 'code' => 'ELSE', 'name' => 'Somebody Else', 'type' => 'shop']);
    Counterparty::create(['legal_name' => 'Salam Store Ltd', 'supplier_id' => $supplier->id, 'customer_id' => $elsewhere->id]);

    expect(fn () => $this->link->link($supplier, $customer, $this->w->owner))->toThrow(ValidationException::class, 'One firm has one identity');
});

it('creates the customer from a supplier, and the supplier from a customer, copying name, phone and address', function () {
    $supplier = Supplier::create(['business_id' => $this->w->business->id, 'code' => 'HASAN', 'name' => 'Hasan Agro', 'phone' => '01812000000', 'address' => 'Bogura', 'contact_name' => 'Hasan']);

    $this->actingAs($this->w->owner)->post(route('accounting.parties.customer-from-supplier', $supplier))->assertRedirect();

    $customer = Customer::query()->where('supplier_id', $supplier->id)->sole();
    expect($customer->name)->toBe('Hasan Agro')
        ->and($customer->phone)->toBe('01812000000')
        ->and($customer->address)->toBe('Bogura')
        ->and($customer->type->value)->toBe('corporate');

    $buyer = Customer::create(['business_id' => $this->w->business->id, 'code' => 'NOOR', 'name' => 'Noor Foods', 'type' => 'dealer', 'phone' => '01912000000', 'address' => 'Sylhet']);
    $this->actingAs($this->w->owner)->post(route('accounting.parties.supplier-from-customer', $buyer))->assertRedirect();

    $made = Supplier::query()->findOrFail($buyer->fresh()->supplier_id);
    expect($made->name)->toBe('Noor Foods')
        ->and($made->phone)->toBe('01912000000')
        ->and($made->address)->toBe('Sylhet')
        ->and($made->code)->toBe('NOORFOOD');
});

it('lets only a person who manages both lists link, and refuses to unlink under a waiting set-off', function () {
    $supplier = Supplier::create(['business_id' => $this->w->business->id, 'code' => 'X1', 'name' => 'X One']);
    $customer = Customer::create(['business_id' => $this->w->business->id, 'code' => 'X1C', 'name' => 'X One', 'type' => 'shop']);

    // Finance reads both sides but manages neither list.
    $this->actingAs($this->w->financeA)->post(route('accounting.parties.link'), ['supplier_id' => $supplier->id, 'customer_id' => $customer->id])->assertForbidden();

    $bill = $this->p->bill('500');
    $invoice = $this->p->invoice('500');
    $this->p->raise([$bill->id => '100'], [$invoice->id => '100']);

    expect(fn () => $this->link->unlink($this->p->customer->fresh(), $this->w->owner))->toThrow(ValidationException::class, 'stands between these two');
});

it('shows both sides together on the supplier page and the customer page', function () {
    $this->p->bill('8000');
    $this->p->bill('1500');
    $this->p->invoice('5000');

    $position = PartyPosition::of($this->p->supplier, $this->p->customer->fresh());
    expect($position->weOwe->format())->toBe('9,500.00')
        ->and($position->theyOwe->format())->toBe('5,000.00')
        ->and($position->net()->minor)->toBe(-450_000)
        ->and($position->settleable()->format())->toBe('5,000.00');

    $card = fn (Assert $page) => $page->where('party.linked.supplier_id', $this->p->supplier->id)
        ->where('party.linked.customer_id', $this->p->customer->id)
        ->where('party.position.we_owe', '9,500.00')
        ->where('party.position.they_owe', '5,000.00')
        ->where('party.position.net', '4,500.00')
        ->where('party.position.net_side', 'them')
        ->where('party.position.open_bills', 2)
        ->where('party.position.open_invoices', 1)
        ->where('party.can_raise', true);

    $this->actingAs($this->w->financeA)->get(route('purchasing.suppliers.show', $this->p->supplier))
        ->assertOk()->assertInertia(fn (Assert $page) => $card($page->component('purchasing/suppliers/show')));

    $this->actingAs($this->w->financeA)->get(route('sales.customers.show', $this->p->customer))
        ->assertOk()->assertInertia(fn (Assert $page) => $card($page->component('sales/customers/show')));

    // An unlinked supplier, seen by somebody who may link: the options, no figures.
    $loose = Supplier::create(['business_id' => $this->w->business->id, 'code' => 'LOOSE', 'name' => 'Loose Supplier']);
    $this->actingAs($this->w->owner)->get(route('purchasing.suppliers.show', $loose))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('party.linked', null)
        ->where('party.position', null)
        ->where('party.can_link', true)
        ->where('party.options', fn ($options) => collect($options)->pluck('id')->doesntContain($this->p->customer->id)));
});

it('builds the combined statement from both statements: a running net, and a set-off that leaves the net alone', function () {
    $bill = $this->p->bill('8000', '2026-10-02');
    $invoice = $this->p->invoice('5000', '2026-10-05');
    $this->p->posted([$bill->id => '3000'], [$invoice->id => '3000']);

    $statement = PartyStatement::for($this->p->supplier, $this->p->customer->fresh(), Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')->endOfDay());
    $nets = $statement->lines->map(fn (array $line) => [$line['side'], $line['net']->minor])->all();

    expect($statement->openingNet()->minor)->toBe(0)
        ->and($nets)->toBe([
            ['supplier', -800_000],   // the bill: we owe them 8,000
            ['customer', -300_000],   // the invoice: they owe us 5,000
            ['customer', -600_000],   // the set-off, customer side: they owe 3,000 less
            ['supplier', -300_000],   // the set-off, supplier side: we owe 3,000 less
        ])
        ->and($statement->closingNet()->minor)->toBe(-300_000)
        ->and($statement->customerSide->closing()->format())->toBe('2,000.00')
        ->and($statement->supplierSide->closing()->format())->toBe('5,000.00')
        ->and($statement->consistent())->toBeTrue();

    $this->actingAs($this->w->financeA)->get(route('accounting.party-statement', ['customer' => $this->p->customer->id, 'from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('accounting/party-statement/index')
            ->where('party.supplier_id', $this->p->supplier->id)
            ->has('lines', 4)
            ->where('summary.closing.amount', '3,000.00')
            ->where('summary.closing.side', 'them')
            ->where('summary.they_owe_closing', '2,000.00')
            ->where('summary.we_owe_closing', '5,000.00')
            ->where('summary.consistent', true));

    // Reading it needs both sides.
    $buyer = User::factory()->create();
    $buyer->businesses()->attach($this->w->business->id);
    $buyer->givePermissionTo('sales.reports.view');
    $this->actingAs($buyer)->get(route('accounting.party-statement'))->assertForbidden();
});

it('registers both new pages in the Purchases sections, each title in the Bangla dictionary', function () {
    $sections = file_get_contents(resource_path('js/components/purchasing/purchases-sections.ts'));
    $dictionary = file_get_contents(resource_path('js/lib/i18n/parties.ts'));

    foreach (['/accounting/set-offs' => 'set_off.title', '/accounting/party-statement' => 'party_statement.title'] as $url => $key) {
        expect($sections)->toMatch("/titleKey: '".preg_quote($key, '/')."',\s*url: '".preg_quote($url, '/')."',\s*can: \(f: SharedData\) => !!f\.canViewParties/")
            ->and($dictionary)->toContain("'{$key}': {");
    }

    expect(file_get_contents(resource_path('js/lib/i18n.ts')))->toContain('...parties,');

    // The shared flag is the two read permissions together.
    $this->actingAs($this->w->financeA)->get('/settings/appearance')
        ->assertInertia(fn (Assert $page) => $page->where('canViewParties', true)->where('canManageSetOffs', true));
    $this->actingAs($this->w->manager)->get('/settings/appearance')
        ->assertInertia(fn (Assert $page) => $page->where('canManageSetOffs', false));
});
