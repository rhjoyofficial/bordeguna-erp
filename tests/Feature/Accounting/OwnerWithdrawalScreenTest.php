<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\AccountingPermissionsSeeder;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\OwnerWithdrawal;
use App\Modules\Foundation\database\seeders\FoundationPermissionsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Accounts > Vouchers > Owner withdrawals through the real HTTP layer (Phase
 * 2, 17 Oct 2026): who may open it, who may write, who may authorise and
 * post, the estimate the list shows, the reimbursement shortcut, the monthly
 * report, Settings > Company's Proprietor, the sections registry and the
 * Bangla dictionary.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-17 10:00:00');

    $this->business = Business::create(['name' => 'Screen Co', 'slug' => 'screen-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Finance', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new FoundationPermissionsSeeder)->run();
    (new AccountingPermissionsSeeder)->run();
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->owner = ($this->asRole)('Owner');
    $this->finance = ($this->asRole)('Finance');
    $this->business->update(['proprietor_user_id' => $this->owner->id]);

    $kg = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAV', 'is_default' => true]);
    $this->ghee = Product::create(['business_id' => $this->business->id, 'unit_of_measure_id' => $kg->id, 'sku' => 'GHEE', 'name' => 'Ghee', 'type' => ProductType::FinishedGood, 'mrp' => '50.0000']);
    $this->batch = StockBatch::findOrFail(app(RecordStockIn::class)->handle($this->ghee, $this->warehouse, '10', '20.00', $this->owner, batchNumber: 'A', occurredAt: '2026-10-01 09:00:00')->batch_id);

    $this->payload = fn (array $line = []) => [
        'warehouse_id' => $this->warehouse->id,
        'withdrawn_on' => '2026-10-15',
        'note' => 'For the house',
        // ⚠ `$line + [...]`: PHP's array union keeps the LEFT side, so the overrides come first.
        'lines' => [$line + ['product_id' => $this->ghee->id, 'quantity' => '4', 'note' => '']],
    ];
});

afterEach(fn () => Carbon::setTestNow());

it('opens for the Owner, Finance and Manager and is refused to Staff', function (string $role, int $status) {
    $this->actingAs(($this->asRole)($role))->get('/accounting/owner-withdrawals')->assertStatus($status);
})->with([['Owner', 200], ['Finance', 200], ['Manager', 200], ['Staff', 403]]);

it('lets the Manager read but not write, and Staff neither', function () {
    $this->actingAs(($this->asRole)('Manager'))->post('/accounting/owner-withdrawals', ($this->payload)())->assertForbidden();
    $this->actingAs(($this->asRole)('Staff'))->post('/accounting/owner-withdrawals', ($this->payload)())->assertForbidden();

    expect(OwnerWithdrawal::count())->toBe(0);
});

it('renders the list with the estimated batch cost per line, the proprietor and the flags', function () {
    $this->actingAs($this->owner)->post('/accounting/owner-withdrawals', ($this->payload)())->assertRedirect();

    $this->actingAs($this->owner)->get('/accounting/owner-withdrawals')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('accounting/owner-withdrawals/index')
            ->where('canManage', true)
            ->where('canAuthorise', false)
            ->where('isProprietor', true)
            ->where('drawingsMapped', true)
            ->where('proprietor.id', $this->owner->id)
            ->has('products', 1)
            ->has('withdrawals', 1, fn (AssertableInertia $row) => $row
                ->where('status', 'draft')
                ->where('editable', true)
                ->where('lines.0.estimate.unit_cost', '20.0000')
                ->where('lines.0.estimate.cost', '80.00')
                ->where('lines.0.estimate.short', '0.0000')
                ->where('lines.0.estimate.reference_source', 'mrp')
                ->where('lines.0.unit_cost', null)
                ->etc()));

    // Finance may authorise but is not the proprietor.
    $this->actingAs($this->finance)->get('/accounting/owner-withdrawals')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canAuthorise', true)->where('isProprietor', false)->etc());

    // The estimate endpoint the popup reads while a line is typed.
    $this->actingAs($this->owner)->getJson('/accounting/owner-withdrawals/estimate?warehouse_id='.$this->warehouse->id.'&product_id='.$this->ghee->id.'&quantity=12')
        ->assertOk()
        ->assertJsonPath('unit_cost', '20.0000')
        ->assertJsonPath('cost', '200.00')
        ->assertJsonPath('short', '2.0000')
        ->assertJsonPath('reference', '600.00');
});

