<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;

/**
 * The pages people see when something has gone wrong.
 *
 * ⚠⚠ These are BLADE, not Inertia, on purpose: an error page has to render when
 * the application is broken, and an Inertia page needs the JavaScript bundle and
 * the shared props, which query the database for the current business and the
 * user's permissions. On the one page that exists to explain a database failure
 * that would throw while explaining the throw.
 *
 * ⚠ Rendering the view directly is what these assert. Provoking a real 500 in a
 * test means breaking the application on purpose, which proves the breakage and
 * not the page.
 */
it('renders every error page without needing the database or the asset build', function (string $view, string $code) {
    $html = view("errors.{$view}")->render();

    expect($html)->toContain($code)
        ->and($html)->toContain('Bor de Guna ERP')
        ->and($html)->toContain('Back to the dashboard')
        // ⚠ No asset from the build and no external font: if the deploy that
        // just went out is the problem, this page must still draw.
        ->and($html)->not->toContain('/build/')
        ->and($html)->not->toContain('fonts.googleapis')
        ->and($html)->not->toContain('bunny.net');
})->with([
    ['404', 'Error 404'],
    ['403', 'Error 403'],
    ['419', 'Error 419'],
    ['429', 'Error 429'],
    ['500', 'Error 500'],
    ['503', 'Error 503'],
]);

/**
 * ⛔ The brand is `Bor de Guna` - lowercase "de". A test rather than a memory,
 * because this is the file least likely to be looked at again.
 */
it('never writes the brand with a capital De', function (string $view) {
    expect(view("errors.{$view}")->render())->not->toContain('Bor De Guna');
})->with(['404', '403', '419', '429', '500', '503']);

/** ⛔ A 500 must never name a table, a column or a query to whoever clicked. */
it('says nothing technical on the five hundred', function () {
    $html = strtolower(view('errors.500')->render());

    foreach (['sqlstate', 'exception', 'stack', 'select ', 'mysql', 'vendor/'] as $leak) {
        expect($html)->not->toContain($leak);
    }
});

/**
 * ⚠ Laravel's own wording for a 419 is "Page Expired", which tells somebody who
 * left a tab open over lunch precisely nothing. It is a stale security token and
 * the fix is to sign in again.
 */
it('explains the expired session in words somebody can act on', function () {
    $html = view('errors.419')->render();

    expect($html)->toContain('session has expired')
        ->and($html)->toContain('Sign in again')
        ->and($html)->toContain('nothing you typed was wrong');
});

/**
 * ⚠⚠ AN UNKNOWN URL NEVER REACHES THE 404 PAGE, and that is a decision this
 * project made before these pages existed: `Route::fallback` in routes/web.php
 * sends a signed-in visitor to the dashboard and everybody else to the sign-in
 * page. Pinned here so nobody later adds a 404 page, tries a made-up URL, sees
 * the dashboard and concludes the page is broken.
 *
 * ⚠ The consequence is worth knowing rather than arguing about: the 404 view is
 * reached when a RECORD is missing - a stale link to a deleted invoice - not
 * when a URL is mistyped. It also means a broken link inside the application
 * lands somebody on the dashboard instead of showing up as a 404 in the logs.
 */
it('sends an unknown url to the dashboard rather than to the 404 page', function () {
    $business = Business::create(['name' => 'Error Co', 'slug' => 'error-co']);
    app(BusinessContext::class)->set($business->id);

    $user = User::factory()->create();
    $user->businesses()->attach($business->id);

    $this->actingAs($user)
        ->get('/this-route-was-never-defined')
        ->assertRedirect(route('dashboard'));
});

it('sends a signed-out visitor to the sign-in page', function () {
    $this->get('/this-route-was-never-defined')->assertRedirect(route('login'));
});
