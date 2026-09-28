<?php

use App\Models\User;
use App\Modules\Foundation\Http\Middleware\PreventDuplicateSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The server-side double-submit guard. Joy, 28 Sep 2026: "until request complete,
 * resubmit not be possible." These exercise the middleware directly, because the
 * thing under test is CONCURRENCY - a second identical write arriving while the
 * first is still in flight - which sequential HTTP requests in a test cannot
 * reproduce (the guard releases the moment the first finishes). Calling handle()
 * with a duplicate raised from INSIDE the first's $next is exactly that overlap.
 */
function fakeUser(int $id): User
{
    return (new User)->forceFill(['id' => $id]);
}

function postRequest(string $path, array $body, ?int $userId = 1, bool $json = false): Request
{
    $request = Request::create($path, 'POST', $body);
    if ($json) {
        $request->headers->set('Accept', 'application/json');
    }
    if ($userId !== null) {
        $request->setUserResolver(fn () => fakeUser($userId));
    }

    return $request;
}

it('lets a single write through and releases the key when it finishes', function () {
    $middleware = new PreventDuplicateSubmission;
    $calls = 0;
    $next = function () use (&$calls) {
        $calls++;

        return new Response('ok');
    };

    // Two sequential identical writes both pass: the first released its key on
    // finishing, so the second is a fresh, legitimate attempt - not a duplicate.
    $middleware->handle(postRequest('/leave', ['dates' => '2026-09-01']), $next);
    $middleware->handle(postRequest('/leave', ['dates' => '2026-09-01']), $next);

    expect($calls)->toBe(2);
});

it('refuses an identical write while the first is still in flight', function () {
    $middleware = new PreventDuplicateSubmission;
    $duplicateReachedHandler = false;
    $blocked = null;

    $middleware->handle(postRequest('/leave', ['dates' => '2026-09-01']), function () use ($middleware, &$duplicateReachedHandler, &$blocked) {
        // A concurrent duplicate arrives before the first has finished.
        $blocked = $middleware->handle(
            postRequest('/leave', ['dates' => '2026-09-01'], json: true),
            function () use (&$duplicateReachedHandler) {
                $duplicateReachedHandler = true;

                return new Response('should never run');
            },
        );

        return new Response('first ok');
    });

    expect($duplicateReachedHandler)->toBeFalse()
        ->and($blocked->getStatusCode())->toBe(Response::HTTP_CONFLICT);
});

it('allows two genuinely different writes from the same user at the same time', function () {
    $middleware = new PreventDuplicateSubmission;
    $inner = 0;

    $middleware->handle(postRequest('/leave', ['dates' => '2026-09-01']), function () use ($middleware, &$inner) {
        // Different payload -> different fingerprint -> never mistaken for the
        // first. Approving one request must not block approving another.
        $middleware->handle(postRequest('/leave', ['dates' => '2026-09-02']), function () use (&$inner) {
            $inner++;

            return new Response('ok');
        });

        return new Response('ok');
    });

    expect($inner)->toBe(1);
});

it('does not confuse two different users sending the same write', function () {
    $middleware = new PreventDuplicateSubmission;
    $inner = 0;

    $middleware->handle(postRequest('/leave', ['dates' => '2026-09-01'], userId: 1), function () use ($middleware, &$inner) {
        $middleware->handle(postRequest('/leave', ['dates' => '2026-09-01'], userId: 2), function () use (&$inner) {
            $inner++;

            return new Response('ok');
        });

        return new Response('ok');
    });

    expect($inner)->toBe(1);
});

it('never blocks a safe GET, even overlapping', function () {
    $middleware = new PreventDuplicateSubmission;
    $inner = 0;

    $get = Request::create('/leave', 'GET');
    $get->setUserResolver(fn () => fakeUser(1));

    $middleware->handle($get, function () use ($middleware, &$inner) {
        $overlap = Request::create('/leave', 'GET');
        $overlap->setUserResolver(fn () => fakeUser(1));
        $middleware->handle($overlap, function () use (&$inner) {
            $inner++;

            return new Response('ok');
        });

        return new Response('ok');
    });

    expect($inner)->toBe(1);
});

it('leaves guests alone', function () {
    $middleware = new PreventDuplicateSubmission;
    $inner = 0;

    // No user resolver: a gateway callback or the login post itself is not ours
    // to de-duplicate here.
    $guest = Request::create('/payments/sslcommerz/ipn', 'POST', ['x' => 1]);

    $middleware->handle($guest, function () use ($middleware, &$inner) {
        $overlap = Request::create('/payments/sslcommerz/ipn', 'POST', ['x' => 1]);
        $middleware->handle($overlap, function () use (&$inner) {
            $inner++;

            return new Response('ok');
        });

        return new Response('ok');
    });

    expect($inner)->toBe(1);
});
