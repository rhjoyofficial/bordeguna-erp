<?php

use App\Models\User;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\DocumentCategory;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The office document house (Joy, 30 August 2026).
 *
 * ⚠⚠ THIS MODULE ADDED NO NEW STORAGE AND NO NEW DOWNLOAD ROUTE, and most of
 * what follows exists to keep it that way. Files already live in `attachments`
 * on a private disk behind one authenticated, business-scoped route, and are
 * shown only to people FileVisibility says may see them. An archive with its own
 * store or its own streaming endpoint would be a second security surface doing
 * the same job, and the second one is always the one that is wrong.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->business = Business::create(['name' => 'Archive Co', 'slug' => 'archive-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['files.view', 'files.manage'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    Role::firstOrCreate(['name' => 'Keeper', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['files.view', 'files.manage']);

    $this->keeper = User::factory()->create();
    $this->keeper->businesses()->attach($this->business->id);
    $this->keeper->assignRole('Keeper');

    $this->asKeeper = fn () => $this->actingAs($this->keeper)
        ->withSession(['current_business_id' => $this->business->id]);
});

it('files a document with the metadata somebody will search by', function () {
    // ⚠⚠ A FILENAME IS NOT A TITLE. Somebody looking for the trade licence
    // types "licence", not "scan_0012.pdf". The whole point of the metadata is
    // that the document can be found again by a person who did not upload it.
    $category = DocumentCategory::create([
        'business_id' => $this->business->id, 'name' => 'Licences', 'slug' => 'licences',
    ]);

    ($this->asKeeper)()->post(route('files.store'), [
        'file' => UploadedFile::fake()->create('scan_0012.pdf', 40, 'application/pdf'),
        'title' => 'Trade licence 2026',
        'reference' => 'TL-2026-0099',
        'description' => 'Barguna Sadar municipality',
        'document_date' => '2026-01-15',
        'document_category_id' => $category->id,
        'expires_on' => '2027-01-14',
    ])->assertRedirect();

    $document = Attachment::query()->latest('id')->sole();

    expect($document->title)->toBe('Trade licence 2026')
        ->and($document->reference)->toBe('TL-2026-0099')
        ->and($document->document_category_id)->toBe($category->id)
        ->and($document->document_date?->toDateString())->toBe('2026-01-15')
        ->and($document->expires_at?->toDateString())->toBe('2027-01-14')
        // ⚠ The original filename is KEPT as well. It is what the file will be
        // called when somebody downloads it, and losing it would rename a
        // document that other people may already hold a copy of.
        ->and($document->original_name)->toBe('scan_0012.pdf');
});

