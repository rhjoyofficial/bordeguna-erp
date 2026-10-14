<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\database\seeders\AccountingPermissionsSeeder;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Counterparty;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Accounting > Counterparties (loans plan v2, phase 1): who may read and who
 * may change, the list with balances per loan head, the page, the statement
 * and the balance confirmation letter.
 *
 *   accounting.counterparties.view    Owner, Finance, Manager
 *   accounting.counterparties.manage  Owner, Finance
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-14 10:00:00');

    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna', 'fiscal_year_start_month' => 7, 'address' => 'Dhaka']);
    $this->laladia = Business::create(['name' => 'LaLaDia', 'slug' => 'laladia', 'fiscal_year_start_month' => 7]);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Finance', 'Manager', 'Payroll'] as $name) {
        Role::firstOrCreate(['name' => $name, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }

    $this->seed(AccountingPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    app(BusinessContext::class)->set($this->business->id);

    $this->as = function (string $role): User {
        $user = User::factory()->create(['name' => $role]);
        $user->businesses()->attach($this->business->id);
        $user->assignRole(Role::where('name', $role)->where('business_id', $this->business->id)->firstOrFail());

        return $user;
    };

    $this->owner = ($this->as)('Owner');
    $this->finance = ($this->as)('Finance');
    $this->manager = ($this->as)('Manager');
    $this->payroll = ($this->as)('Payroll');

    $this->brac = Counterparty::create(['legal_name' => 'BRAC Bank PLC', 'kind' => 'bank', 'tin' => '111122223333', 'phone' => '01711000000']);
    $this->tycoon = Counterparty::create(['legal_name' => 'Tycoon - Hi-Tech Park', 'kind' => 'company', 'related_party' => true, 'relationship' => "The owner's company", 'address' => 'Gazipur']);

    $this->post = fn (string $date, string $slug, Counterparty $party, string $amount, bool $debit) => app(PostJournal::class)->handle(new JournalDraft(
        date: Carbon::parse($date),
        lines: $debit
            ? [JournalLineDraft::debit(Account::bySlug($slug)->id, Money::ofTaka($amount), null, $party), JournalLineDraft::credit(Account::where('code', '1180')->sole()->id, Money::ofTaka($amount))]
            : [JournalLineDraft::credit(Account::bySlug($slug)->id, Money::ofTaka($amount), null, $party), JournalLineDraft::debit(Account::where('code', '1180')->sole()->id, Money::ofTaka($amount))],
    ), $this->owner);

    $this->payload = fn (array $overrides = []) => $overrides + ['legal_name' => 'Padma Finance PLC', 'kind' => 'nbfi', 'phone' => '01811000000'];
});

afterEach(fn () => Carbon::setTestNow());

it('lets Owner, Finance and Manager read the counterparties, and nobody else', function () {
    foreach ([$this->owner, $this->finance, $this->manager] as $reader) {
        $this->actingAs($reader)->get('/accounting/counterparties')->assertOk();
        $this->actingAs($reader)->get("/accounting/counterparties/{$this->brac->id}")->assertOk();
        $this->actingAs($reader)->get("/accounting/counterparties/{$this->brac->id}/statement")->assertOk();
        $this->actingAs($reader)->get("/accounting/counterparties/{$this->brac->id}/confirmation")->assertOk();
    }

    $this->actingAs($this->payroll)->get('/accounting/counterparties')->assertForbidden();
    $this->actingAs($this->payroll)->get("/accounting/counterparties/{$this->brac->id}/statement")->assertForbidden();
});

it('lets Owner and Finance add, change and switch off a counterparty, and not the Manager', function () {
    $this->actingAs($this->manager)->postJson('/accounting/counterparties', ($this->payload)())->assertForbidden();
    $this->actingAs($this->manager)->putJson("/accounting/counterparties/{$this->brac->id}", ['legal_name' => 'X', 'kind' => 'bank'])->assertForbidden();
    $this->actingAs($this->manager)->post("/accounting/counterparties/{$this->brac->id}/deactivate")->assertForbidden();

    $created = $this->actingAs($this->finance)->postJson('/accounting/counterparties', ($this->payload)())
        ->assertCreated()
        ->assertJsonPath('label', 'Padma Finance PLC')
        ->assertJsonPath('related', false)
        ->json();

    $padma = Counterparty::findOrFail($created['id']);
    expect($padma->created_by)->toBe($this->finance->id);

    $this->actingAs($this->owner)->putJson("/accounting/counterparties/{$padma->id}", ($this->payload)(['legal_name' => 'Padma Finance PLC', 'notes' => 'Term loans']))
        ->assertOk()
        ->assertJsonPath('id', $padma->id);

    expect($padma->fresh()->notes)->toBe('Term loans')
        ->and($padma->fresh()->updated_by)->toBe($this->owner->id)
        ->and(Activity::query()->where('subject_type', Counterparty::class)->where('subject_id', $padma->id)->count())->toBe(2);
});

