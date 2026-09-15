<?php

use App\Models\User;
use App\Modules\Crm\Actions\ArchiveLead;
use App\Modules\Crm\Actions\LinkDuplicateLead;
use App\Modules\Crm\Actions\VerifyLead;
use App\Modules\Crm\database\seeders\CrmPermissionsSeeder;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Models\Lead;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Support\BdGeo;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * CRM lead slice 2b: the lead screen catches up with what a field lead is.
 *
 * ⭐ From Joy's interview (design doc answers 1-20) and build plan D8: a shop is
 * a PLACE, recorded as district and thana in the dataset's own spelling plus
 * the market that actually finds it; the proprietor is a separate fact from the
 * shop's name; a second phone warns but never blocks; and the list is the
 * working list unless somebody asks for more.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Field Co', 'slug' => 'field-co-2b']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }
    (new CrmPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $person = function (string $name, string $role, ?Employee $supervisor = null): array {
        $user = User::factory()->create(['name' => $name]);
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        $employee = Employee::create([
            'employee_code' => 'F2B-'.str_pad((string) (Employee::count() + 1), 6, '0', STR_PAD_LEFT),
            'user_id' => $user->id, 'first_name' => $name, 'last_name' => 'Test',
            'employment_type' => 'permanent', 'status' => 'active',
        ]);
        EmployeeAssignment::create([
            'business_id' => $this->business->id, 'employee_id' => $employee->id,
            'supervisor_id' => $supervisor?->id, 'is_primary' => true, 'effective_from' => '2026-01-01',
        ]);

        return [$user, $employee];
    };

    [$this->owner] = $person('Musa', 'Owner');
    [$this->asm, $asmEmployee] = $person('Shoykod', 'Sales Lead');
    [$this->rakib] = $person('Rakib', 'Sales Officer', $asmEmployee);
    [$this->stranger] = $person('Stranger', 'Sales Officer');

    $this->as = function (User $user) {
        app()->forgetScopedInstances();

        return $this->actingAs($user)->withHeader('X-Business-Id', (string) $this->business->id);
    };

    $this->shop = fn (array $overrides = []) => array_merge([
        'lead_type' => 'retailer', 'name' => 'Rahim Mudi Store', 'contact_name' => 'Abdur Rahim',
        'phone' => '01711000001', 'status' => 'new', 'district' => 'Dhaka', 'thana' => 'Mirpur',
        'market' => 'Mirpur 1 kitchen market',
    ], $overrides);

    $this->props = fn (User $user, string $query = '') => ($this->as)($user)->get('/crm/leads'.$query)->assertOk()->viewData('page')['props'];
});

/* -------------------------------------------------------------------------
 | A shop is a place (D8)
 | ---------------------------------------------------------------------- */

it('refuses a shop, a distributor or a supplier with no district and thana', function (string $type) {
    ($this->as)($this->rakib)->post('/crm/leads', ($this->shop)(['lead_type' => $type, 'district' => '', 'thana' => '']))
        ->assertSessionHasErrors(['district', 'thana']);

    expect(Lead::withoutGlobalScopes()->count())->toBe(0);
})->with(['retailer', 'distributor', 'supplier']);

it('does not ask a consumer where they live', function () {
    ($this->as)($this->rakib)->post('/crm/leads', ['name' => 'Fatima', 'phone' => '01911000000', 'status' => 'new'])
        ->assertSessionHasNoErrors();
});

it('stores the dataset\'s own spelling of the place, whatever was typed', function () {
    ($this->as)($this->rakib)->post('/crm/leads', ($this->shop)([
        'district' => 'chittagong', 'thana' => (string) collect(BdGeo::thanas('Chattogram'))->first()['name'],
    ]))->assertSessionHasNoErrors();

    expect(Lead::withoutGlobalScopes()->sole()->district)->toBe('Chattogram');
});

it('refuses a thana that belongs to another district', function () {
    ($this->as)($this->rakib)->post('/crm/leads', ($this->shop)(['district' => 'Sylhet', 'thana' => 'Mirpur']))
        ->assertSessionHasErrors(['thana' => 'Pick a thana of Sylhet from the list.']);
});

it('refuses an area outside the thana, and an area with no thana', function () {
    ($this->as)($this->rakib)->post('/crm/leads', ($this->shop)(['area' => 'Jigatala TSO']))
        ->assertSessionHasErrors('area');

    ($this->as)($this->rakib)->post('/crm/leads', ['name' => 'Fatima', 'status' => 'new', 'district' => 'Dhaka', 'area' => 'Mirpur TSO'])
        ->assertSessionHasErrors('thana');
});

