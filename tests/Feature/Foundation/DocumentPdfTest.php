<?php

use App\Models\User;
use App\Modules\Foundation\database\seeders\FoundationPermissionsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\DocumentPdf;
use App\Modules\Foundation\Support\DocumentRenderToken;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Download a document as a PDF (Joy, 8 September 2026).
 *
 * ⭐⭐ THE DESIGN IN ONE LINE: a headless browser is pointed at the very page a
 * person opens, so there is no second set of PDF templates that can drift out of
 * step with the screen. dompdf was measured and rejected - it writes Bengali
 * unshaped, and every customer-facing document here can carry a Bengali name.
 *
 * ⚠ These tests do NOT start a browser. What they pin is everything around it:
 * who the render is allowed to run as, that a token buys exactly one document
 * once, and that a page the browser could not read never comes back as a PDF.
 * The browser itself was proved separately by rendering "ক্ষ কি" and reading the
 * glyphs out of the PDF: four glyphs for six codepoints, one of them a conjunct.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Paper Co', 'slug' => 'paper-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }
    (new FoundationPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->asOwner = fn () => $this->actingAs($this->owner)
        ->withHeader('X-Business-Id', (string) $this->business->id);
});

it('mints a token for one user, one document and one use', function () {
    $token = DocumentRenderToken::mint($this->owner->id, $this->business->id, 'sales/invoices/7/print');

    $first = DocumentRenderToken::claim($token, 'sales/invoices/7/print');
    expect($first)->not->toBeNull()
        ->and($first['user_id'])->toBe($this->owner->id);

    // ⚠⚠ THE SECOND CLAIM MUST FAIL. A token that reaches a log or a process
    // list has to be spent by the time anybody reads it.
    expect(DocumentRenderToken::claim($token, 'sales/invoices/7/print'))->toBeNull();
});

it('refuses a token minted for a different document', function () {
    // ⛔ The commonest shape of this mistake: a token for a payslip fetching an
    // invoice. The path is part of what is claimed, not decoration on it.
    $token = DocumentRenderToken::mint($this->owner->id, $this->business->id, 'sales/invoices/7/print');

    expect(DocumentRenderToken::claim($token, 'hrm/payslips/7'))->toBeNull()
        // And refusing it must not have spent it, or a wrong guess would be a
        // way to destroy somebody else's render.
        ->and(DocumentRenderToken::claim($token, 'sales/invoices/7/print'))->not->toBeNull();
});

it('leaves a request with no render token completely alone', function () {
    Route::middleware('web')->get('/test-untouched', fn () => response('anonymous'));

    // ⚠ This is an authentication path in the web group. It has to be inert
    // unless a token is actually present.
    $this->get('/test-untouched')->assertOk()->assertSee('anonymous');
    expect(auth()->check())->toBeFalse();
});

it('refuses an invalid render token rather than redirecting to the login page', function () {
    Route::middleware(['web', 'auth'])->get('/test-guarded', fn () => response('secret'));

    // ⛔ A browser sent to a login form renders it, and the caller receives a
    // beautifully typeset PDF of a sign-in screen.
    $this->get('/test-guarded?render_token=not-a-real-token')->assertForbidden();
});

it('authenticates the render as the person who asked for it', function () {
    Route::middleware(['web', 'auth'])->get('/test-guarded', fn () => response(auth()->user()->email));

    $token = DocumentRenderToken::mint($this->owner->id, $this->business->id, 'test-guarded');

    $this->get('/test-guarded?render_token='.$token)
        ->assertOk()
        ->assertSee($this->owner->email);
});

it('records how the render actually ended, so a refused page is not sold as a PDF', function () {
    Route::middleware(['web', 'auth'])->get('/test-refused', fn () => abort(403));

    $token = DocumentRenderToken::mint($this->owner->id, $this->business->id, 'test-refused');
    $this->get('/test-refused?render_token='.$token)->assertForbidden();

    expect(DocumentRenderToken::outcome($token))->toBe(403);
});

it('does not know how to render a document type nobody whitelisted', function () {
    // ⛔ Without the whitelist this route is a server-side fetcher pointed at
    // any URL by whoever calls it.
    ($this->asOwner)()->get('/documents/payslip/1/pdf')->assertNotFound();

    // ⚠ A path-traversal attempt does NOT reach the whitelist: Symfony
    // normalises the encoded separator and redirects before routing. The
    // invariant worth pinning is that it never succeeds, not which of the two
    // safe answers the framework happens to give.
    expect(($this->asOwner)()->get('/documents/..%2Fetc%2Fpasswd/1/pdf')->status())
        ->not->toBe(200);
});

it('says so plainly when the server has no browser installed', function () {
    $this->app->instance(DocumentPdf::class, new class extends DocumentPdf
    {
        public function isAvailable(): bool
        {
            return false;
        }
    });

    // ⚠ 501 and a sentence, not Laravel's 503 maintenance page. A reader told
    // "the system is being updated" would go away; this one is told the truth
    // and that Print still works.
    ($this->asOwner)()->get('/documents/invoice/1/pdf')
        ->assertStatus(501)
        ->assertSee('Print', false);
});

it('keeps the download behind a login', function () {
    $this->get('/documents/invoice/1/pdf')->assertRedirect('/login');
});
