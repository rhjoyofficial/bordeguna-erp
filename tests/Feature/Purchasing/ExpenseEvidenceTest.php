<?php

use App\Models\User;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The receipt behind the money, and the voucher on paper.
 *
 * ⚠⚠ THE GAP THESE CLOSE. An expense voucher IS money leaving the business, and
 * it was the one money document in this ERP with nowhere to put the proof and no
 * way to print itself. Supplier bills, disbursement batches, adjustments and
 * employee records could all carry a file; rent, electricity, hosting and every
 * small cash payment could not. A voucher with no receipt is a number somebody
 * typed.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->business = Business::create(['name' => 'Expense Co', 'slug' => 'expense-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['purchasing.expense.record', 'purchasing.reports.view'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    Role::firstOrCreate(['name' => 'Finance', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['purchasing.expense.record', 'purchasing.reports.view']);
    Role::firstOrCreate(['name' => 'Viewer', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('purchasing.reports.view');
    Role::firstOrCreate(['name' => 'Outsider', 'business_id' => $this->business->id, 'guard_name' => 'web']);

    $this->finance = User::factory()->create();
    $this->finance->businesses()->attach($this->business->id);
    $this->finance->assignRole('Finance');

    $this->viewer = User::factory()->create();
    $this->viewer->businesses()->attach($this->business->id);
    $this->viewer->assignRole('Viewer');

    $this->outsider = User::factory()->create();
    $this->outsider->businesses()->attach($this->business->id);
    $this->outsider->assignRole('Outsider');

    $ledger = Account::create([
        'business_id' => $this->business->id,
        'code' => '1010',
        'name' => 'Cash',
        'type' => AccountType::Asset,
        'is_postable' => true,
        'is_active' => true,
    ]);

    $this->money = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'Petty cash',
        'type' => 'cash',
        'account_id' => $ledger->id,
        'is_active' => true,
    ]);

    $this->voucher = ExpenseVoucher::create([
        'business_id' => $this->business->id,
        'voucher_number' => 'EV-0001',
        'spent_on' => '2026-08-27',
        'money_account_id' => $this->money->id,
        'paid_to' => 'Hostinger',
        'total' => '1200.0000',
        'reference' => 'INV-99',
        'created_by' => $this->finance->id,
    ]);
});

it('attaches a receipt to a voucher', function () {
    $this->actingAs($this->finance)
        ->post("/purchasing/expenses/{$this->voucher->id}/evidence", [
            'file' => UploadedFile::fake()->create('hostinger-invoice.pdf', 40, 'application/pdf'),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->voucher->fresh()->attachments)->toHaveCount(1);
});

it('keeps more than one receipt, because a payment often has two documents', function () {
    // ⚠ A month of hosting is an invoice AND a card statement line. Singleton
    // behaviour here would silently delete the first when the second arrived.
    foreach (['invoice.pdf', 'statement.pdf'] as $name) {
        $this->actingAs($this->finance)
            ->post("/purchasing/expenses/{$this->voucher->id}/evidence", [
                'file' => UploadedFile::fake()->create($name, 20, 'application/pdf'),
            ])
            ->assertRedirect();
    }

    expect($this->voucher->fresh()->attachments)->toHaveCount(2);
});

it('refuses a file type that is not a receipt', function () {
    $this->actingAs($this->finance)
        ->post("/purchasing/expenses/{$this->voucher->id}/evidence", [
            'file' => UploadedFile::fake()->create('payload.exe', 10),
        ])
        ->assertSessionHasErrors('file');

    expect($this->voucher->fresh()->attachments)->toHaveCount(0);
});

it('refuses a receipt from somebody who may only look at expenses', function () {
    $this->actingAs($this->viewer)
        ->post("/purchasing/expenses/{$this->voucher->id}/evidence", [
            'file' => UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
        ])
        ->assertForbidden();
});

it('will not remove a receipt belonging to a different voucher', function () {
    // ⚠⚠ The id in the URL is not proof of ownership. Without this check, the
    // right permission on ANY voucher would delete the evidence off another.
    $other = ExpenseVoucher::create([
        'business_id' => $this->business->id,
        'voucher_number' => 'EV-0002',
        'spent_on' => '2026-08-27',
        'money_account_id' => $this->money->id,
        'paid_to' => 'WiFi',
        'total' => '500.0000',
        'created_by' => $this->finance->id,
    ]);

    $this->actingAs($this->finance)
        ->post("/purchasing/expenses/{$this->voucher->id}/evidence", [
            'file' => UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
        ]);

    $attachment = $this->voucher->fresh()->attachments->first();

    $this->actingAs($this->finance)
        ->delete("/purchasing/expenses/{$other->id}/evidence/{$attachment->id}")
        ->assertNotFound();

    expect($this->voucher->fresh()->attachments)->toHaveCount(1);
});

it('prints the voucher for anyone who may see expenses', function () {
    $this->actingAs($this->viewer)
        ->get("/purchasing/expenses/{$this->voucher->id}/print")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('purchasing/expenses/print')
            ->where('voucher.voucher_number', 'EV-0001')
            ->where('voucher.payee', 'Hostinger'));
});

it('refuses the printed voucher to somebody with no expense access at all', function () {
    $this->actingAs($this->outsider)
        ->get("/purchasing/expenses/{$this->voucher->id}/print")
        ->assertForbidden();
});

it('shows the receipts on the expense list, so a payment with none is visible', function () {
    $this->actingAs($this->finance)
        ->post("/purchasing/expenses/{$this->voucher->id}/evidence", [
            'file' => UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
        ]);

    $this->actingAs($this->viewer)
        ->get('/purchasing/expenses')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('vouchers.data.0.evidence', 1));
});
