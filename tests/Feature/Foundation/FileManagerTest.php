<?php

use App\Models\User;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\DocumentFolder;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\FileVisibility;
use App\Modules\Hrm\Models\ExpenseClaim;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The file manager.
 *
 * ⚠⚠ EVERY FILE IN THIS SYSTEM ALREADY LIVES IN ONE TABLE: an employee's
 * documents, the evidence behind an attendance correction, an expense claim
 * receipt, a supplier bill scan. A browser over that table with a single
 * permission would hand anybody who can open it every payroll-adjacent document
 * in the business, in one screen, sorted by date. That is the obvious way to
 * build this page, and it is what these tests exist to prevent.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->business = Business::create(['name' => 'File Co', 'slug' => 'file-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['files.view', 'files.manage', 'hrm.payroll.manage', 'foundation.organization.manage'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['files.view', 'files.manage', 'hrm.payroll.manage', 'foundation.organization.manage']);

    // ⚠ Holds the file manager and nothing else. Every leak test below is about
    // this reader.
    Role::firstOrCreate(['name' => 'Staff', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['files.view']);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');
});

function libraryFile(int $businessId, int $uploader, string $name = 'trade-licence.pdf', ?string $expires = null): Attachment
{
    return Attachment::create([
        'business_id' => $businessId,
        'attachable_type' => Business::class,
        'attachable_id' => $businessId,
        'collection' => FileVisibility::LIBRARY,
        'disk' => 'local', 'path' => 'attachments/'.$businessId.'/'.$name,
        'original_name' => $name, 'mime_type' => 'application/pdf', 'size' => 2048,
        'expires_at' => $expires, 'uploaded_by' => $uploader,
    ]);
}

function claimReceipt(int $businessId, int $uploader): Attachment
{
    return Attachment::create([
        'business_id' => $businessId,
        'attachable_type' => ExpenseClaim::class,
        'attachable_id' => 1,
        'collection' => 'receipts',
        'disk' => 'local', 'path' => 'attachments/'.$businessId.'/receipt.pdf',
        'original_name' => 'receipt.pdf', 'mime_type' => 'application/pdf', 'size' => 1024,
        'uploaded_by' => $uploader,
    ]);
}

it('shows the library to anybody who may open the page', function () {
    libraryFile($this->business->id, $this->owner->id);

    foreach ([$this->owner, $this->staff] as $user) {
        $this->actingAs($user)->get('/files')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('files/index')->where('files.total', 1));
    }
});

it('never shows a payroll document to somebody who may not open payroll', function () {
    libraryFile($this->business->id, $this->owner->id);
    claimReceipt($this->business->id, $this->owner->id);

    // The owner holds hrm.payroll.manage, so they see both.
    $this->actingAs($this->owner)->get('/files')
        ->assertInertia(fn ($page) => $page->where('files.total', 2));

    // ⚠⚠ The whole point of FileVisibility. A file is visible only to somebody
    // who could already open the record it hangs on.
    $this->actingAs($this->staff)->get('/files')
        ->assertInertia(fn ($page) => $page->where('files.total', 1)->where('files.data.0.name', 'trade-licence.pdf'));
});

it('hides a file type nobody has mapped, rather than showing it by default', function () {
    Attachment::create([
        'business_id' => $this->business->id,
        // A module that starts attaching files without anybody updating
        // FileVisibility.
        'attachable_type' => 'App\\Modules\\Future\\Models\\SomethingNew',
        'attachable_id' => 7,
        'disk' => 'local', 'path' => 'attachments/x.pdf', 'original_name' => 'secret.pdf', 'size' => 10,
        'uploaded_by' => $this->owner->id,
    ]);

    // ⚠⚠ THE DEFAULT IS CLOSED. Organisation management can see it, so it is
    // never lost; everybody else is not shown a document nothing has decided
    // they may read.
    $this->actingAs($this->staff)->get('/files')
        ->assertInertia(fn ($page) => $page->where('files.total', 0));

    $this->actingAs($this->owner)->get('/files')
        ->assertInertia(fn ($page) => $page->where('files.total', 1));
});

it('offers only the record kinds this reader is allowed to know about', function () {
    // ⚠ A filter naming "Expense Claim" to somebody who may not read one has
    // already told them expense claims with documents exist.
    $this->actingAs($this->staff)->get('/files')
        ->assertInertia(fn ($page) => $page->where('types', fn ($types) => collect($types)->pluck('value')->contains(Business::class)
            && ! collect($types)->pluck('value')->contains(ExpenseClaim::class)));

    $this->actingAs($this->owner)->get('/files')
        ->assertInertia(fn ($page) => $page->where('types', fn ($types) => collect($types)->pluck('value')->contains(ExpenseClaim::class)));
});