it('answers a duplicate or one of our own businesses with the sentence, as JSON for the dialog', function () {
    $this->actingAs($this->owner)->postJson('/accounting/counterparties', ($this->payload)(['legal_name' => 'Somebody', 'tin' => '1111-2222-3333']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tin' => 'TIN 1111-2222-3333 already belongs to BRAC Bank PLC']);

    $this->actingAs($this->owner)->postJson('/accounting/counterparties', ($this->payload)(['legal_name' => 'LaLaDia']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['legal_name' => 'Inter-business transfers']);
});

it('creates a related party inline from the voucher picker, ready for 11752', function () {
    // The owner's case: the quick dialog carries the kind, the related switch
    // and how the party is related, and hands back what the picker needs.
    $this->actingAs($this->owner)->postJson('/accounting/counterparties', [
        'legal_name' => 'Joy Holdings', 'kind' => 'company', 'related_party' => true, 'relationship' => 'The owner holds 100%',
    ])->assertCreated()->assertJsonPath('related', true)->assertJsonPath('label', 'Joy Holdings');

    $this->actingAs($this->owner)->get('/accounting/vouchers/payment/create')
        ->assertInertia(fn (Assert $page) => $page->where('counterparties', fn ($rows) => collect($rows)->contains(fn ($row) => $row['label'] === 'Joy Holdings' && $row['related'] === true)));
});

it('switches a counterparty off only once it holds no balance, and back on', function () {
    ($this->post)('2026-09-01', 'bank_loans', $this->brac, '500000', false);

    $this->actingAs($this->owner)->from("/accounting/counterparties/{$this->brac->id}")
        ->post("/accounting/counterparties/{$this->brac->id}/deactivate")
        ->assertSessionHasErrors(['counterparty' => 'BRAC Bank PLC still has balances: 2210 - Bank Loans 500,000.00 Cr (Bor de Guna). Settle or move them before deactivating.']);

    expect($this->brac->fresh()->is_active)->toBeTrue();

    $padma = Counterparty::create(['legal_name' => 'Padma Finance PLC', 'kind' => 'nbfi']);
    $this->actingAs($this->finance)->post("/accounting/counterparties/{$padma->id}/deactivate")->assertRedirect()->assertSessionHasNoErrors();
    expect($padma->fresh()->is_active)->toBeFalse();

    $this->actingAs($this->finance)->post("/accounting/counterparties/{$padma->id}/activate")->assertRedirect();
    expect($padma->fresh()->is_active)->toBeTrue();
});

it('lists every counterparty with its balances per loan head, and each head totalled against the ledger', function () {
    ($this->post)('2026-09-01', 'bank_loans', $this->brac, '500000', false);
    ($this->post)('2026-10-05', 'interest_payable', $this->brac, '4200', false);
    ($this->post)('2026-08-25', 'loans_to_related_parties', $this->tycoon, '300000', true);

    $this->actingAs($this->manager)->get('/accounting/counterparties')
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounting/counterparties/index')
            ->has('counterparties', 2)
            ->where('counterparties.0.legal_name', 'BRAC Bank PLC')
            ->where('counterparties.0.kind', 'bank')
            ->where('counterparties.0.balances', [
                ['slug' => 'interest_payable', 'label' => '2127 - Interest Payable', 'amount' => '4,200.00', 'side' => 'credit'],
                ['slug' => 'bank_loans', 'label' => '2210 - Bank Loans', 'amount' => '500,000.00', 'side' => 'credit'],
            ])
            ->where('counterparties.0.we_owe', '504,200.00')
            ->where('counterparties.0.owed_to_us', '0.00')
            ->where('counterparties.1.legal_name', 'Tycoon - Hi-Tech Park')
            ->where('counterparties.1.related_party', true)
            ->where('counterparties.1.owed_to_us', '300,000.00')
            ->where('heads', fn ($heads) => collect($heads)->every(fn ($head) => $head['agrees'] === true)
                && collect($heads)->firstWhere('slug', 'bank_loans')['counterparties_total'] === '500,000.00'
                && collect($heads)->firstWhere('slug', 'bank_loans')['ledger_total'] === '500,000.00')
            ->where('canManage', false));

    $this->actingAs($this->owner)->get('/accounting/counterparties?search=tycoon')
        ->assertInertia(fn (Assert $page) => $page->has('counterparties', 1)->where('counterparties.0.legal_name', 'Tycoon - Hi-Tech Park'));

    $this->actingAs($this->owner)->get('/accounting/counterparties?search=111122223333')
        ->assertInertia(fn (Assert $page) => $page->has('counterparties', 1)->where('counterparties.0.legal_name', 'BRAC Bank PLC'));
});

it('shows one counterparty with its details, links and balances', function () {
    $employee = Employee::create(['employee_code' => 'BDG-000009', 'first_name' => 'Karim', 'last_name' => 'Mia', 'employment_type' => 'permanent', 'status' => 'active']);
    $karim = Counterparty::create(['legal_name' => 'Karim Mia', 'kind' => 'individual', 'employee_id' => $employee->id]);
    ($this->post)('2026-09-01', 'loans_to_other_parties', $karim, '15000', true);

    $this->actingAs($this->owner)->get("/accounting/counterparties/{$karim->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounting/counterparties/show')
            ->where('counterparty.legal_name', 'Karim Mia')
            ->where('counterparty.employee.label', 'Karim Mia (BDG-000009)')
            ->where('balances', [['slug' => 'loans_to_other_parties', 'label' => '11751 - Loans to Other Parties', 'amount' => '15,000.00', 'side' => 'debit']])
            ->where('totals.owed_to_us', '15,000.00')
            ->where('business', 'Bor de Guna')
            ->where('canManage', true));
});

it('prints a statement per loan head for the period', function () {
    ($this->post)('2026-09-01', 'bank_loans', $this->brac, '500000', false);
    ($this->post)('2026-10-05', 'bank_loans', $this->brac, '50000', true);

    $this->actingAs($this->manager)->get("/accounting/counterparties/{$this->brac->id}/statement?from=2026-10-01&to=2026-10-31")
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounting/counterparties/statement')
            ->where('counterparty.legal_name', 'BRAC Bank PLC')
            ->has('heads', 1)
            ->where('heads.0.account', '2210 - Bank Loans')
            ->where('heads.0.opening', '500,000.00')
            ->where('heads.0.opening_side', 'credit')
            ->where('heads.0.closing', '450,000.00')
            ->where('heads.0.lines.0.debit', '50,000.00')
            ->where('issuer.name', 'Bor de Guna'));
});

it('writes the balance confirmation letter as at a date, saying who owes whom', function () {
    ($this->post)('2026-08-25', 'loans_to_related_parties', $this->tycoon, '300000', true);
    ($this->post)('2026-08-27', 'loans_to_related_parties', $this->tycoon, '300000', false);
    ($this->post)('2026-09-02', 'interest_receivable', $this->tycoon, '1000', true);

    $this->actingAs($this->owner)->get("/accounting/counterparties/{$this->tycoon->id}/confirmation?as_at=2026-08-26")
        ->assertInertia(fn (Assert $page) => $page
            ->component('accounting/counterparties/confirmation')
            ->where('as_at', '2026-08-26')
            ->where('counterparty.legal_name', 'Tycoon - Hi-Tech Park')
            ->where('counterparty.address', 'Gazipur')
            ->where('balances', [['label' => '11752 - Loans to Related Parties', 'amount' => '300,000.00', 'owed_by' => 'counterparty']])
            ->where('issuer.name', 'Bor de Guna'));

    $this->actingAs($this->owner)->get("/accounting/counterparties/{$this->tycoon->id}/confirmation?as_at=2026-10-14")
        ->assertInertia(fn (Assert $page) => $page->where('balances', [['label' => '11753 - Interest Receivable', 'amount' => '1,000.00', 'owed_by' => 'counterparty']]));
});

it('shows the menu link to the roles that can open it', function () {
    $this->actingAs($this->manager)->get('/accounting/counterparties')
        ->assertInertia(fn (Assert $page) => $page->where('canViewCounterparties', true)->where('canManageCounterparties', false));
    $this->actingAs($this->finance)->get('/accounting/counterparties')
        ->assertInertia(fn (Assert $page) => $page->where('canViewCounterparties', true)->where('canManageCounterparties', true));
    $this->actingAs($this->payroll)->get('/accounting/journals')
        ->assertInertia(fn (Assert $page) => $page->where('canViewCounterparties', false));

    $sidebar = file_get_contents(resource_path('js/components/app-sidebar.tsx'));
    expect($sidebar)->toContain("url: '/accounting/counterparties'")
        ->and($sidebar)->toContain('canViewCounterparties');
});

it('grants the two permissions to the existing roles by migration, once', function () {
    foreach (['accounting.counterparties.view', 'accounting.counterparties.manage'] as $name) {
        $id = DB::table('permissions')->where('name', $name)->value('id');
        DB::table('role_has_permissions')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
    }

    $migration = require base_path('app/Modules/Accounting/database/migrations/2026_10_14_990300_counterparty_permissions_are_granted_to_existing_roles.php');
    $migration->up();
    $migration->up();

    $granted = fn (string $name) => DB::table('role_has_permissions')
        ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('permissions.name', $name)
        ->where('roles.business_id', $this->business->id)
        ->pluck('roles.name')->sort()->values()->all();

    expect($granted('accounting.counterparties.view'))->toBe(['Finance', 'Manager', 'Owner'])
        ->and($granted('accounting.counterparties.manage'))->toBe(['Finance', 'Owner'])
        ->and(DB::table('permissions')->where('name', 'accounting.counterparties.view')->count())->toBe(1);
});
