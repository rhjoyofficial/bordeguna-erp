<?php

use App\Models\User;
use App\Modules\Foundation\Actions\DeleteAttachment;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Storage::fake('local');

    $this->business = Business::create(['name' => 'Attach Co', 'slug' => 'attach-co']);
    $this->otherBusiness = Business::create(['name' => 'Other Attach', 'slug' => 'other-attach']);
    app(BusinessContext::class)->set($this->business->id);

    Permission::firstOrCreate(['name' => 'foundation.organization.manage', 'guard_name' => 'web']);
    foreach ([$this->business, $this->otherBusiness] as $biz) {
        foreach (['Owner', 'Staff'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'business_id' => $biz->id, 'guard_name' => 'web']);
        }
        Role::where('business_id', $biz->id)->where('name', 'Owner')->first()->givePermissionTo('foundation.organization.manage');
    }
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');
});

it('stores a file and records it against a model', function () {
    $attachment = app(StoreAttachment::class)->handle($this->business, UploadedFile::fake()->create('logo.png', 10), 'logo', $this->owner);

    expect($attachment->collection)->toBe('logo');
    expect($attachment->business_id)->toBe($this->business->id);
    expect($attachment->original_name)->toBe('logo.png');
    expect($attachment->uploaded_by)->toBe($this->owner->id);
    Storage::disk('local')->assertExists($attachment->path);
});

it('replaces a singleton collection and removes the old file', function () {
    $first = app(StoreAttachment::class)->handle($this->business, UploadedFile::fake()->create('a.png', 10), 'logo', $this->owner, singleton: true);
    $second = app(StoreAttachment::class)->handle($this->business, UploadedFile::fake()->create('b.png', 10), 'logo', $this->owner, singleton: true);

    Storage::disk('local')->assertMissing($first->path);
    Storage::disk('local')->assertExists($second->path);
    expect($this->business->attachments()->where('collection', 'logo')->count())->toBe(1);
});

it('deletes the file and the record together', function () {
    $attachment = app(StoreAttachment::class)->handle($this->business, UploadedFile::fake()->create('x.png', 10), 'logo', $this->owner);

    app(DeleteAttachment::class)->handle($attachment);

    Storage::disk('local')->assertMissing($attachment->path);
    expect(Attachment::find($attachment->id))->toBeNull();
});

it('streams an attachment for a member of its business', function () {
    $attachment = app(StoreAttachment::class)->handle($this->business, UploadedFile::fake()->create('logo.png', 10), 'logo', $this->owner);

    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->get(route('attachments.show', $attachment))
        ->assertOk();
});

/**
 * ⚠⚠ Found on the CRM slice 2d walk, 15 Sep 2026: Symfony's file response is
 * PUBLIC by default, and with a Last-Modified date a browser may reuse it with no
 * request at all. An officer opened a visit photo; a colleague outside his team,
 * signed in on the same browser, then "opened" it too, and the server - which
 * would have said 404 - was never asked. The older the file, the longer the
 * browser trusts its copy: an employee's NID scan from last month for days.
 */
it('tells every browser and proxy never to keep a copy of a private file', function () {
    $attachment = app(StoreAttachment::class)->handle($this->business, UploadedFile::fake()->create('nid-scan.png', 10), 'documents', $this->owner);

    $response = $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->get(route('attachments.show', $attachment))
        ->assertOk();

    $cacheControl = $response->headers->get('Cache-Control');

    expect($cacheControl)->toContain('no-store')
        ->and($cacheControl)->toContain('private')
        ->and($cacheControl)->not->toContain('public');
});

it('404s an attachment from another business', function () {
    app(BusinessContext::class)->set($this->otherBusiness->id);
    $other = app(StoreAttachment::class)->handle($this->otherBusiness, UploadedFile::fake()->create('o.png', 10), 'logo', null);
    app(BusinessContext::class)->set($this->business->id);

    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->get(route('attachments.show', $other))
        ->assertNotFound();
});

it('uploads a business logo through settings', function () {
    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('business.logo.store'), ['logo' => UploadedFile::fake()->image('logo.png')])
        ->assertRedirect();

    $logo = $this->business->attachmentInCollection('logo');
    expect($logo)->not->toBeNull();
    Storage::disk('local')->assertExists($logo->path);
});

it('removes a business logo', function () {
    app(StoreAttachment::class)->handle($this->business, UploadedFile::fake()->create('l.png', 10), 'logo', $this->owner, singleton: true);

    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->delete(route('business.logo.destroy'))
        ->assertRedirect();

    expect($this->business->attachmentInCollection('logo'))->toBeNull();
});

it('rejects a non-image logo', function () {
    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('business.logo.store'), ['logo' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')])
        ->assertSessionHasErrors('logo');
});

it('blocks a non-admin from uploading a logo', function () {
    $this->actingAs($this->staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('business.logo.store'), ['logo' => UploadedFile::fake()->image('logo.png')])
        ->assertForbidden();
});
