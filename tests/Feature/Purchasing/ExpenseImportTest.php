<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Purchasing\Actions\ApplyExpenseImport;
use App\Modules\Purchasing\Actions\PlanExpenseImport;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Support\ExpenseImportFile;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Joy, 1 September 2026: "also import system."
 *
 * ⚠⚠ THE DANGEROUS IMPORTER. Every other importer in this system writes master
 * data - a product priced wrongly is corrected, a supplier typed twice is
 * merged. This one MOVES MONEY: an expense voucher is paid the moment it is
 * recorded, so importing the same file twice does not create a duplicate to
 * tidy up, it records a payment that never happened, on a month already
 * reconciled. Almost every test here is about that one sentence.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-01 10:00:00');

    $this->business = Business::create(['name' => 'Import Co', 'slug' => 'import-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);

    foreach (['purchasing.reports.view', 'purchasing.expense.record'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['purchasing.reports.view', 'purchasing.expense.record']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);
    $this->user->assignRole('Owner');

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);
    TenantScope::enter($this->business->id);

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'BRAC Bank',
        'type' => MoneyAccountType::Bank,
        'account_id' => Account::bySlug('cash_in_hand')->id,
    ]);

    Supplier::create([
        'business_id' => $this->business->id,
        'code' => 'NIKOLA', 'name' => 'Nikola Host',
    ]);

    $this->hosting = Account::where('code', '6225')->first() ?? Account::where('code', '6051')->first();

    // A file, in memory, read the way the importer reads one.
    $this->plan = function (string $csv) {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        try {
            $read = ExpenseImportFile::readHandle($handle);
        } finally {
            fclose($handle);
        }

        return app(PlanExpenseImport::class)->handle($read['rows'], $read['ignored']);
    };

    $this->header = "date,payee,account_code,description,amount,paid_from,reference\n";
});

it('records one expense per row and posts each one', function () {
    $plan = ($this->plan)($this->header
        ."2026-08-31,Nikola Host,{$this->hosting->code},Shared hosting August,4900,BRAC Bank,NIKOLA-2026-08\n"
        ."2026-08-15,Someone Else,{$this->hosting->code},A one-off,1500,BRAC Bank,MISC-1\n");

    expect($plan->errors)->toBeEmpty()
        ->and($plan->vouchers)->toHaveCount(2)
        ->and($plan->total())->toBe(6400.0);

    $numbers = app(ApplyExpenseImport::class)->handle($plan, $this->user);

    expect($numbers)->toHaveCount(2)
        ->and(ExpenseVoucher::count())->toBe(2)
        // ⚠ Posted, not merely stored. The importer goes through the same
        // action the screen does, so the ledger cannot disagree with it.
        ->and(Journal::count())->toBe(2);

    $first = ExpenseVoucher::where('reference', 'NIKOLA-2026-08')->first();

    expect($first->supplier?->name)->toBe('Nikola Host')
        ->and($first->paid_to)->toBeNull()
        ->and((float) $first->total)->toBe(4900.0);
});

it('refuses a reference that has already been paid', function () {
    // ⛔⛔ THE WHOLE POINT. Re-importing last month's file to be safe must not
    // pay the hosting bill a second time.
    $plan = ($this->plan)($this->header
        ."2026-08-31,Nikola Host,{$this->hosting->code},Shared hosting August,4900,BRAC Bank,NIKOLA-2026-08\n");

    app(ApplyExpenseImport::class)->handle($plan, $this->user);

    $again = ($this->plan)($this->header
        ."2026-08-31,Nikola Host,{$this->hosting->code},Shared hosting August,4900,BRAC Bank,NIKOLA-2026-08\n");

    expect($again->hasErrors())->toBeTrue()
        ->and($again->errors[0]['message'])->toContain('already on voucher')
        ->and($again->vouchers)->toBeEmpty()
        ->and(ExpenseVoucher::count())->toBe(1);
});

it('refuses the same reference twice inside one file', function () {
    // ⚠ A per-row database check cannot see the row two lines above it.
    $plan = ($this->plan)($this->header
        ."2026-08-31,Nikola Host,{$this->hosting->code},Hosting,4900,BRAC Bank,NIKOLA-2026-08\n"
        ."2026-08-31,Nikola Host,{$this->hosting->code},Hosting again,4900,BRAC Bank,nikola-2026-08\n");

    expect($plan->hasErrors())->toBeTrue()
        ->and($plan->errors[0]['message'])->toContain('line 2');
});