it('keeps a lead\'s type when an edit does not send one, so a shop still needs its place', function () {
    ($this->as)($this->rakib)->post('/crm/leads', ($this->shop)())->assertSessionHasNoErrors();
    $lead = Lead::withoutGlobalScopes()->sole();

    ($this->as)($this->rakib)->put("/crm/leads/{$lead->id}", ['name' => 'Renamed', 'status' => 'contacted'])
        ->assertSessionHasErrors(['district', 'thana']);
});

/* -------------------------------------------------------------------------
 | The second phone
 | ---------------------------------------------------------------------- */

it('finds a lead by its second number, and a second number by a lead\'s first', function () {
    ($this->as)($this->rakib)->post('/crm/leads', ($this->shop)(['secondary_phone' => '+880 1819-000000']))->assertSessionHasNoErrors();

    $bySecond = ($this->as)($this->rakib)->getJson('/crm/leads/duplicates?phone=01819000000')->json('leads');
    $byFirst = ($this->as)($this->rakib)->getJson('/crm/leads/duplicates?phone=01711000001')->json('leads');

    expect($bySecond)->toHaveCount(1)->and($byFirst)->toHaveCount(1)
        ->and(Lead::withoutGlobalScopes()->sole()->secondary_phone_normalized)->toBe('01819000000');
});

it('never makes the second number unique: two shops may list the same owner\'s mobile', function () {
    ($this->as)($this->rakib)->post('/crm/leads', ($this->shop)(['secondary_phone' => '01819000000']))->assertSessionHasNoErrors();
    ($this->as)($this->rakib)->post('/crm/leads', ($this->shop)(['name' => 'Rahim Second Store', 'phone' => '01711000002', 'secondary_phone' => '01819000000']))
        ->assertSessionHasNoErrors();

    expect(Lead::withoutGlobalScopes()->count())->toBe(2);
});

/* -------------------------------------------------------------------------
 | The list
 | ---------------------------------------------------------------------- */

it('shows the working list by default, and the rest only when asked', function () {
    $make = fn (string $name, string $phone) => Lead::create(['business_id' => $this->business->id, 'lead_type' => 'retailer', 'name' => $name, 'phone' => $phone, 'status' => LeadStatus::New, 'district' => 'Dhaka', 'thana' => 'Mirpur']);

    $working = $make('Working', '01711000011');
    $archived = $make('Archived', '01711000012');
    $copy = $make('Copy', '01711000013');

    app(ArchiveLead::class)->handle($archived, $this->owner);
    app(LinkDuplicateLead::class)->handle($copy, $working, $this->owner);

    $names = fn (string $query) => collect(($this->props)($this->owner, $query)['leads']['data'])->pluck('name')->sort()->values()->all();

    expect($names(''))->toBe(['Working'])
        ->and($names('?show=archived'))->toBe(['Archived'])
        ->and($names('?show=duplicates'))->toBe(['Copy'])
        ->and($names('?show=all'))->toBe(['Archived', 'Copy', 'Working']);
});

it('filters by type, by verification and by district', function () {
    $retailer = Lead::create(['business_id' => $this->business->id, 'lead_type' => 'retailer', 'name' => 'Dhaka Shop', 'phone' => '01711000021', 'status' => LeadStatus::New, 'district' => 'Dhaka', 'thana' => 'Mirpur']);
    Lead::create(['business_id' => $this->business->id, 'lead_type' => 'supplier', 'name' => 'Sylhet Supplier', 'phone' => '01711000022', 'status' => LeadStatus::New, 'district' => 'Sylhet', 'thana' => 'Sylhet Sadar']);
    Lead::create(['business_id' => $this->business->id, 'name' => 'A Consumer', 'status' => LeadStatus::New]);
    app(VerifyLead::class)->handle($retailer, $this->owner);

    $names = fn (string $query) => collect(($this->props)($this->owner, $query)['leads']['data'])->pluck('name')->sort()->values()->all();

    expect($names('?lead_type=supplier'))->toBe(['Sylhet Supplier'])
        ->and($names('?verification=verified'))->toBe(['Dhaka Shop'])
        ->and($names('?verification=unverified'))->toBe(['A Consumer', 'Sylhet Supplier'])
        ->and($names('?district=Dhaka'))->toBe(['Dhaka Shop']);
});

