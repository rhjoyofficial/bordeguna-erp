<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * accounting:reconcile as a screen.
 *
 * ⚠⚠ The states worth testing are the CLASSIFICATION, not the arithmetic - the
 * engine's arithmetic has its own file. Zero checks must read as caution and
 * never as agreement (C-RSC-3, the 13 Aug restore-drill false green); an
 * account WITH postings that disagrees is a red break; documents with no
 * postings at all are an amber warning (C-RSC-4).
 *
 * See RECONCILIATION-SCREEN-ARCHITECTURE.md.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-16 10:00:00');

    $this->business = Business::create([
        'name' => 'Reconcile Co',
        'slug' => 'reconcile-co',
        'fiscal_year_start_month' => 7,
    ]);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::findOrCreate('accounting.view', 'web');

    $reader = Role::firstOrCreate(['name' => 'Reader', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $reader->givePermissionTo('accounting.view');

    $outsider = Role::firstOrCreate(['name' => 'Outsider', 'business_id' => $this->business->id, 'guard_name' => 'web']);

    (new ChartOfAccountsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    $this->reader = User::factory()->create();
    $this->reader->businesses()->attach($this->business->id);
    $this->reader->assignRole($reader);

    $this->outsider = User::factory()->create();
    $this->outsider->businesses()->attach($this->business->id);
    $this->outsider->assignRole($outsider);

    // ⚠ Mappings are NOT seeded here. Half the tests need the zero-mapping
    // state; the others seed them explicitly.
    $this->seedMappings = function () {
        (new SalesAccountMappingSeeder)->run();
        app(BusinessContext::class)->set($this->business->id);
    };

    $this->invoice = fn (string $total) => SalesInvoice::create([
        'business_id' => $this->business->id,
        'customer_id' => Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Rahman Traders',
        ])->id,
        'invoice_number' => 'INV-0001',
        'invoice_date' => '2026-07-05',
        'subtotal' => $total,
        'total' => $total,
        'created_by' => $this->reader->id,
    ]);

    $this->postAr = fn (string $amount, Customer $customer) => app(PostJournal::class)->handle(new JournalDraft(
        date: Carbon::parse('2026-07-05'),
        lines: [
            JournalLineDraft::debit(Account::bySlug('accounts_receivable')->id, Money::ofTaka($amount), null, $customer),
            JournalLineDraft::credit(Account::bySlug('owners_capital')->id, Money::ofTaka($amount)),
        ],
    ), $this->reader);
});

afterEach(fn () => Carbon::setTestNow());

// ─── C-RSC-3. ZERO CHECKS IS NOT AGREEMENT ──────────────────────────────────

it('says nothing was reconciled when no mappings exist, never that the books agree', function () {
    $this->actingAs($this->reader)
        ->get('/accounting/reconciliation')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('accounting/reconciliation/index')
            ->where('checks', 0)
            ->where('nothingReconciled', true)
            ->where('problems', [])
        );
});

// ─── THE CLEAN PASS ─────────────────────────────────────────────────────────

it('reports agreement when checks ran and found nothing', function () {
    ($this->seedMappings)();

    $this->actingAs($this->reader)
        ->get('/accounting/reconciliation')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('nothingReconciled', false)
            ->where('problems', [])
            ->etc()
        )
        ->assertInertia(function ($page) {
            expect($page->toArray()['props']['checks'])->toBeGreaterThan(0);
        });
});

// ─── C-RSC-4. THE CLASSIFICATION ────────────────────────────────────────────

it('shows a posted account that disagrees with its documents as a red break', function () {
    ($this->seedMappings)();

    // The document says 10,000; the ledger says 8,000. The account HAS
    // postings, so this is a problem, not a warning.
    $invoice = ($this->invoice)('10000');
    ($this->postAr)('8000', $invoice->customer);

    $this->actingAs($this->reader)
        ->get('/accounting/reconciliation')
        ->assertOk()
        ->assertInertia(function ($page) {
            $problems = $page->toArray()['props']['problems'];

            expect($problems)->not->toBeEmpty()
                ->and(implode(' ', $problems))->toContain('Accounts Receivable');
        });
});

it('shows documents with no postings at all as an amber warning, not a break', function () {
    ($this->seedMappings)();

    ($this->invoice)('10000');   // nothing ever posted to AR

    $this->actingAs($this->reader)
        ->get('/accounting/reconciliation')
        ->assertOk()
        ->assertInertia(function ($page) {
            $props = $page->toArray()['props'];

            expect($props['problems'])->toBe([])
                ->and(implode(' ', $props['warnings']))->toContain('no postings');
        });
});

// ─── REACHABILITY ───────────────────────────────────────────────────────────

it('keeps the screen away from a user who cannot view accounting', function () {
    $this->actingAs($this->outsider)->get('/accounting/reconciliation')->assertForbidden();
});
