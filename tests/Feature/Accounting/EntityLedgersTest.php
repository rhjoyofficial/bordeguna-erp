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
 * its control account (Joy, 27 Sep 2026). The head is what a person sees and
 * picks; money still rolls up through the control account (Interpretation B).
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Heads Co', 'slug' => 'heads-co']);
    app(BusinessContext::class)->set($this->business->id);
    (new ChartOfAccountsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
});

it('gives a customer a head under Accounts Receivable, uniquely keyed', function () {
    $customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Rahim', 'code' => 'RAHIM']);

    $head = EntityLedgers::ensureOwnLedger($customer);

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

    expect($customer->account_id)->toBeNull();

    Artisan::call('accounting:backfill-entity-ledgers');

    expect($customer->fresh()->account_id)->not->toBeNull()
        ->and($supplier->fresh()->account_id)->not->toBeNull()
        ->and($employee->fresh()->account_id)->not->toBeNull();
});