it('gives each row its type, place, verification and who added it', function () {
    ($this->as)($this->rakib)->post('/crm/leads', ($this->shop)())->assertSessionHasNoErrors();
    app(VerifyLead::class)->handle(Lead::withoutGlobalScopes()->sole(), $this->asm);

    $row = ($this->props)($this->rakib)['leads']['data'][0];

    expect($row)->toMatchArray([
        'lead_type' => 'retailer', 'lead_type_label' => 'Retailer', 'contact_name' => 'Abdur Rahim',
        'district' => 'Dhaka', 'thana' => 'Mirpur', 'market' => 'Mirpur 1 kitchen market',
        'is_verified' => true, 'verified_by' => 'Shoykod', 'is_archived' => false, 'created_by' => 'Rakib',
    ])->and($row['verified_on'])->toBe(now()->toDateString());
});

it('searches the market, the proprietor and the second number too', function () {
    ($this->as)($this->rakib)->post('/crm/leads', ($this->shop)(['secondary_phone' => '01819000000']))->assertSessionHasNoErrors();

    foreach (['kitchen market', 'Abdur', '01819000000'] as $term) {
        expect(($this->props)($this->rakib, '?search='.urlencode($term))['leads']['data'])->toHaveCount(1, "search: {$term}");
    }
});

it('gives the screen the lead types and the districts it may pick from', function () {
    $props = ($this->props)($this->rakib);

    expect(collect($props['lead_types'])->pluck('value')->all())->toBe(['customer', 'retailer', 'distributor', 'supplier'])
        ->and(collect($props['lead_types'])->firstWhere('value', 'customer')['is_shop'])->toBeFalse()
        ->and($props['districts'])->toContain('Dhaka')
        ->and($props['districts'])->toHaveCount(64);
});

/* -------------------------------------------------------------------------
 | Who a lead may be handed to
 | ---------------------------------------------------------------------- */

it('offers an officer only himself, and the ASM only his team', function () {
    expect(collect(($this->props)($this->rakib)['agents'])->pluck('name')->all())->toBe(['Rakib'])
        ->and(collect(($this->props)($this->asm)['agents'])->pluck('name')->sort()->values()->all())->toBe(['Rakib', 'Shoykod'])
        ->and(collect(($this->props)($this->owner)['agents'])->pluck('name')->sort()->values()->all())->toBe(['Musa', 'Rakib', 'Shoykod', 'Stranger']);
});

it('refuses an ASM handing a lead to somebody outside his team, however the request is written', function () {
    ($this->as)($this->asm)->post('/crm/leads', ($this->shop)(['assigned_to_user_id' => $this->stranger->id]))
        ->assertSessionHasErrors(['assigned_to_user_id' => 'You can hand a lead only to yourself or somebody in your team.']);

    ($this->as)($this->asm)->post('/crm/leads', ($this->shop)(['assigned_to_user_id' => $this->rakib->id]))->assertSessionHasNoErrors();
    ($this->as)($this->owner)->post('/crm/leads', ($this->shop)(['name' => 'Other', 'phone' => '01711000099', 'assigned_to_user_id' => $this->stranger->id]))
        ->assertSessionHasNoErrors();
});

/* -------------------------------------------------------------------------
 | Conversion and the place lookups
 | ---------------------------------------------------------------------- */

it('refuses a customer type that does not exist with a sentence, not a crash', function () {
    ($this->as)($this->owner)->post('/crm/leads', ($this->shop)())->assertSessionHasNoErrors();
    $lead = Lead::withoutGlobalScopes()->sole();

    ($this->as)($this->owner)->post("/crm/leads/{$lead->id}/convert", ['code' => 'RAHIM', 'name' => 'Rahim', 'type' => 'Distributor'])
        ->assertSessionHasErrors('type');

    expect($lead->fresh()->converted_customer_id)->toBeNull();
});

it('answers the thanas and areas of a place for any signed-in screen', function () {
    expect(($this->as)($this->rakib)->getJson('/places/thanas?district=Dhaka')->assertOk()->json('data'))->toContain('Mirpur')
        ->and(($this->as)($this->rakib)->getJson('/places/areas?district=Dhaka&thana=Mirpur')->json('data'))->toBe(['Mirpur TSO'])
        // ⚠ An unknown place is an empty list and a 200, never a 404.
        ->and(($this->as)($this->rakib)->getJson('/places/thanas?district=Atlantis')->assertOk()->json('data'))->toBe([]);
});

it('does not answer the place lookups to somebody signed out', function () {
    auth()->logout();

    $this->getJson('/places/thanas?district=Dhaka')->assertUnauthorized();
});
