<?php

use App\Models\User;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\CreateEmployee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Storage::fake('local');

    $this->business = Business::create(['name' => 'Doc Co', 'slug' => 'doc-co']);
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
});

function docOwner()
{
    return test()->actingAs(test()->owner)->withSession(['current_business_id' => test()->business->id]);
}

function uploadDoc(array $overrides = [])
{
    return docOwner()->post(route('hrm.employees.documents.store', test()->employee), array_merge([
        'category' => 'national_id',
        'file' => UploadedFile::fake()->create('nid.pdf', 120, 'application/pdf'),
        'expires_at' => '2030-01-01',
    ], $overrides));
}

it('lets HR upload a categorised document with an expiry', function () {
    uploadDoc()->assertRedirect();

    $doc = $this->employee->attachments()->first();
    expect($doc)->not->toBeNull();
    expect($doc->collection)->toBe('national_id');
    expect($doc->expires_at->toDateString())->toBe('2030-01-01');
    expect($doc->business_id)->toBe($this->business->id);
    expect($doc->uploaded_by)->toBe($this->owner->id);
    Storage::disk('local')->assertExists($doc->path);
});

it('keeps every uploaded document (documents are not a singleton collection)', function () {
    uploadDoc(['category' => 'national_id'])->assertRedirect();
    uploadDoc(['category' => 'national_id'])->assertRedirect();

    expect($this->employee->attachments()->count())->toBe(2);
});

it('lets HR remove a document, deleting the stored file too', function () {
    uploadDoc()->assertRedirect();
    $doc = $this->employee->attachments()->first();

    // Corrections wave 3 (24 Oct 2026): a reason is required now.
    docOwner()->delete(route('hrm.employees.documents.destroy', [$this->employee, $doc]))->assertSessionHasErrors('reason_text');
    expect(Attachment::find($doc->id))->not->toBeNull();

    docOwner()->delete(route('hrm.employees.documents.destroy', [$this->employee, $doc]), ['reason_text' => 'Scanned the wrong card'])->assertRedirect();

    // Corrections wave 3 (24 Oct 2026): a removed file goes to the Recycle bin; the stored file stays for a restore.
    expect(Attachment::find($doc->id))->toBeNull();
    Storage::disk('local')->assertExists($doc->path);
});

it('will not delete a document that belongs to another employee', function () {
    uploadDoc()->assertRedirect();
    $doc = $this->employee->attachments()->first();

    $other = app(CreateEmployee::class)->handle(
        ['first_name' => 'Rahim', 'last_name' => 'B', 'employment_type' => 'permanent'],
        ['office_id' => $this->office->id],
    );

    docOwner()->delete(route('hrm.employees.documents.destroy', [$other, $doc]))->assertNotFound();
    expect(Attachment::find($doc->id))->not->toBeNull();
});

it('rejects an invalid document category and a disallowed file type', function () {
    docOwner()->post(route('hrm.employees.documents.store', $this->employee), [
        'category' => 'not_a_type',
        'file' => UploadedFile::fake()->create('nid.pdf', 10, 'application/pdf'),
    ])->assertSessionHasErrors('category');

    docOwner()->post(route('hrm.employees.documents.store', $this->employee), [
        'category' => 'national_id',
        'file' => UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream'),
    ])->assertSessionHasErrors('file');
});

it('blocks a non-admin from uploading a document', function () {
    $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.employees.documents.store', $this->employee), [
            'category' => 'national_id',
            'file' => UploadedFile::fake()->create('nid.pdf', 10, 'application/pdf'),
        ])->assertForbidden();
});

it('serves an employee document to HR but forbids a non-HR colleague', function () {
    uploadDoc()->assertRedirect();
    $doc = $this->employee->attachments()->first();

    docOwner()->get($doc->url())->assertOk();

    $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->get($doc->url())->assertForbidden();
});

it('lets the employee view their own document through their linked user', function () {
    // A plain business member with no HR permission, linked to the employee.
    $selfUser = User::factory()->create();
    $selfUser->businesses()->attach($this->business->id);
    $this->employee->update(['user_id' => $selfUser->id]);

    uploadDoc()->assertRedirect();
    $doc = $this->employee->attachments()->first();

    $this->actingAs($selfUser)->withSession(['current_business_id' => $this->business->id])
        ->get($doc->url())->assertOk();
});
