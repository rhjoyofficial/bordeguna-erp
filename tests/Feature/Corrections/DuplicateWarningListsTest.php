<?php

use App\Modules\Crm\Models\Contact;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Models\DuplicateAcknowledgement;
use App\Modules\Foundation\Support\DuplicateKeys;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PeopleWorld;
use Tests\Support\SalesWorld;
use Tests\Support\SupplierMoneyWorld;

/**
 * ⭐ Corrections follow-up (B13, 24 Oct 2026; FINAL-PLAN 5): "Possible
 * duplicate ... Save anyway" on customers, suppliers, employees, products, the
 * phone book's quick add, the receipt taken from the invoice page, and a
 * supplier payment's Correct. A warning, never a block; the ack is kept.
 */
beforeEach(function () {
    $this->s = SalesWorld::make();
    $this->w = $this->s->w;

    $permissions = ['sales.customers.manage', 'purchasing.suppliers.manage', 'purchasing.reports.view', 'inventory.catalog.manage', 'inventory.reports.view', 'crm.contacts.view', 'crm.contacts.manage', 'hrm.employees.manage'];
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }
    Role::where('name', 'Owner')->where('business_id', $this->w->business->id)->firstOrFail()->givePermissionTo($permissions);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(fn () => Carbon::setTestNow());

function customerForm(array $changes = []): array
{
    return array_merge(['name' => 'Rahim Traders', 'type' => 'dealer', 'phone' => '01712-345678'], $changes);
}

it('reads Bangla digits, +88 and a lost leading zero as one phone', function () {
    expect(DuplicateKeys::phone('০১৭১২-৩৪৫৬৭৮'))->toBe('01712345678')
        ->and(DuplicateKeys::phone('+880 1712 345678'))->toBe('01712345678')
        ->and(DuplicateKeys::phone('1712345678'))->toBe('01712345678')
        ->and(DuplicateKeys::phone('12'))->toBeNull()
        ->and(DuplicateKeys::name('  Rahim   TRADERS '))->toBe(DuplicateKeys::name('rahim traders'));
});

it('asks before saving a customer with the same phone; Save anyway saves it and keeps the ack', function () {
    $this->actingAs($this->w->owner)->post(route('sales.customers.store'), customerForm())->assertSessionHasNoErrors();

    // Same number typed in Bangla digits with +88: asked, nothing saved.
    $this->actingAs($this->w->owner)
        ->post(route('sales.customers.store'), customerForm(['name' => 'Rahim Store', 'phone' => '+৮৮০১৭১২৩৪৫৬৭৮']))
        ->assertSessionHasErrors('_duplicate')
        ->assertSessionHas('duplicateWarning');
    expect(Customer::query()->where('name', 'Rahim Store')->exists())->toBeFalse();

    $ack = session('duplicateWarning')['ack_token'];
    expect(session('duplicateWarning')['candidates'][0]['summary'])->toContain('Rahim Traders');

    // A changed form is asked again: the ack is bound to what was typed.
    $this->actingAs($this->w->owner)
        ->post(route('sales.customers.store'), customerForm(['name' => 'Rahim Shop', 'phone' => '+৮৮০১৭১২৩৪৫৬৭৮', 'duplicate_ack' => $ack]))
        ->assertSessionHasErrors('_duplicate');

    $this->actingAs($this->w->owner)
        ->post(route('sales.customers.store'), customerForm(['name' => 'Rahim Store', 'phone' => '+৮৮০১৭১২৩৪৫৬৭৮', 'duplicate_ack' => $ack]))
        ->assertSessionHasNoErrors();

    $saved = Customer::query()->where('name', 'Rahim Store')->sole();
    expect(DuplicateAcknowledgement::query()->where('subject_id', $saved->id)->where('rule_key', 'sales.customer')->count())->toBe(1);
});

it('editing a customer asks only when the phone or name changes to match another', function () {
    $this->actingAs($this->w->owner)->post(route('sales.customers.store'), customerForm())->assertSessionHasNoErrors();
    $other = Customer::query()->create(['business_id' => $this->w->business->id, 'name' => 'Karim Stores', 'type' => 'dealer', 'phone' => '01911000000']);

    // Its own note changes: no question about itself.
    $this->actingAs($this->w->owner)
        ->put(route('sales.customers.update', $other), ['name' => 'Karim Stores', 'type' => 'dealer', 'phone' => '01911000000', 'note' => 'Pays on Thursdays'])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->w->owner)
        ->put(route('sales.customers.update', $other), ['name' => 'Karim Stores', 'type' => 'dealer', 'phone' => '01712345678'])
        ->assertSessionHasErrors('_duplicate');
});