it('finds a document by its title and its reference, not only its filename', function () {
    ($this->asKeeper)()->post(route('files.store'), [
        'file' => UploadedFile::fake()->create('scan_0012.pdf', 20, 'application/pdf'),
        'title' => 'Trade licence 2026',
        'reference' => 'TL-2026-0099',
    ]);

    foreach (['licence', 'TL-2026', 'scan_0012'] as $term) {
        ($this->asKeeper)()->get(route('files.index', ['search' => $term]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('files.data', 1));
    }

    // And a term that matches nothing still returns nothing, so the search is
    // actually filtering rather than ignoring the input.
    ($this->asKeeper)()->get(route('files.index', ['search' => 'nothing-like-this']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('files.data', 0));
});

it('tells the screen how each document can be shown, from its mime type', function () {
    // ⚠⚠ FROM THE MIME, NEVER THE EXTENSION. The extension is whatever the
    // uploader typed. Deciding to render something as a video because it ends
    // in .mp4 is how a preview becomes a way to make the browser do something
    // nobody intended.
    ($this->asKeeper)()->post(route('files.store'), [
        'file' => UploadedFile::fake()->create('clip.mp4', 60, 'video/mp4'),
        'title' => 'Factory walkthrough',
    ])->assertRedirect();

    ($this->asKeeper)()->get(route('files.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('files.data.0.previewable', 'video'));
});

it('accepts video, and still refuses a program', function () {
    // Joy asked for video. The allow-list is what stops the same box accepting
    // an executable somebody was talked into uploading.
    ($this->asKeeper)()->post(route('files.store'), [
        'file' => UploadedFile::fake()->create('walkthrough.mp4', 100, 'video/mp4'),
    ])->assertRedirect()->assertSessionHasNoErrors();

    ($this->asKeeper)()->post(route('files.store'), [
        'file' => UploadedFile::fake()->create('payload.exe', 10, 'application/x-msdownload'),
    ])->assertSessionHasErrors('file');
});

it('hides a replaced version until somebody asks for the history', function () {
    // ⚠⚠ A superseded document is kept, never deleted: the version somebody
    // acted on last year must still be producible. It is simply not the one the
    // archive shows by default.
    ($this->asKeeper)()->post(route('files.store'), [
        'file' => UploadedFile::fake()->create('licence-2025.pdf', 20, 'application/pdf'),
        'title' => 'Trade licence 2025',
    ]);
    ($this->asKeeper)()->post(route('files.store'), [
        'file' => UploadedFile::fake()->create('licence-2026.pdf', 20, 'application/pdf'),
        'title' => 'Trade licence 2026',
    ]);

    $documents = Attachment::query()->orderBy('id')->get();
    $documents->first()->update(['superseded_by_id' => $documents->last()->id]);

    ($this->asKeeper)()->get(route('files.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('files.data', 1));

    ($this->asKeeper)()->get(route('files.index', ['superseded' => '1']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('files.data', 2));

    // ⛔ And it is still there. Hidden is not deleted.
    expect(Attachment::query()->count())->toBe(2);
});

it('counts what is expiring across the whole archive, not just this page', function () {
    // ⚠ A licence expiring next month is not less urgent because it happens to
    // be on page three.
    ($this->asKeeper)()->post(route('files.store'), [
        'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        'expires_on' => now()->addDays(20)->toDateString(),
    ]);
    ($this->asKeeper)()->post(route('files.store'), [
        'file' => UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'),
        'expires_on' => now()->subDays(5)->toDateString(),
    ]);

    ($this->asKeeper)()->get(route('files.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('expiring_count', 1)
            ->where('expired_count', 1)
        );
});

it('treats a category with no retention as keep indefinitely', function () {
    // ⚠⚠ Silence in an archive must never read as permission to destroy.
    $forever = DocumentCategory::create([
        'business_id' => $this->business->id, 'name' => 'Contracts', 'slug' => 'contracts',
    ]);
    $limited = DocumentCategory::create([
        'business_id' => $this->business->id, 'name' => 'Delivery photos', 'slug' => 'delivery-photos',
        'retention_months' => 12,
    ]);

    expect($forever->keepsIndefinitely())->toBeTrue()
        ->and($limited->keepsIndefinitely())->toBeFalse();
});

it('refuses a second category with the same name', function () {
    ($this->asKeeper)()->post(route('files.categories.store'), ['name' => 'Licences'])->assertRedirect();
    ($this->asKeeper)()->post(route('files.categories.store'), ['name' => 'Licences'])
        ->assertSessionHasErrors('name');

    expect(DocumentCategory::query()->count())->toBe(1);
});

it('will not let somebody without the permission file anything', function () {
    $outsider = User::factory()->create();
    $outsider->businesses()->attach($this->business->id);

    $this->actingAs($outsider)->withSession(['current_business_id' => $this->business->id])
        ->post(route('files.store'), ['file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
        ->assertForbidden();
});

it('serves a document inline for preview and as a download only when asked', function () {
    // ⚠⚠ The preview and the download are the SAME route. A preview that needed
    // its own endpoint would be a second way to read a file, and the second one
    // is the one that forgets to check who is asking.
    ($this->asKeeper)()->post(route('files.store'), [
        'file' => UploadedFile::fake()->create('licence.pdf', 20, 'application/pdf'),
    ]);

    $document = Attachment::query()->sole();

    $inline = ($this->asKeeper)()->get(route('attachments.show', $document));
    $inline->assertOk();
    expect($inline->headers->get('content-disposition'))->toStartWith('inline');

    $download = ($this->asKeeper)()->get(route('attachments.show', $document).'?download=1');
    $download->assertOk();
    expect($download->headers->get('content-disposition'))->toStartWith('attachment');
});
