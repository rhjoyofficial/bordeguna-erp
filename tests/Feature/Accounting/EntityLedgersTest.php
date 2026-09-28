<?php

use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\EntityLedgers;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Facades\Artisan;

/**
 * Every customer, supplier and employee gets its own named ledger head under
 * its control account (Joy, 27 Sep 2026). ⚠ The head is a NON-POSTABLE display
 * grouping (revised 28 Sep 2026): money posts to the control account with the
 * entity as party, never to the head, so it cannot bypass the party subledger.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Heads Co', 'slug' => 'heads-co']);
    app(BusinessContext::class)->set($this->business->id);
    (new ChartOfAccountsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
});

it('gives a customer a head under Accounts Receivable, uniquely keyed', function () {
    $customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Rahim', 'code' => 'RAHIM']);

    // The observer gives it a head the moment it is created.
    expect($customer->fresh()->account_id)->not->toBeNull();

    $head = EntityLedgers::ensureOwnLedger($customer->fresh());

    expect($head)->not->toBeNull()
        ->and($customer->fresh()->account_id)->toBe($head->id)
        ->and($head->parent_id)->toBe(Account::bySlug('accounts_receivable')->id)
        ->and($head->name)->toBe('Rahim - Heads Co')
        ->and($head->code)->toBe('1140-'.$customer->id)
        ->and($head->type->value)->toBe('asset');

    // Two customers with the SAME display name each get their own head.
    $other = Customer::create(['business_id' => $this->business->id, 'name' => 'Rahim', 'code' => 'RAHIM2']);
    $otherHead = EntityLedgers::ensureOwnLedger($other);
    expect($otherHead->id)->not->toBe($head->id)
        ->and($otherHead->code)->toBe('1140-'.$other->id);
});

it('gives a supplier a head under Accounts Payable and an employee one under Employee Advances', function () {
    $supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Padma Traders']);
    $employee = Employee::create([
        'business_id' => $this->business->id, 'employee_code' => 'BDG-000099',
        'first_name' => 'Musa', 'last_name' => 'Islam',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);

    $sHead = EntityLedgers::ensureOwnLedger($supplier);
    $eHead = EntityLedgers::ensureOwnLedger($employee);

    expect($sHead->parent_id)->toBe(Account::bySlug('accounts_payable')->id)
        ->and($sHead->type->value)->toBe('liability')
        ->and($sHead->name)->toBe('Padma Traders - Heads Co')
        ->and($eHead->parent_id)->toBe(Account::bySlug('employee_advances')->id)
        ->and($eHead->name)->toBe('Musa Islam (BDG-000099)')
        ->and($eHead->code)->toBe('1170-BDG-000099');
});

it('is idempotent and renames when the entity is renamed', function () {
    $customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Old Name', 'code' => 'ON']);
    $head = EntityLedgers::ensureOwnLedger($customer);

    // Second call makes no new head.
    expect(EntityLedgers::ensureOwnLedger($customer->fresh())->id)->toBe($head->id);

    $customer->update(['name' => 'New Name']);
    $renamed = EntityLedgers::ensureOwnLedger($customer->fresh());
    expect($renamed->id)->toBe($head->id)
        ->and($renamed->name)->toBe('New Name - Heads Co');
});

it('back-fills every existing entity that has no head', function () {
    $customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Backfill Co', 'code' => 'BF']);
    $supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Backfill Supplies']);
    $employee = Employee::create([
        'business_id' => $this->business->id, 'employee_code' => 'BDG-000100',
        'first_name' => 'Back', 'last_name' => 'Fill',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);

    // Simulate rows from before the feature: drop their auto-created heads so
    // the entity has none, exactly what a pre-feature customer looks like.
    foreach ([$customer, $supplier, $employee] as $entity) {
        $head = $entity->fresh()->account_id;
        $entity->forceFill(['account_id' => null])->saveQuietly();
        Account::whereKey($head)->delete();
    }

    expect($customer->fresh()->account_id)->toBeNull();

    Artisan::call('accounting:backfill-entity-ledgers');

    expect($customer->fresh()->account_id)->not->toBeNull()
        ->and($supplier->fresh()->account_id)->not->toBeNull()
        ->and($employee->fresh()->account_id)->not->toBeNull();
});

it('auto-creates a head when a supplier or employee is created, via the observer', function () {
    $supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Fresh Supplier']);
    $employee = Employee::create([
        'business_id' => $this->business->id, 'employee_code' => 'BDG-000200',
        'first_name' => 'New', 'last_name' => 'Hire',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);

    expect($supplier->fresh()->account_id)->not->toBeNull()
        ->and($employee->fresh()->account_id)->not->toBeNull();
});

it('makes every entity head non-postable, so nothing can land on it and bypass the subledger', function () {
    // ⚠⚠ The hole this closes: the 27 Sep feature created these heads postable on
    // the promise of a PostJournal redirect that was never built, so a posting
    // landed on the head and vanished from the customer statement, ageing,
    // employee statement and R7 reconciliation. Non-postable removes the hole.
    $customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Karim', 'code' => 'KARIM']);
    $supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Padma Traders', 'code' => 'PADMA']);
    $employee = Employee::create([
        'employee_code' => 'BDG-000077', 'first_name' => 'Musa', 'last_name' => 'Islam',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);
    EntityLedgers::ensureOwnLedger($employee);

    $headIds = [
        $customer->fresh()->account_id,
        $supplier->fresh()->account_id,
        $employee->fresh()->account_id,
    ];

    // Each head exists but is a heading, not a ledger.
    foreach ($headIds as $id) {
        expect(Account::find($id)->is_postable)->toBeFalse();
    }

    // And the pickers, which offer only postable accounts, omit every one of them.
    $postableIds = Account::query()->postable()->pluck('id')->all();
    foreach ($headIds as $id) {
        expect($postableIds)->not->toContain($id);
    }
});
