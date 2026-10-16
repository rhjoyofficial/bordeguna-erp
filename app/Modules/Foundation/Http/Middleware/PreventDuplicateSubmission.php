<?php

namespace App\Modules\Foundation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * The server-side half of the double-submit guard (Joy, 28 Sep 2026: "until
 * request complete, resubmit not be possible ... the same request [must not] be
 * done twice").
 *
 * app.tsx already drops a duplicate write in the browser, but a guard that lives
 * only in the front end is one a slow network, a second tab, a refresh mid-post
 * or a non-browser caller walks straight past. This is the standard idempotency
 * pattern applied at the one place every mutating request passes through: while
 * an identical write from the same user is still being processed, a second copy
 * is refused, and the moment the first finishes the guard lets go. It is a
 * WHILE-IN-FLIGHT lock, deliberately not a cooldown - a legitimate, genuinely
 * different second attempt a moment later must never be refused.
 *
 * "Identical" means same user, same verb, same path, same fields. Approving two
 * DIFFERENT requests in quick succession hashes differently and is untouched;
 * only the same request sent twice collides. This is the same reasoning as
 * EnsurePageBusinessMatches: a global guard nobody has to remember to add to a
 * new route, so it cannot be missing from the one route nobody thought about.
 */
class PreventDuplicateSubmission
{
    /**
     * ⚠ A safety valve, NOT a cooldown. The key is normally released in the
     * `finally` the instant the first request finishes; this TTL only matters if
     * that process dies mid-request (fatal, timeout, killed worker) so a crashed
     * write can never wedge the button shut for longer than this.
     */
    private const MAX_HOLD_SECONDS = 30;

    public const MESSAGE = 'That request is already being processed - please wait for it to finish instead of pressing again. Nothing was lost.';

    /**
     * Ending a session must never depend on cache state, and a double logout is
     * harmless anyway.
     *
     * @var array<int, string>
     */
    private const EXEMPT_ROUTES = [
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->guards($request)) {
            return $next($request);
        }

        $key = $this->fingerprint($request);

        // ⚠⚠ Cache::add is atomic on every store (an insert that fails if the row
        // already exists), so two concurrent identical writes can never both
        // acquire it - exactly one wins and proceeds, the other is refused. A
        // read-then-write would race here and let both through, which is the very
        // bug this exists to stop.
        if (! Cache::add($key, true, self::MAX_HOLD_SECONDS)) {
            return $this->reject($request);
        }

        try {
            return $next($request);
        } finally {
            Cache::forget($key);
        }
    }

    /**
     * Only mutating requests, only when signed in, and never a precognition
     * probe (a live-validation keystroke is not a submission). A safe method
     * writes nothing, so a duplicate GET costs nothing to allow.
     */
    private function guards(Request $request): bool
    {
        return $request->user() !== null
            && ! $request->isMethodSafe()
            && ! $request->isPrecognitive()
            && ! in_array($request->route()?->getName(), self::EXEMPT_ROUTES, true);
    }

    /**
     * Stable across two identical submits, distinct for anything genuinely
     * different. Files are dropped from the payload (an UploadedFile does not
     * serialise, and a re-upload of the same bytes is a new attempt, not the
     * duplicate we guard); CSRF and method-spoof fields carry no intent.
     */
    private function fingerprint(Request $request): string
    {
        $payload = $request->except(array_merge(
            ['_token', '_method'],
            array_keys($request->allFiles()),
        ));

        // ⚠ Bug hunt 16 Oct 2026: but WHICH file is part of the request. Two
        // different files sent to the same folder at once used to look like
        // one request sent twice, and the second was refused.
        $files = collect($request->allFiles())->flatten()
            ->map(fn ($file) => $file instanceof UploadedFile ? $file->getClientOriginalName().':'.$file->getSize() : '')
            ->all();

        return 'dup-submit:'.hash('sha256', implode('|', [
            (string) $request->user()->id,
            $request->getMethod(),
            $request->path(),
            (string) json_encode($payload),
            (string) json_encode($files),
        ]));
    }

    /**
     * ⚠⚠ 303, not the default 302, for the reason recorded on
     * EnsurePageBusinessMatches: a 302 answer to a PUT/PATCH/DELETE is re-issued
     * by the browser WITH the method preserved and 405s on the index route. 303
     * See Other forces the follow-up to a GET, which is what actually shows the
     * message.
     */
    private function reject(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => self::MESSAGE], Response::HTTP_CONFLICT);
        }

        return back(Response::HTTP_SEE_OTHER)->with('error', self::MESSAGE);
    }
}
