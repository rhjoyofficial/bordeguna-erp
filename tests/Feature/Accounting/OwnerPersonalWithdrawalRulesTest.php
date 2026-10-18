<?php

use App\Models\User;
use App\Modules\Accounting\Actions\AuthoriseOwnerWithdrawal;
use App\Modules\Accounting\Actions\SaveOwnerWithdrawal;
use App\Modules\Accounting\database\seeders\AccountingPermissionsSeeder;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\database\seeders\FoundationPermissionsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ D-C (Joy, 18 Oct 2026): the screen is KEPT, renamed "Owner Personal
 * Withdrawal", restricted to the Owner, Finance and a role explicitly given
 * the permission (the Manager loses it), with the sentence saying when to use
 * it and when to sell to his customer account instead. Plus the practice
 * findings P20 (the draft shows line totals) and P21 (authorising needs the
 * permission or being the proprietor with a note; the Owner role and the
 * super admin get no free pass).
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-18 10:00:00');

    $this->business = Business::create(['name' => 'Personal Co', 'slug' => 'personal-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Finance', 'Staff', 'Store Keeper'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new FoundationPermissionsSeeder)->run();
    (new AccountingPermissionsSeeder)->run();
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->asRole = function (string $role, array $attributes = []) {
        $user = User::factory()->create($attributes);
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->moin = ($this->asRole)('Owner');
    // Joy: Owner role AND super admin, not the proprietor.
    $this->joy = ($this->asRole)('Owner', ['is_super_admin' => true]);
    $this->finance = ($this->asRole)('Finance');
    $this->business->update(['proprietor_user_id' => $this->moin->id]);

    $kg = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAV', 'is_default' => true]);
    $this->salt = Product::create(['business_id' => $this->business->id, 'unit_of_measure_id' => $kg->id, 'sku' => 'SALT', 'name' => 'Pink Salt', 'type' => ProductType::FinishedGood, 'mrp' => '890.0000']);
    app(RecordStockIn::class)->handle($this->salt, $this->warehouse, '10', '566.3135', $this->moin, batchNumber: 'PRN-2', occurredAt: '2026-10-01 09:00:00');

    $this->draft = fn () => app(SaveOwnerWithdrawal::class)->handle($this->warehouse, '2026-10-18', [['product_id' => $this->salt->id, 'quantity' => '2']], $this->moin);
});

afterEach(fn () => Carbon::setTestNow());

it('refuses the Owner role and the super admin who is not the proprietor (P21), on the action and on the button', function () {
    $w = ($this->draft)();

    expect(fn () => app(AuthoriseOwnerWithdrawal::class)->handle($w, $this->joy, 'looks fine'))->toThrow(RuntimeException::class, 'second person');
    expect($w->fresh()->status->value)->toBe('draft');

    $this->actingAs($this->joy)->post("/accounting/owner-withdrawals/{$w->id}/authorise", ['authorisation_note' => 'looks fine'])
        ->assertRedirect()->assertSessionHas('error');
    $this->actingAs($this->joy)->get('/accounting/owner-withdrawals')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canAuthorise', false)->where('isProprietor', false)->etc());

    // The proprietor himself, with a note, still may.
    $done = app(AuthoriseOwnerWithdrawal::class)->handle($w, $this->moin, 'Musa away');
    expect($done->self_authorised)->toBeTrue();
});

it('lets a role explicitly given the permissions view and authorise (an inventory role), and Finance by default', function () {
    $keeper = ($this->asRole)('Store Keeper');
    $this->actingAs($keeper)->get('/accounting/owner-withdrawals')->assertForbidden();

    Role::findByName('Store Keeper')->givePermissionTo(['accounting.owner_withdrawal.view', AuthoriseOwnerWithdrawal::PERMISSION]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $keeper = $keeper->fresh();

    $w = ($this->draft)();
    $this->actingAs($keeper)->get('/accounting/owner-withdrawals')
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('canAuthorise', true)->etc());
    $this->actingAs($keeper)->post("/accounting/owner-withdrawals/{$w->id}/authorise", ['authorisation_note' => 'counted'])->assertSessionHas('success');

    expect($w->fresh()->authorised_by)->toBe($keeper->id);
    expect(AuthoriseOwnerWithdrawal::holdsPermission($this->finance))->toBeTrue();
});

it('takes the Manager\'s seeded view and authorise grants back, idempotently, leaving other roles alone', function () {
    $manager = Role::findByName('Manager');
    $manager->givePermissionTo(['accounting.owner_withdrawal.view', AuthoriseOwnerWithdrawal::PERMISSION]);

    $migration = require base_path('app/Modules/Accounting/database/migrations/2026_10_18_300300_owner_personal_withdrawal_is_for_owner_and_finance.php');
    $migration->up();
    $migration->up();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $grants = fn (string $role) => DB::table('role_has_permissions')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('role_has_permissions.role_id', Role::findByName($role)->id)
        ->pluck('permissions.name')->all();

    expect($grants('Manager'))->not->toContain('accounting.owner_withdrawal.view')->not->toContain(AuthoriseOwnerWithdrawal::PERMISSION)
        ->and($grants('Finance'))->toContain('accounting.owner_withdrawal.view')->toContain(AuthoriseOwnerWithdrawal::PERMISSION)
        ->and($grants('Owner'))->toContain('accounting.owner_withdrawal.view')->toContain('accounting.owner_withdrawal.manage');
});

it('shows a draft line\'s reference and difference as line totals, like an authorised one (P20)', function () {
    ($this->draft)();

    $this->actingAs($this->moin)->get('/accounting/owner-withdrawals')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('withdrawals.0.status', 'draft')
            ->where('withdrawals.0.lines.0.estimate.cost', '1132.63')
            ->where('withdrawals.0.lines.0.estimate.reference', '1780.00')
            ->where('withdrawals.0.lines.0.estimate.difference', '647.37')
            ->etc());
});

it('is called Owner Personal Withdrawal everywhere, in both languages, and says when to sell to his customer account instead', function () {
    $dictionary = file_get_contents(resource_path('js/lib/i18n/owner-withdrawals.ts'));

    expect($dictionary)
        ->toContain("'owner_withdrawal.nav': { en: 'Owner Personal Withdrawal', bn: 'মালিকের ব্যক্তিগত উত্তোলন' }")
        ->toContain("'owner_withdrawal.title': { en: 'Owner Personal Withdrawal'")
        ->toContain('Use this only when the proprietor takes stock for personal use. When he buys and pays (now or later), sell to his customer account at owner price.')
        ->not->toContain("en: 'Owner withdrawals'");

    expect(file_get_contents(resource_path('js/components/accounting/accounts-sections.ts')))->toContain("title: 'Owner Personal Withdrawal'");

    $page = file_get_contents(resource_path('js/pages/accounting/owner-withdrawals/index.tsx'));
    expect($page)->toContain('<Head title="Owner Personal Withdrawal" />')->toContain("t('owner_withdrawal.use_only_when')");

    $w = ($this->draft)();
    $this->actingAs($this->finance)->post("/accounting/owner-withdrawals/{$w->id}/authorise", ['authorisation_note' => 'ok'])
        ->assertSessionHas('success', fn (string $message) => str_starts_with($message, "Owner personal withdrawal {$w->number} authorised"));
});
