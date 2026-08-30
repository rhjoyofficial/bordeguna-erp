<?php

use App\Models\User;
use App\Modules\Foundation\Actions\StoreAttachment;
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
 * "I added my image, but it still shows RJ" (Joy, 30 Aug 2026).
 *
 * Two things were wrong. `auth.user.avatar` was a typed field that nothing
 * ever set, so the sidebar, the user menu and the chat panel drew initials
 * for everybody. And an employee's photo sat behind the same gate as their
 * NID and bank documents, so anyone who was not HR got a 403 on another
 * person's face and the browser fell back to initials there too.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->business = Business::create(['name' => 'Face Co', 'slug' => 'face-co']);
    app(BusinessContext::class)->set($this->business->id);

    Permission::firstOrCreate(['name' => 'hrm.employees.manage', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'foundation.chat.use', 'guard_name' => 'web']);
    foreach (['Owner', 'Staff'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()->givePermissionTo('hrm.employees.manage');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->joy = User::factory()->create(['name' => 'Rashedul Joy']);
    $this->joy->businesses()->attach($this->business->id);
    $this->joy->assignRole('Owner');

    $this->staff = User::factory()->create(['name' => 'Some Staff']);
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');

    $office = Office::create(['business_id' => $this->business->id, 'name' => 'Head Office']);

    $this->employee = app(CreateEmployee::class)->handle(
        ['first_name' => 'Rashedul', 'last_name' => 'Joy', 'employment_type' => 'permanent', 'joining_date' => '2025-01-01'],
        ['office_id' => $office->id],
    );
    $this->employee->forceFill(['user_id' => $this->joy->id])->save();

    $this->photo = app(StoreAttachment::class)->handle(
        $this->employee, UploadedFile::fake()->image('joy.jpg', 200, 200), 'photo', $this->joy, singleton: true,
    );

    $this->as = fn (User $user) => $this->actingAs($user)->withSession(['current_business_id' => $this->business->id]);
});

it('shares the employee photo as the signed-in user avatar on every page', function () {
    ($this->as)($this->joy)->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.user.avatar', $this->photo->url()));
});

it('shares no avatar for a user without a linked employee', function () {
    ($this->as)($this->staff)->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.user.avatar', null));
});

it('lets anyone in the business see a colleague face, but not their documents', function () {
    $nid = app(StoreAttachment::class)->handle($this->employee, UploadedFile::fake()->image('nid.jpg'), 'national_id', $this->joy);

    ($this->as)($this->staff)->get($this->photo->url())->assertOk();
    ($this->as)($this->staff)->get($nid->url())->assertForbidden();
});

it('shows the picture on the profile page and lets the person replace and remove it', function () {
    ($this->as)($this->joy)->get(route('profile.edit'))
        ->assertInertia(fn ($page) => $page->where('photo_url', $this->photo->url())->where('hasEmployee', true));

    ($this->as)($this->joy)->post(route('profile.photo.store'), ['photo' => UploadedFile::fake()->image('new.png', 300, 300)])
        ->assertRedirect()->assertSessionHasNoErrors();

    $fresh = $this->employee->fresh();
    expect($fresh->attachments()->where('collection', 'photo')->count())->toBe(1)
        ->and($fresh->attachmentInCollection('photo')->id)->not->toBe($this->photo->id);

    ($this->as)($this->joy)->delete(route('profile.photo.destroy'))->assertRedirect();
    expect($this->employee->fresh()->attachmentInCollection('photo'))->toBeNull();
});

it('tells a user with no employee record why there is nothing to upload', function () {
    ($this->as)($this->staff)->post(route('profile.photo.store'), ['photo' => UploadedFile::fake()->image('x.png')])
        ->assertRedirect()->assertSessionHas('error');
});

it('hands the employee list each photo', function () {
    ($this->as)($this->joy)->get(route('hrm.employees.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('employees.0.photo_url', $this->photo->url()));
});

it('still shows the picture on a page that carries no business context, like the profile page', function () {
    // ⚠ The profile routes sit outside the `business` middleware, and a
    // console-style cold request has no tenant set. Seen live on 30 Aug 2026:
    // every business page showed Joy's face, /settings/profile showed "RJ".
    app(BusinessContext::class)->forget();

    $this->actingAs($this->joy)->withSession(['current_business_id' => $this->business->id])
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.avatar', $this->photo->url())
            ->where('photo_url', $this->photo->url()));
});