it('refuses a type filter the reader is not entitled to, rather than honouring it', function () {
    claimReceipt($this->business->id, $this->owner->id);
    libraryFile($this->business->id, $this->owner->id);

    // ⚠ Hand-typing the filter into the address bar must not widen what is
    // returned. The visibility clause still applies underneath it.
    $this->actingAs($this->staff)->get('/files?type='.urlencode(ExpenseClaim::class))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('files.total', 1)->where('files.data.0.name', 'trade-licence.pdf'));
});

it('adds a file to the library, attached to the business itself', function () {
    $this->actingAs($this->owner)->post(route('files.store'), [
        'file' => UploadedFile::fake()->create('licence.pdf', 20, 'application/pdf'),
    ])->assertRedirect();

    $file = Attachment::first();

    // ⚠ No new table and no new column: a library file is the shape
    // StoreAttachment already understood.
    expect($file->attachable_type)->toBe(Business::class)
        ->and($file->collection)->toBe(FileVisibility::LIBRARY)
        ->and($file->original_name)->toBe('licence.pdf');

    Storage::disk('local')->assertExists($file->path);
});

it('refuses an executable however it is dressed up', function () {
    // ⚠⚠ This is a general upload box reachable by most of the team. An
    // allow-list of extensions, not a size limit alone.
    $this->actingAs($this->owner)->post(route('files.store'), [
        'file' => UploadedFile::fake()->create('payload.exe', 10, 'application/octet-stream'),
    ])->assertSessionHasErrors('file');

    expect(Attachment::count())->toBe(0);
});

it('deletes a library file and removes it from the disk', function () {
    $this->actingAs($this->owner)->post(route('files.store'), [
        'file' => UploadedFile::fake()->create('old.pdf', 10, 'application/pdf'),
    ]);

    $file = Attachment::first();
    $path = $file->path;

    $this->actingAs($this->owner)->delete(route('files.destroy', $file->id))->assertRedirect();

    expect(Attachment::count())->toBe(0);
    Storage::disk('local')->assertMissing($path);
});

it('refuses to delete evidence attached to a record, even for the owner', function () {
    $receipt = claimReceipt($this->business->id, $this->owner->id);

    // ⚠⚠ An expense claim receipt, a payroll adjustment's proof and an
    // attendance correction's evidence are EVIDENCE. A generic file browser
    // with a delete button is how evidence disappears with nobody deciding it
    // should. Removing one stays with the module that owns it.
    $this->actingAs($this->owner)->delete(route('files.destroy', $receipt->id))->assertForbidden();

    expect(Attachment::count())->toBe(1);
});

it('tells the screen a record file cannot be deleted, so no button is drawn', function () {
    claimReceipt($this->business->id, $this->owner->id);
    libraryFile($this->business->id, $this->owner->id);

    $this->actingAs($this->owner)->get('/files')
        ->assertInertia(fn ($page) => $page->where('files.data', fn ($rows) => collect($rows)
            ->every(fn ($row) => $row['can_delete'] === ($row['kind'] === 'Library'))));
});

it('never shows one business the files of another', function () {
    libraryFile($this->business->id, $this->owner->id, 'ours.pdf');

    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-file-co']);
    Attachment::withoutGlobalScopes()->create([
        'business_id' => $other->id, 'attachable_type' => Business::class, 'attachable_id' => $other->id,
        'collection' => FileVisibility::LIBRARY, 'disk' => 'local', 'path' => 'x', 'original_name' => 'theirs.pdf', 'size' => 1,
    ]);

    $this->actingAs($this->owner)->get('/files')
        ->assertInertia(fn ($page) => $page->where('files.total', 1)->where('files.data.0.name', 'ours.pdf'));
});

it('marks a file that has passed its date as out of date rather than hiding it', function () {
    libraryFile($this->business->id, $this->owner->id, 'expired-licence.pdf', now()->subDay()->toDateString());

    // ⚠ Still listed. A trade licence that lapsed is exactly the file somebody
    // needs to find, and hiding it is how nobody notices it lapsed.
    $this->actingAs($this->owner)->get('/files')
        ->assertInertia(fn ($page) => $page->where('files.total', 1)->where('files.data.0.expired', true));
});

