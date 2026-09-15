<?php

use App\Models\User;
use App\Modules\Crm\Actions\LogCrmActivity;
use App\Modules\Crm\database\seeders\CrmPermissionsSeeder;
use App\Modules\Crm\Enums\ActivityType;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Models\CrmActivity;
use App\Modules\Crm\Models\Lead;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Sales\Models\Customer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * CRM lead slice 2d: a visit may carry proof, and a shop is pinned by its first
 * visit with a location.
 *
 * ⭐⭐ JOY'S ANSWERS ON TRIAL HERE. Answer 2: GPS and a photo on a visit are
 * MANUAL and OPTIONAL, so a visit with neither is as good a visit as any. Plan
 * D14 (approved 15 Sep): the pinned location is the FIRST visit that carries
 * GPS, shown as a Google Maps link, no embedded map.
 *
 * ⚠⚠ The refusals that matter: a location that is half a location, photos on a
 * call, and a visit's photos reaching somebody outside the lead's team through
 * the file manager or the stream.
 *
 * The org chart: the Owner; Shoykod (Sales Lead) with Rakib under him; Stranger,
 * an officer in nobody's team.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-15 10:00:00');
    Storage::fake('local');

    $this->business = Business::create(['name' => 'Visit Co', 'slug' => 'visit-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }
    (new CrmPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->person = function (string $name, string $role, ?Employee $supervisor = null): array {
        $user = User::factory()->create(['name' => $name]);
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        $employee = Employee::create([
            'employee_code' => 'VST-'.str_pad((string) (Employee::count() + 1), 6, '0', STR_PAD_LEFT),
            'user_id' => $user->id,
            'first_name' => $name,
            'last_name' => 'Test',
            'employment_type' => 'permanent',
            'status' => 'active',
        ]);

        EmployeeAssignment::create([
            'business_id' => $this->business->id,
            'employee_id' => $employee->id,
            'supervisor_id' => $supervisor?->id,
            'is_primary' => true,
            'effective_from' => '2026-01-01',
        ]);

        return [$user, $employee];
    };

    [$this->owner] = ($this->person)('Musa', 'Owner');
    [$this->asm, $asmEmployee] = ($this->person)('Shoykod', 'Sales Lead');
    [$this->rakib] = ($this->person)('Rakib', 'Sales Officer', $asmEmployee);
    [$this->stranger] = ($this->person)('Stranger', 'Sales Officer');

    $this->shop = Lead::create([
        'business_id' => $this->business->id, 'lead_type' => 'retailer', 'name' => 'Mirpur Store',
        'phone' => '01711000001', 'status' => LeadStatus::New, 'assigned_to_user_id' => $this->rakib->id,
    ]);

    $this->as = function (User $user) {
        app()->forgetScopedInstances();

        return $this->actingAs($user)->withHeader('X-Business-Id', (string) $this->business->id);
    };

    $this->visit = fn (array $extra = []) => array_merge([
        'type' => 'visit', 'direction' => 'outbound', 'summary' => 'At the shop',
    ], $extra);

    $this->here = ['latitude' => '23.8103000', 'longitude' => '90.4125000', 'gps_accuracy_meters' => '12.4'];

    $this->timeline = fn (User $user, string $kind = 'lead', ?int $id = null) => ($this->as)($user)
        ->get("/crm/{$kind}/".($id ?? $this->shop->id).'/timeline')->assertOk()->viewData('page')['props'];

    $this->listRow = fn (User $user) => collect(($this->as)($user)->get('/crm/leads')->assertOk()->viewData('page')['props']['leads']['data'])
        ->firstWhere('id', $this->shop->id);
});

afterEach(fn () => Carbon::setTestNow());

/* -------------------------------------------------------------------------
 | The proof itself (answer 2)
 | ---------------------------------------------------------------------- */

it('records a visit with where the phone was and what the officer saw', function () {
    ($this->as)($this->rakib)->post("/crm/lead/{$this->shop->id}/activities", ($this->visit)($this->here + [
        'photos' => [UploadedFile::fake()->image('front.jpg', 800, 600), UploadedFile::fake()->image('shelf.jpg', 800, 600)],
    ]))->assertSessionHasNoErrors();

    $visit = CrmActivity::withoutGlobalScopes()->sole();

    expect($visit->only(['latitude', 'longitude', 'gps_accuracy_meters']))->toBe([
        'latitude' => '23.8103000', 'longitude' => '90.4125000', 'gps_accuracy_meters' => '12.40',
    ])->and($visit->attachments()->where('collection', 'photos')->count())->toBe(2);

    $row = ($this->timeline)($this->rakib)['activities'][0];

    expect($row['location'])->toMatchArray([
        'accuracy_meters' => 12,
        'map_url' => 'https://www.google.com/maps/search/?api=1&query=23.8103,90.4125',
    ])->and(collect($row['photos'])->pluck('name')->sort()->values()->all())->toBe(['front.jpg', 'shelf.jpg']);
});

it('records a visit with no proof at all, as Joy decided', function () {
    ($this->as)($this->rakib)->post("/crm/lead/{$this->shop->id}/activities", ($this->visit)())->assertSessionHasNoErrors();

    $row = ($this->timeline)($this->rakib)['activities'][0];

    expect($row['location'])->toBeNull()
        ->and($row['photos'])->toBe([]);
});

it('drops a location sent with a call, and refuses photos sent with one', function () {
    ($this->as)($this->rakib)->post("/crm/lead/{$this->shop->id}/activities", [
        'type' => 'call', 'direction' => 'outbound', 'summary' => 'Rang them', ...$this->here,
    ])->assertSessionHasNoErrors();

    expect(CrmActivity::withoutGlobalScopes()->sole()->latitude)->toBeNull();

    ($this->as)($this->rakib)->post("/crm/lead/{$this->shop->id}/activities", [
        'type' => 'call', 'direction' => 'outbound', 'summary' => 'Rang them again',
        'photos' => [UploadedFile::fake()->image('screenshot.jpg')],
    ])->assertSessionHasErrors(['photos' => 'Photos are recorded with a visit.']);

    expect(CrmActivity::withoutGlobalScopes()->count())->toBe(1)
        ->and(Attachment::withoutGlobalScopes()->count())->toBe(0);
});

it('refuses half a location, or a place that is not on the map', function (array $sent, string $field) {
    ($this->as)($this->rakib)->post("/crm/lead/{$this->shop->id}/activities", ($this->visit)($sent))
        ->assertSessionHasErrors($field);

    expect(CrmActivity::withoutGlobalScopes()->count())->toBe(0);
})->with([
    'no longitude' => [['latitude' => '23.81', 'gps_accuracy_meters' => '10'], 'longitude'],
    'no accuracy' => [['latitude' => '23.81', 'longitude' => '90.41'], 'gps_accuracy_meters'],
    'latitude off the planet' => [['latitude' => '123.4', 'longitude' => '90.41', 'gps_accuracy_meters' => '10'], 'latitude'],
    'negative accuracy' => [['latitude' => '23.81', 'longitude' => '90.41', 'gps_accuracy_meters' => '-1'], 'gps_accuracy_meters'],
]);

it('refuses half a location even from a caller that skips the screen\'s rules', function () {
    expect(fn () => app(LogCrmActivity::class)->handle(
        subject: $this->shop,
        type: ActivityType::Visit,
        summary: 'Imported visit',
        recordedBy: $this->rakib,
        location: ['latitude' => 23.81, 'longitude' => null, 'accuracy' => 10],
    ))->toThrow(ValidationException::class);

    expect(CrmActivity::withoutGlobalScopes()->count())->toBe(0);
});

it('never moves a visit once it is recorded', function () {
    ($this->as)($this->rakib)->post("/crm/lead/{$this->shop->id}/activities", ($this->visit)($this->here))->assertSessionHasNoErrors();

    $visit = CrmActivity::withoutGlobalScopes()->sole();

    expect(fn () => $visit->update(['latitude' => '22.3569000']))->toThrow(RuntimeException::class)
        ->and($visit->fresh()->latitude)->toBe('23.8103000');
});

/* -------------------------------------------------------------------------
 | The pinned location (plan D14)
 | ---------------------------------------------------------------------- */

it('pins a shop to the first visit with a location by when it happened, not the latest or the first typed', function () {
    // A visit with no location happened first of all: it pins nothing.
    ($this->as)($this->rakib)->post("/crm/lead/{$this->shop->id}/activities", ($this->visit)(['occurred_at' => '2026-09-10 09:00:00']))
        ->assertSessionHasNoErrors();

    // Typed first, but it happened on the 12th.
    ($this->as)($this->rakib)->post("/crm/lead/{$this->shop->id}/activities", ($this->visit)([
        'occurred_at' => '2026-09-12 11:00:00', 'latitude' => '23.9000000', 'longitude' => '90.5000000', 'gps_accuracy_meters' => '30',
    ]))->assertSessionHasNoErrors();

    // Typed a day late, but it happened on the 11th: this is the first time
    // somebody stood at the shop with a location.
    ($this->as)($this->asm)->post("/crm/lead/{$this->shop->id}/activities", ($this->visit)([
        'occurred_at' => '2026-09-11 16:00:00', ...$this->here,
    ]))->assertSessionHasNoErrors();

    // ⚠ Typed last AND happened last. Without it, "the latest typed" and "the
    // first that happened" pick the same visit and a wrong ordering would pass.
    ($this->as)($this->rakib)->post("/crm/lead/{$this->shop->id}/activities", ($this->visit)([
        'occurred_at' => '2026-09-14 12:00:00', 'latitude' => '24.0000000', 'longitude' => '90.6000000', 'gps_accuracy_meters' => '8',
    ]))->assertSessionHasNoErrors();

    $pinned = ($this->timeline)($this->rakib)['subject']['pinned'];
    $expected = 'https://www.google.com/maps/search/?api=1&query=23.8103,90.4125';

    expect($pinned['map_url'])->toBe($expected)
        ->and($pinned['visited_at'])->toBe('2026-09-11 16:00:00')
        ->and($pinned['by'])->toBe('Shoykod')
        ->and(($this->listRow)($this->rakib)['map_url'])->toBe($expected);
});

it('shows no map for a shop nobody has located yet', function () {
    ($this->as)($this->rakib)->post("/crm/lead/{$this->shop->id}/activities", [
        'type' => 'call', 'direction' => 'outbound', 'summary' => 'Rang them', ...$this->here,
    ])->assertSessionHasNoErrors();

    expect(($this->timeline)($this->rakib)['subject']['pinned'])->toBeNull()
        ->and(($this->listRow)($this->rakib)['map_url'])->toBeNull();
});

/* -------------------------------------------------------------------------
 | Who sees a visit's photos
 | ---------------------------------------------------------------------- */

it('keeps a visit\'s photos as hidden as the lead, in the stream and in the file manager', function () {
    ($this->as)($this->rakib)->post("/crm/lead/{$this->shop->id}/activities", ($this->visit)([
        'photos' => [UploadedFile::fake()->image('front.jpg', 400, 300)],
    ]))->assertSessionHasNoErrors();

    $photo = Attachment::withoutGlobalScopes()->sole();

    Permission::firstOrCreate(['name' => 'files.view', 'guard_name' => 'web']);
    Role::where('business_id', $this->business->id)->whereIn('name', ['Sales Officer', 'Sales Lead'])->get()
        ->each->givePermissionTo('files.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $listed = fn (User $user) => collect(($this->as)($user)->get('/files')->assertOk()->viewData('page')['props']['files']['data'])
        ->pluck('filename')->all();

    expect($listed($this->rakib))->toBe(['front.jpg'])
        ->and($listed($this->asm))->toBe(['front.jpg'])
        ->and($listed($this->stranger))->toBe([]);

    ($this->as)($this->asm)->get(route('attachments.show', $photo))->assertOk();
    ($this->as)($this->stranger)->get(route('attachments.show', $photo))->assertNotFound();
});

it('shows a customer visit\'s photos to whoever may read the timeline, and to nobody else', function () {
    $customer = Customer::create(['business_id' => $this->business->id, 'code' => 'CUST-1', 'name' => 'Adabor Grocery']);

    ($this->as)($this->owner)->post("/crm/customer/{$customer->id}/activities", ($this->visit)([
        'photos' => [UploadedFile::fake()->image('counter.jpg', 400, 300)],
    ]))->assertSessionHasNoErrors();

    $photo = Attachment::withoutGlobalScopes()->sole();

    ($this->as)($this->stranger)->get(route('attachments.show', $photo))->assertOk();

    // Somebody in the business with no CRM permission at all.
    $clerk = User::factory()->create(['name' => 'Clerk']);
    $clerk->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $clerk->assignRole('Staff');
    Role::where('business_id', $this->business->id)->where('name', 'Staff')->sole()->syncPermissions([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    ($this->as)($clerk)->get(route('attachments.show', $photo))->assertForbidden();
});
