<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\AccountingPermissionsSeeder;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ACC-005 (audit of 14 Oct 2026): the Accounts menu showed VAT settings and
 * Transfer history to every `accounting.view` holder, while the routes need
 * `accounting.vat.manage` and `accounting.journal.post`. Finance and Payroll
 * met a 403. The menu now follows the route gate.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Menu Co', 'slug' => 'menu-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Finance', 'Payroll'] as $name) {
        Role::firstOrCreate(['name' => $name, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }

    $this->seed(AccountingPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);

    $this->as = function (string $role): User {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        $user->assignRole(Role::where('name', $role)->where('business_id', $this->business->id)->firstOrFail());

        return $user;
    };
});

it('hides VAT settings and Transfer history from Finance and Payroll, who cannot open them', function () {
    foreach (['Finance', 'Payroll'] as $role) {
        $user = ($this->as)($role);

        $this->actingAs($user)->get('/accounting/journals')
            ->assertInertia(fn ($page) => $page->where('canManageVat', false)->where('canPostJournal', false));
        $this->actingAs($user)->get('/accounting/vat-settings')->assertForbidden();
        $this->actingAs($user)->get('/accounting/money-transfers')->assertForbidden();
    }

    $owner = ($this->as)('Owner');
    $this->actingAs($owner)->get('/accounting/journals')
        ->assertInertia(fn ($page) => $page->where('canManageVat', true)->where('canPostJournal', true));

    // Round 3 (16 Oct 2026): the links are tabs in accounts-sections.ts now,
    // each with its gate on the same line as its address.
    $sections = file_get_contents(resource_path('js/components/accounting/accounts-sections.ts'));
    expect($sections)->toContain("{ title: 'VAT settings', url: '/accounting/vat-settings', can: (f: SharedData) => f.canManageVat")
        ->and($sections)->toContain("{ title: 'Transfers', url: '/accounting/money-transfers', can: post,")
        ->and($sections)->toContain('const post = (f: SharedData) => f.canPostJournal;');
});
