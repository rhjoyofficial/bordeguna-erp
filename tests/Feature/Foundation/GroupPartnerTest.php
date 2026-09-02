<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 6, Slice 1 (design doc: BDG-ERP-M6-GROUP-LAYER-DESIGN.md):
 * group partner links + the group 360.
 *
 * Partners STAY per-business; a link is a statement of identity ("these two
 * rows are the same real-world party"), reversible, touching no document.
 * The group screen reads THROUGH links and shows a member row only when the
 * viewer belongs to that member's business - the natural gate.
 */
beforeEach(function () {
    $this->bdg = Business::create(['name' => 'Bor de Guna', 'slug' => 'bdg']);
    $this->lld = Business::create(['name' => 'LaLaDia', 'slug' => 'lld']);

    app(BusinessContext::class)->set($this->bdg->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->bdg->id);
    foreach ([$this->bdg, $this->lld] as $business) {
        Role::create(['name' => 'Owner', 'guard_name' => 'web', 'business_id' => $business->id]);
    }

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach([$this->bdg->id, $this->lld->id]);

    $this->limitedUser = User::factory()->create();
    $this->limitedUser->businesses()->attach($this->bdg->id);

    // The same real-world dealer, once in each company, sharing a phone.
    app(BusinessContext::class)->set($this->bdg->id);
    $this->bdgCustomer = Customer::create([
        'business_id' => $this->bdg->id, 'code' => 'CUS-0001', 'name' => 'Rahim Traders',
        'type' => 'dealer', 'phone' => '01712345678', 'is_active' => true,
    ]);
    app(BusinessContext::class)->set($this->lld->id);
    $this->lldCustomer = Customer::create([
        'business_id' => $this->lld->id, 'code' => 'CUS-0001', 'name' => 'Rahim Bhai',
        'type' => 'dealer', 'phone' => '+8801712345678', 'is_active' => true,
    ]);

    // Outstanding balances: 5,000 in Bor de Guna, 2,000 in LaLaDia.
    foreach ([[$this->bdg->id, $this->bdgCustomer->id, 5000, 'INV-A'], [$this->lld->id, $this->lldCustomer->id, 2000, 'INV-B']] as [$biz, $cus, $total, $no]) {
        DB::table('sales_invoices')->insert([
            'business_id' => $biz, 'customer_id' => $cus, 'invoice_number' => $no,
            'invoice_date' => '2026-09-01', 'subtotal' => $total, 'invoice_discount' => 0,
            'tax_total' => 0, 'total' => $total,
            'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    app(BusinessContext::class)->set($this->bdg->id);

    $this->asOwner = fn () => $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->bdg->id]);
});

it('suggests the same phone living in two companies, and links it into one party', function () {
    ($this->asOwner)()->get(route('group.partners'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('group/partners')
            ->has('suggestions', 1)
            ->where('suggestions.0.members.0.business', 'Bor de Guna')
            ->where('suggestions.0.members.1.business', 'LaLaDia'));

    ($this->asOwner)()->post(route('group.partners.link'), [
        'name' => 'Rahim (group)',
        'kind' => 'customer',
        'members' => [
            ['business_id' => $this->bdg->id, 'partner_id' => $this->bdgCustomer->id],
            ['business_id' => $this->lld->id, 'partner_id' => $this->lldCustomer->id],
        ],
    ])->assertSessionHasNoErrors();

    ($this->asOwner)()->get(route('group.partners'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('links', 1)
            ->where('links.0.name', 'Rahim (group)')
            ->has('links.0.members', 2)
            ->where('links.0.total_outstanding', fn ($value) => (float) $value === 7000.0)
            // Once linked, the pair stops being a suggestion.
            ->has('suggestions', 0));
});

it('shows a viewer only the member businesses they belong to', function () {
    ($this->asOwner)()->post(route('group.partners.link'), [
        'name' => 'Rahim (group)', 'kind' => 'customer',
        'members' => [
            ['business_id' => $this->bdg->id, 'partner_id' => $this->bdgCustomer->id],
            ['business_id' => $this->lld->id, 'partner_id' => $this->lldCustomer->id],
        ],
    ]);

    $this->actingAs($this->limitedUser)->withSession(['current_business_id' => $this->bdg->id])
        ->get(route('group.partners'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('links.0.members', 1)
            ->where('links.0.members.0.business', 'Bor de Guna')
            // The total follows the eyes: only what this viewer may see.
            ->where('links.0.total_outstanding', fn ($value) => (float) $value === 5000.0));
});

it('unlinks without touching any partner or document', function () {
    ($this->asOwner)()->post(route('group.partners.link'), [
        'name' => 'Rahim (group)', 'kind' => 'customer',
        'members' => [
            ['business_id' => $this->bdg->id, 'partner_id' => $this->bdgCustomer->id],
            ['business_id' => $this->lld->id, 'partner_id' => $this->lldCustomer->id],
        ],
    ]);

    $link = (int) DB::table('group_partner_links')->value('id');

    ($this->asOwner)()->delete(route('group.partners.unlink', $link))->assertSessionHasNoErrors();

    expect(DB::table('group_partner_links')->count())->toBe(0)
        ->and(Customer::withoutGlobalScopes()->count())->toBe(2)
        ->and(DB::table('sales_invoices')->count())->toBe(2);
});