it('asks before saving a supplier with the same name, ignoring case and spaces', function () {
    $this->actingAs($this->w->owner)->post(route('purchasing.suppliers.store'), ['code' => 'SUP-9', 'name' => 'Padma Oil Mills', 'phone' => '01811000000'])->assertSessionHasNoErrors();

    $this->actingAs($this->w->owner)
        ->post(route('purchasing.suppliers.store'), ['code' => 'SUP-10', 'name' => 'padma  oil MILLS', 'phone' => '01822000000'])
        ->assertSessionHasErrors('_duplicate');

    expect(Supplier::query()->where('code', 'SUP-10')->exists())->toBeFalse();
});

it('asks before saving an employee with the same National ID, without repeating the number', function () {
    PeopleWorld::make($this->w);
    Employee::query()->first()->forceFill(['national_id' => '1990 2612 3456 789'])->save();

    $this->actingAs($this->w->owner)
        ->post(route('hrm.employees.store'), ['first_name' => 'Rakib', 'last_name' => 'Uddin', 'employment_type' => 'permanent', 'national_id' => '19902612-3456789'])
        ->assertSessionHasErrors('_duplicate');

    $summary = session('duplicateWarning')['candidates'][0]['summary'];
    expect($summary)->toContain('same National ID')->not->toContain('3456789');
});

it('asks before saving a product whose SKU differs only by case and dashes (the product popup reads it from JSON)', function () {
    $response = $this->actingAs($this->w->owner)->postJson(route('inventory.products.store'), [
        'sku' => 'honey 500', 'name' => 'Mustard Honey', 'type' => 'finished_good', 'unit_of_measure_id' => $this->s->honey->unit_of_measure_id,
    ]);

    $response->assertStatus(422)->assertJsonPath('errors._duplicate.0', 'Possible duplicate. Is this a different product?');
    $ack = $response->json('duplicate.ack_token');

    $this->actingAs($this->w->owner)->postJson(route('inventory.products.store'), [
        'sku' => 'honey 500', 'name' => 'Mustard Honey', 'type' => 'finished_good', 'unit_of_measure_id' => $this->s->honey->unit_of_measure_id, 'duplicate_ack' => $ack,
    ])->assertCreated();

    expect(Product::query()->where('name', 'Mustard Honey')->exists())->toBeTrue();
});

it('the phone book quick add asks before saving the same number twice', function () {
    $this->actingAs($this->w->owner)->post(route('crm.contacts.store'), ['name' => 'Salma', 'phone' => '01555123456'])->assertSessionHasNoErrors();

    $this->actingAs($this->w->owner)
        ->post(route('crm.contacts.store'), ['name' => 'Salma Begum', 'phone' => '+8801555123456'])
        ->assertSessionHasErrors('_duplicate');

    expect(Contact::query()->count())->toBe(1);
});

it('the receipt taken from the invoice page asks like the Receive payment form', function () {
    $invoice = $this->s->invoice($this->s->challan($this->s->order('10', '850')));
    $this->s->receive($invoice, '1000', '2026-10-14');
    $form = ['money_account_id' => $this->w->bank->id, 'amount' => '1000', 'received_on' => '2026-10-15'];

    $this->actingAs($this->w->owner)->post(route('sales.invoices.receipts.store', $invoice), $form)->assertSessionHasErrors('_duplicate');
    expect(CustomerReceipt::query()->count())->toBe(1);

    $ack = session('duplicateWarning')['ack_token'];
    $this->actingAs($this->w->owner)->post(route('sales.invoices.receipts.store', $invoice), $form + ['duplicate_ack' => $ack])->assertSessionHasNoErrors();
    expect(CustomerReceipt::query()->count())->toBe(2);
});

it('a supplier payment Correct that now looks like another recent payment shows the warning in its preview', function () {
    $m = SupplierMoneyWorld::make($this->w);
    $billA = $m->d->bill($m->d->order([[$m->d->salt, '50', '20.00']]), null, 'INV-A');
    $billB = $m->d->bill($m->d->order([[$m->d->sugar, '40', '10.00']]), null, 'INV-B');
    $payment = $m->d->pay($billA, '1000');
    $m->d->pay($billB, '300');

    $preview = $m->preview($m->input($payment, DocumentAction::Correct, $m->paymentForm($payment, [
        'allocations' => [['supplier_bill_id' => $billA->id, 'amount' => '300']],
    ])), $this->w->financeB);

    expect($preview->duplicate)->not->toBeNull()
        ->and($preview->duplicate->candidates)->toHaveCount(1);

    // The payment's own original never counts against its correction.
    $same = $m->preview($m->input($payment, DocumentAction::Correct, $m->paymentForm($payment, ['paid_on' => '2026-10-15'])), $this->w->financeB);
    expect($same->duplicate)->toBeNull();
});
