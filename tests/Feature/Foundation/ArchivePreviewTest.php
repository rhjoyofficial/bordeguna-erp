<?php

use App\Models\User;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\FileVisibility;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Every kind of file in the document archive can be looked at in the page
 * (Joy, 12 Oct 2026).
 *
 * ⚠⚠ THE PREVIEW IS DRAWN IN THE PAGE, SO THE STREAM MUST NEVER BE THE THING
 * THAT RUNS. Text, markdown, sheets and Word files are read with fetch() and
 * drawn by the screen; the stream itself only ever hands back bytes under a
 * type a browser will not execute. These tests hold both halves: what the box
 * accepts, and what the stream declares.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->business = Business::create(['name' => 'Preview Co', 'slug' => 'preview-co']);
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

function archiveRow(int $businessId, int $uploader, string $name, ?string $mime, string $body = 'hello'): Attachment
{
    $path = 'attachments/'.$businessId.'/'.uniqid().'-'.$name;
    Storage::disk('local')->put($path, $body);

    return Attachment::create([
        'business_id' => $businessId,
        'attachable_type' => Business::class,
        'attachable_id' => $businessId,
        'collection' => FileVisibility::LIBRARY,
        'disk' => 'local', 'path' => $path,
        'original_name' => $name, 'mime_type' => $mime, 'size' => strlen($body),
        'uploaded_by' => $uploader,
    ]);
}

it('accepts markdown, json, xml and log files', function (string $name, string $body) {
    ($this->asKeeper)()->post(route('files.store'), [
        'file' => UploadedFile::fake()->createWithContent($name, $body),
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(Attachment::query()->sole()->original_name)->toBe($name);
})->with([
    'markdown' => ['notes.md', "# Notes\n\nPlain words."],
    'markdown long name' => ['notes.markdown', "# Notes\n"],
    'json' => ['export.json', '{"a": 1}'],
    'xml' => ['feed.xml', '<?xml version="1.0"?><feed><item>1</item></feed>'],
    'log' => ['server.log', "line one\nline two\n"],
]);

it('still refuses html, svg and programs however they are named', function (string $name, string $body, ?string $mime) {
    // ⛔ html and svg are documents a browser runs script in. This box files
    // things other people will open.
    $file = $mime === null
        ? UploadedFile::fake()->createWithContent($name, $body)
        : UploadedFile::fake()->create($name, 10, $mime);

    ($this->asKeeper)()->post(route('files.store'), ['file' => $file])
        ->assertSessionHasErrors('file');

    expect(Attachment::count())->toBe(0);
})->with([
    'html' => ['page.html', '<html><body><script>alert(1)</script></body></html>', null],
    'plain text dressed as html' => ['page.html', 'just words', null],
    'svg' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', null],
    'exe' => ['payload.exe', '', 'application/x-msdownload'],
    'exe renamed by content type' => ['payload.exe', '', 'text/plain'],
]);

it('tells the screen which reader draws each kind of file', function (string $name, ?string $mime, ?string $kind) {
    archiveRow($this->business->id, $this->keeper->id, $name, $mime);

    ($this->asKeeper)()->get(route('files.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('files.data.0.previewable', $kind));
})->with([
    'png' => ['photo.png', 'image/png', 'image'],
    'pdf' => ['licence.pdf', 'application/pdf', 'pdf'],
    'mp4' => ['clip.mp4', 'video/mp4', 'video'],
    'mp3' => ['memo.mp3', 'audio/mpeg', 'audio'],
    'txt' => ['notes.txt', 'text/plain', 'text'],
    'log' => ['server.log', 'text/plain', 'text'],
    'json' => ['export.json', 'application/json', 'text'],
    'xml' => ['feed.xml', 'text/xml', 'text'],
    'md' => ['readme.md', 'text/markdown', 'markdown'],
    'markdown' => ['readme.markdown', null, 'markdown'],
    'csv sent as excel by windows' => ['ledger.csv', 'application/vnd.ms-excel', 'sheet'],
    'xls' => ['old.xls', 'application/vnd.ms-excel', 'sheet'],
    'xlsx' => ['stock.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'sheet'],
    'docx' => ['letter.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'docx'],
    'pptx' => ['deck.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'pptx'],
    'doc' => ['old.doc', 'application/msword', 'doc'],
    'ppt' => ['old.ppt', 'application/vnd.ms-powerpoint', 'ppt'],
    'zip' => ['bundle.zip', 'application/zip', 'zip'],
    'no extension, plain text' => ['README', 'text/plain', 'text'],
    'unknown' => ['mystery.bin', 'application/octet-stream', null],
]);

it('streams a preview inline, as its own type, unsniffable and never kept', function () {
    $file = archiveRow($this->business->id, $this->keeper->id, 'export.json', 'application/json', '{"a":1}');

    $response = ($this->asKeeper)()->get(route('attachments.show', $file))->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('application/json')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toBe('sandbox')
        ->and($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Cache-Control'))->not->toContain('public');
});

it('serves a pdf without the sandbox, so the browser viewer can draw it in the frame', function () {
    $file = archiveRow($this->business->id, $this->keeper->id, 'licence.pdf', 'application/pdf', '%PDF-1.4');

    $response = ($this->asKeeper)()->get(route('attachments.show', $file))->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->has('Content-Security-Policy'))->toBeFalse();
});

it('never serves a claimed active type as itself', function (string $claimed) {
    // ⚠⚠ The stored mime is what the uploader's browser claimed. A text file
    // that claimed text/html must not come back as HTML on this origin.
    $file = archiveRow($this->business->id, $this->keeper->id, 'notes.txt', $claimed, '<script>alert(1)</script>');

    $response = ($this->asKeeper)()->get(route('attachments.show', $file))->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/plain')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
})->with([
    'text/html', 'application/xhtml+xml', 'image/svg+xml', 'text/xml',
    'application/xml', 'text/javascript', 'application/javascript', 'text/css',
]);

it('keeps the declared type of an office document', function () {
    $type = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    $file = archiveRow($this->business->id, $this->keeper->id, 'letter.docx', $type, 'PK');

    expect(($this->asKeeper)()->get(route('attachments.show', $file))->headers->get('Content-Type'))->toBe($type);
});