it('walks the buttons: the proprietor needs a note to authorise himself, Finance authorises, the Owner posts, the ledger follows', function () {
    $this->actingAs($this->owner)->post('/accounting/owner-withdrawals', ($this->payload)())->assertRedirect();
    $w = OwnerWithdrawal::sole();

    // Posting a draft is refused; authorising without a note, by the proprietor, too.
    $this->actingAs($this->owner)->post("/accounting/owner-withdrawals/{$w->id}/post")->assertRedirect()->assertSessionHas('error');
    $this->actingAs($this->owner)->post("/accounting/owner-withdrawals/{$w->id}/authorise", ['authorisation_note' => ''])->assertRedirect()->assertSessionHasErrors('authorisation_note');
    expect($w->fresh()->status->value)->toBe('draft');

    // Staff cannot even reach the button.
    $this->actingAs(($this->asRole)('Staff'))->post("/accounting/owner-withdrawals/{$w->id}/authorise")->assertForbidden();

    $this->actingAs($this->finance)->post("/accounting/owner-withdrawals/{$w->id}/authorise", ['authorisation_note' => 'Checked'])->assertRedirect()->assertSessionHas('success');
    $w->refresh();
    expect($w->status->value)->toBe('approved')
        ->and($w->authorised_by)->toBe($this->finance->id)
        ->and(StockReservation::heldOn($this->batch->id))->toBe('4.0000');

    // Finance may post too (manage); the Manager may not.
    $this->actingAs(($this->asRole)('Manager'))->post("/accounting/owner-withdrawals/{$w->id}/post")->assertForbidden();

    $before = (int) JournalLine::where('account_id', Account::bySlug('owners_drawings')->id)->sum('amount_minor');
    $this->actingAs($this->owner)->post("/accounting/owner-withdrawals/{$w->id}/post")->assertRedirect()->assertSessionHas('success');

    $w->refresh();
    expect($w->status->value)->toBe('posted')
        ->and($w->journal_id)->not->toBeNull()
        ->and((string) $this->batch->fresh()->current_quantity)->toBe('6.0000')
        ->and((int) JournalLine::where('account_id', Account::bySlug('owners_drawings')->id)->sum('amount_minor') - $before)->toBe(8000);

    // Posted: frozen. Editing and deleting are refused.
    $this->actingAs($this->owner)->put("/accounting/owner-withdrawals/{$w->id}", ($this->payload)())->assertSessionHasErrors('lines');
    $this->actingAs($this->owner)->delete("/accounting/owner-withdrawals/{$w->id}")->assertRedirect()->assertSessionHas('error');
    expect(OwnerWithdrawal::count())->toBe(1);

    // The monthly report shows it.
    $this->actingAs($this->owner)->get('/accounting/owner-withdrawals?month=2026-10')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.month', '2026-10')
            ->has('months', 1)
            ->where('months.0.cost', '80.00')
            ->where('months.0.reference', '200.00')
            ->where('months.0.difference', '120.00')
            ->where('monthDetail.totals.cost', '80.00')
            ->has('monthDetail.rows', 1)
            ->etc());
});

it('self-authorises with a note from the button, and sends an authorised one back to draft', function () {
    $this->actingAs($this->owner)->post('/accounting/owner-withdrawals', ($this->payload)())->assertRedirect();
    $w = OwnerWithdrawal::sole();

    $this->actingAs($this->owner)->post("/accounting/owner-withdrawals/{$w->id}/authorise", ['authorisation_note' => 'Nobody else in today'])->assertRedirect()->assertSessionHas('success');
    expect($w->fresh()->self_authorised)->toBeTrue();

    $this->actingAs($this->owner)->post("/accounting/owner-withdrawals/{$w->id}/reopen")->assertRedirect()->assertSessionHas('success');
    expect($w->fresh()->status->value)->toBe('draft')
        ->and(StockReservation::query()->open()->count())->toBe(0);

    $this->actingAs($this->owner)->delete("/accounting/owner-withdrawals/{$w->id}")->assertRedirect()->assertSessionHas('success');
    expect(OwnerWithdrawal::count())->toBe(0);
});