it('refuses the page to a login that holds no files permission', function () {
    $nobody = User::factory()->create();
    $nobody->businesses()->attach($this->business->id);

    $this->actingAs($nobody)->get('/files')->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Folders — the Google-Drive-style tree over the library (28 Sep 2026)
|--------------------------------------------------------------------------
| ⚠ Folders organise; they never decide who may see a file. That stays with
| FileVisibility, exercised by the leak tests above.
*/
function asFileOwner()
{
    return test()->actingAs(test()->owner)->withSession(['current_business_id' => test()->business->id]);
}

it('creates folders and shows the ones at the level being browsed', function () {
    asFileOwner()->post(route('files.folders.store'), ['name' => 'Licences'])->assertSessionHasNoErrors();
    $licences = DocumentFolder::sole();
    asFileOwner()->post(route('files.folders.store'), ['name' => '2026', 'parent_id' => $licences->id])->assertSessionHasNoErrors();

    // The root shows the top-level folder, not the nested one.
    asFileOwner()->get(route('files.index'))
        ->assertInertia(fn ($page) => $page
            ->where('browsing', true)
            ->where('current_folder', null)
            ->has('folders', 1)
            ->where('folders.0.name', 'Licences')
            ->where('folders.0.subfolder_count', 1));

    // Opening it shows the nested folder and the breadcrumb.
    asFileOwner()->get(route('files.index', ['folder' => $licences->id]))
        ->assertInertia(fn ($page) => $page
            ->where('current_folder.name', 'Licences')
            ->has('folders', 1)
            ->where('folders.0.name', '2026')
            ->has('breadcrumb', 1)
            ->where('breadcrumb.0.name', 'Licences'));
});

it('refuses a duplicate folder name under the same parent', function () {
    asFileOwner()->post(route('files.folders.store'), ['name' => 'Contracts'])->assertSessionHasNoErrors();
    asFileOwner()->post(route('files.folders.store'), ['name' => 'Contracts'])->assertSessionHasErrors('name');
    expect(DocumentFolder::where('name', 'Contracts')->count())->toBe(1);
});

it('moves a library file into a folder, and it leaves the root for that folder', function () {
    $folder = DocumentFolder::create(['business_id' => $this->business->id, 'name' => 'Licences']);
    $file = libraryFile($this->business->id, $this->owner->id);

    asFileOwner()->patch(route('files.update', $file), ['folder_id' => $folder->id])->assertSessionHasNoErrors();
    expect($file->fresh()->folder_id)->toBe($folder->id);

    // Gone from the root...
    asFileOwner()->get(route('files.index'))
        ->assertInertia(fn ($page) => $page->where('files.data', fn ($files) => collect($files)->isEmpty()));

    // ...and present in the folder.
    asFileOwner()->get(route('files.index', ['folder' => $folder->id]))
        ->assertInertia(fn ($page) => $page
            ->where('files.data', fn ($files) => collect($files)->pluck('id')->contains($file->id)));
});

it('renames a file without disturbing fields the request did not send', function () {
    $file = libraryFile($this->business->id, $this->owner->id);
    $file->update(['title' => 'Trade licence 2026', 'reference' => 'TL-99']);

    // A move sends only folder_id; the title and reference must survive.
    $folder = DocumentFolder::create(['business_id' => $this->business->id, 'name' => 'Licences']);
    asFileOwner()->patch(route('files.update', $file), ['folder_id' => $folder->id])->assertSessionHasNoErrors();

    expect($file->fresh()->title)->toBe('Trade licence 2026')
        ->and($file->fresh()->reference)->toBe('TL-99')
        ->and($file->fresh()->folder_id)->toBe($folder->id);
});

it('deletes an empty folder but refuses one that still holds a file', function () {
    $folder = DocumentFolder::create(['business_id' => $this->business->id, 'name' => 'Licences']);
    $file = libraryFile($this->business->id, $this->owner->id);
    $file->update(['folder_id' => $folder->id]);

    asFileOwner()->delete(route('files.folders.destroy', $folder))->assertSessionHas('error');
    expect(DocumentFolder::find($folder->id))->not->toBeNull();

    // Empty it, then it deletes.
    $file->update(['folder_id' => null]);
    asFileOwner()->delete(route('files.folders.destroy', $folder))->assertSessionHas('success');
    expect(DocumentFolder::find($folder->id))->toBeNull();
});

it('will not move a folder inside its own subtree', function () {
    $parent = DocumentFolder::create(['business_id' => $this->business->id, 'name' => 'Parent']);
    $child = DocumentFolder::create(['business_id' => $this->business->id, 'name' => 'Child', 'parent_id' => $parent->id]);

    asFileOwner()->patch(route('files.folders.update', $parent), ['parent_id' => $child->id])
        ->assertSessionHasErrors('parent_id');
    expect($parent->fresh()->parent_id)->toBeNull();
});

it('keeps folders out of the reach of files.view only', function () {
    $folder = DocumentFolder::create(['business_id' => $this->business->id, 'name' => 'Licences']);

    test()->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->post(route('files.folders.store'), ['name' => 'Nope'])->assertForbidden();
    test()->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->delete(route('files.folders.destroy', $folder))->assertForbidden();
    test()->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->patch(route('files.update', libraryFile($this->business->id, $this->owner->id)), ['title' => 'x'])->assertForbidden();
});
