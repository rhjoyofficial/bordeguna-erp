<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\CreateEmployee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The employee details additions from Joy's reorganisation, 23 Aug 2026:
 * a profile photo ("can add image") and who referred them ("Refer by").
 */
beforeEach(function () {
    Storage::fake('local');

    $this->business = Business::create(['name' => 'Photo Co', 'slug' => 'photo-co']);
    app(BusinessContext::class)->set($this->business->id);

    Permission::firstOrCreate(['name' => 'hrm.employees.manage', 'guard_name' => 'web']);
    foreach (['Owner', 'Staff'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()->givePermissionTo('hrm.employees.manage');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');

    $this->office = Office::create(['business_id' => $this->business->id, 'name' => 'Head Office']);

    $this->employee = app(CreateEmployee::class)->handle(
        ['first_name' => 'Karim', 'last_name' => 'A', 'employment_type' => 'permanent', 'joining_date' => '2025-01-01'],
        ['office_id' => $this->office->id],
    );

    $this->asOwner = fn () => $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id]);
});

it('stores a photo, replaces it as a singleton, and keeps it out of the documents list', function () {
    ($this->asOwner)()->post(route('hrm.employees.photo.store', $this->employee), [
        'photo' => UploadedFile::fake()->image('karim.jpg', 300, 300),
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($this->employee->fresh()->attachmentInCollection('photo'))->not->toBeNull();

    // Replacing does not accumulate: still exactly one photo attachment.
    ($this->asOwner)()->post(route('hrm.employees.photo.store', $this->employee), [
        'photo' => UploadedFile::fake()->image('karim2.png', 300, 300),
    ])->assertRedirect();

    expect($this->employee->fresh()->attachments()->where('collection', 'photo')->count())->toBe(1);

    // And the details page treats the face as a face, not a certificate.
    ($this->asOwner)()->get(route('hrm.employees.show', $this->employee))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('employee.photo_url', fn ($url) => filled($url))
            ->where('employee.documents', fn ($documents) => collect($documents)->isEmpty()));
});

it('removes the photo on request', function () {
    ($this->asOwner)()->post(route('hrm.employees.photo.store', $this->employee), [
        'photo' => UploadedFile::fake()->image('karim.jpg'),
    ]);

    ($this->asOwner)()->delete(route('hrm.employees.photo.destroy', $this->employee))->assertRedirect();

    expect($this->employee->fresh()->attachmentInCollection('photo'))->toBeNull();
});

it('refuses a photo from someone who may not manage employees', function () {
    $this->actingAs($this->staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.employees.photo.store', $this->employee), [
            'photo' => UploadedFile::fake()->image('sneak.jpg'),
        ])->assertForbidden();
});

it('refuses a file that is not an image', function () {
    ($this->asOwner)()->post(route('hrm.employees.photo.store', $this->employee), [
        'photo' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
    ])->assertSessionHasErrors('photo');
});

it('saves and shows who referred the employee', function () {
    ($this->asOwner)()->patch(route('hrm.employees.update', $this->employee), [
        'first_name' => 'Karim',
        'last_name' => 'A',
        'employment_type' => 'permanent',
        'referred_by' => 'Razzak Mill (Salam bhai)',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($this->employee->fresh()->referred_by)->toBe('Razzak Mill (Salam bhai)');

    ($this->asOwner)()->get(route('hrm.employees.show', $this->employee))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('employee.referred_by', 'Razzak Mill (Salam bhai)'));
});