it('opens the receipt voucher prefilled with the drawings line for a posted withdrawal', function () {
    $this->actingAs($this->owner)->post('/accounting/owner-withdrawals', ($this->payload)())->assertRedirect();
    $w = OwnerWithdrawal::sole();
    $this->actingAs($this->finance)->post("/accounting/owner-withdrawals/{$w->id}/authorise")->assertRedirect();
    $this->actingAs($this->owner)->post("/accounting/owner-withdrawals/{$w->id}/post")->assertRedirect()->assertSessionHas('success');

    $this->actingAs($this->owner)->get("/accounting/vouchers/receipt/create?purpose=owner_reimbursement&withdrawal={$w->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('accounting/vouchers/form')
            ->where('prefill.lines.0.account_id', Account::bySlug('owners_drawings')->id)
            ->where('prefill.lines.0.side', 'credit')
            ->where('prefill.lines.0.amount', '80.00')
            ->where('prefill.counterparty', $this->owner->name)
            ->etc());

    // Without the purpose, no prefill; a payment voucher never gets one.
    $this->actingAs($this->owner)->get('/accounting/vouchers/receipt/create')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('prefill', null)->etc());
    $this->actingAs($this->owner)->get('/accounting/vouchers/payment/create?purpose=owner_reimbursement')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('prefill', null)->etc());

    expect(file_get_contents(resource_path('js/pages/accounting/owner-withdrawals/index.tsx')))->toContain('purpose=owner_reimbursement');
});

it('names the proprietor under Settings > Company from the members of the business', function () {
    $this->business->update(['proprietor_user_id' => null]);
    $outsider = User::factory()->create();

    $this->actingAs($this->owner)->get('/settings/business')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('business.proprietor_user_id', null)
            ->where('proprietor_default', null)
            ->has('members', 2)
            ->etc());

    $base = [
        'name' => 'Screen Co', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'fiscal_year_start_month' => 7,
        'payroll_cycle_start_day' => 1, 'wfh_monthly_cap' => 0,
    ];

    $this->actingAs($this->owner)->patch('/settings/business', $base + ['proprietor_user_id' => $outsider->id])->assertSessionHasErrors('proprietor_user_id');
    $this->actingAs($this->owner)->patch('/settings/business', $base + ['proprietor_user_id' => $this->finance->id])->assertSessionHasNoErrors();

    expect($this->business->fresh()->proprietor_user_id)->toBe($this->finance->id);

    expect(file_get_contents(resource_path('js/pages/settings/business.tsx')))->toContain('proprietor_user_id');
});

it('is registered in the Accounts sections with its gate on one line, and speaks Bangla through the dictionary', function () {
    expect(file_get_contents(resource_path('js/components/accounting/accounts-sections.ts')))
        ->toContain("url: '/accounting/owner-withdrawals', can: ownerWithdrawals")
        ->toContain('const ownerWithdrawals = (f: SharedData) => !!f.canViewOwnerWithdrawals;');

    expect(file_get_contents(resource_path('js/lib/i18n.ts')))->toContain('...ownerWithdrawals');

    $dictionary = file_get_contents(resource_path('js/lib/i18n/owner-withdrawals.ts'));

    foreach (['owner_withdrawal.nav', 'owner_withdrawal.add', 'owner_withdrawal.authorise_self', 'owner_withdrawal.estimated_cost', 'owner_withdrawal.reimburse', 'owner_withdrawal.view_monthly'] as $key) {
        expect($dictionary)->toContain("'{$key}'");
    }

    foreach (['js/pages/accounting/owner-withdrawals/index.tsx', 'js/components/accounting/owner-withdrawal-dialog.tsx'] as $file) {
        expect(file_get_contents(resource_path($file)))->toContain("t('owner_withdrawal.");
    }

    // The shared flag the gate reads is really sent.
    $this->actingAs($this->owner)->get('/settings/appearance')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canViewOwnerWithdrawals', true)->etc());
    $this->actingAs(($this->asRole)('Staff'))->get('/settings/appearance')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canViewOwnerWithdrawals', false)->etc());
});