it('reads a date the same way whoever wrote the file meant it', function () {
    // ⚠⚠ 03/08/2026 is 3 August, and a parser that read it as 8 March would
    // put the payment in the wrong month with nothing on screen looking wrong.
    $plan = ($this->plan)($this->header
        ."03/08/2026,Nikola Host,{$this->hosting->code},Hosting,4900,BRAC Bank,X-1\n");

    expect($plan->errors)->toBeEmpty()
        ->and($plan->vouchers[0]['date'])->toBe('2026-08-03');
});

it('refuses a row it cannot classify rather than guessing', function () {
    $plan = ($this->plan)($this->header
        ."2026-08-31,Nikola Host,9999,Hosting,4900,BRAC Bank,X-1\n"
        ."2026-08-31,Nikola Host,{$this->hosting->code},Hosting,4900,Some Other Bank,X-2\n"
        ."2026-08-31,Nikola Host,{$this->hosting->code},Hosting,nonsense,BRAC Bank,X-3\n"
        ."2027-01-01,Nikola Host,{$this->hosting->code},Hosting,4900,BRAC Bank,X-4\n");

    expect($plan->errors)->toHaveCount(4)
        ->and($plan->vouchers)->toBeEmpty()
        ->and($plan->errors[0]['message'])->toContain('no expense account')
        ->and($plan->errors[1]['message'])->toContain('to pay from')
        ->and($plan->errors[2]['message'])->toContain('amount')
        ->and($plan->errors[3]['message'])->toContain('future');
});

it('warns rather than refuses when the payee is not a supplier', function () {
    // A rickshaw fare has no supplier record and never will. The warning exists
    // so a MISSPELT supplier does not quietly become a typed name.
    $plan = ($this->plan)($this->header
        ."2026-08-31,Nikola Hostt,{$this->hosting->code},Hosting,4900,BRAC Bank,X-1\n");

    expect($plan->errors)->toBeEmpty()
        ->and($plan->warnings)->toHaveCount(1)
        ->and($plan->warnings[0]['message'])->toContain('not a supplier on file')
        ->and($plan->vouchers[0]['supplier_id'])->toBeNull();

    app(ApplyExpenseImport::class)->handle($plan, $this->user);

    expect(ExpenseVoucher::first()->paid_to)->toBe('Nikola Hostt');
});

it('warns about a row with no reference, because nothing can protect it later', function () {
    $plan = ($this->plan)($this->header
        ."2026-08-31,Nikola Host,{$this->hosting->code},Hosting,4900,BRAC Bank,\n");

    expect($plan->errors)->toBeEmpty()
        ->and($plan->warnings[0]['message'])->toContain('cannot tell it apart');
});

it('applies the whole file or none of it', function () {
    // ⚠⚠ Half an import is worse than none: nobody can say which rows still
    // need paying, and re-importing pays the applied ones twice.
    $plan = ($this->plan)($this->header
        ."2026-08-31,Nikola Host,{$this->hosting->code},Hosting,4900,BRAC Bank,X-1\n"
        ."2026-08-31,Nikola Host,9999,Broken,4900,BRAC Bank,X-2\n");

    expect($plan->hasErrors())->toBeTrue();

    expect(fn () => app(ApplyExpenseImport::class)->handle($plan, $this->user))
        ->toThrow(RuntimeException::class);

    expect(ExpenseVoucher::count())->toBe(0)
        ->and(Journal::count())->toBe(0);
});

it('refuses a file whose header it cannot understand', function () {
    expect(fn () => ($this->plan)("date,payee,amount\n2026-08-31,Nikola Host,4900\n"))
        ->toThrow(RuntimeException::class, 'account_code');
});

it('is reachable from the expenses screen, not just by URL', function () {
    // ⚠⚠ Nine engines in this system have shipped complete and unreachable.
    expect(file_get_contents(resource_path('js/pages/purchasing/expenses/index.tsx')))
        ->toContain("route('purchasing.expenses.import')");

    $this->actingAs($this->user)->withSession(['current_business_id' => $this->business->id])
        ->get('/purchasing/expenses/import')
        ->assertOk();
});

it('hands out a template the importer can read back', function () {
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, ExpenseImportFile::template());
    rewind($handle);
    $read = ExpenseImportFile::readHandle($handle);
    fclose($handle);

    expect($read['ignored'])->toBeEmpty()
        ->and($read['rows'])->toHaveCount(1)
        ->and($read['rows'][0]['values']['reference'])->toBe('NIKOLA-2026-09');
});
