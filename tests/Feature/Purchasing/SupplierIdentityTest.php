<?php

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Database\QueryException;

/**
 * What Purchasing and Accounting need to know about a supplier.
 *
 * ⚠ Added before onboarding, not after: every one of these is collected while
 * somebody is already on the phone to the supplier. Retrofitting a tax number
 * means ringing them all back.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Supplier Co', 'slug' => 'supplier-identity-co']);
    app(BusinessContext::class)->set($this->business->id);
});

it('stores the identity, tax and payment details a purchase needs', function () {
    $supplier = Supplier::create([
        'business_id' => $this->business->id,
        'code' => 'ACME',
        'name' => 'Acme Traders',
        'payment_terms' => 'Net 30',
        'bin' => '004123456-0101',
        'tin' => '123456789012',
        'bank_name' => 'BRAC Bank',
        'bank_branch' => 'Mohammadpur',
        'bank_account_name' => 'Acme Trading Company',
        'bank_account_number' => '1501203456789',
        'mfs_number' => '01711000000',
        'notes' => 'Delivers Tuesdays only. Ask for Rahim at the gate.',
    ])->fresh();

    expect($supplier->code)->toBe('ACME')
        ->and($supplier->bin)->toBe('004123456-0101')
        ->and($supplier->tin)->toBe('123456789012')
        // ⚠ The account name is often NOT the trading name. Paying to the wrong
        // one is how a transfer bounces a week later.
        ->and($supplier->bank_account_name)->toBe('Acme Trading Company')
        ->and($supplier->bank_account_number)->toBe('1501203456789')
        ->and($supplier->mfs_number)->toBe('01711000000')
        ->and($supplier->notes)->toContain('Tuesdays');
});

// ⚠ A supplier is real before anybody knows their BIN.
it('allows a supplier with only a code and a name', function () {
    $supplier = Supplier::create([
        'business_id' => $this->business->id,
        'code' => 'MINIMAL',
        'name' => 'Corner Shop',
    ])->fresh();

    expect($supplier->exists)->toBeTrue()
        ->and($supplier->bin)->toBeNull()
        ->and($supplier->is_active)->toBeTrue();
});

// ⚠⚠ THE IMPORT'S IDEMPOTENCY KEY. The only other candidate was `name`, and
// names get corrected - after which a re-run would create a SECOND supplier
// rather than update the first.
it('refuses the same supplier code twice in one business', function () {
    Supplier::create(['business_id' => $this->business->id, 'code' => 'ACME', 'name' => 'First']);
    Supplier::create(['business_id' => $this->business->id, 'code' => 'ACME', 'name' => 'Second']);
})->throws(QueryException::class);

it('allows the same code in a different business', function () {
    Supplier::create(['business_id' => $this->business->id, 'code' => 'ACME', 'name' => 'Ours']);

    $other = Business::create(['name' => 'Rival Co', 'slug' => 'rival-supplier-co']);
    app(BusinessContext::class)->set($other->id);

    expect(Supplier::create(['business_id' => $other->id, 'code' => 'ACME', 'name' => 'Theirs'])->exists)
        ->toBeTrue();
});

// ⚠ Nullable and unique when present, the same shape customers.code uses.
it('allows many suppliers with no code', function () {
    Supplier::create(['business_id' => $this->business->id, 'name' => 'One']);
    Supplier::create(['business_id' => $this->business->id, 'name' => 'Two']);

    expect(Supplier::whereNull('code')->count())->toBe(2);
});

// ⚠⚠ The point of a permanent code: correcting a trading name must not create a
// second supplier when the import runs again.
it('keeps the code stable when the trading name is corrected', function () {
    $supplier = Supplier::create([
        'business_id' => $this->business->id, 'code' => 'ACME', 'name' => 'Acme Traders',
    ]);

    $supplier->update(['name' => 'Acme Trading Co']);

    expect(Supplier::where('code', 'ACME')->count())->toBe(1)
        ->and($supplier->fresh()->name)->toBe('Acme Trading Co');
});
