<?php

namespace App\Modules\Foundation\Http\Middleware;

use App\Modules\Foundation\Support\BusinessContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * W3. The current business lives in the SESSION, so it belongs to the browser
 * rather than to the page, and the whole design assumed one user with one tab.
 *
 * Switch business in tab B, return to tab A which is still showing the previous
 * tenant's figures, click Approve, and the write lands in the tenant tab A was
 * never displaying. Nothing in the request said which business the page was
 * built for, so nothing could detect it. It failed silently, and it wrote.
 *
 * This is the same reasoning as a CSRF token: the page asserts the context it
 * was built for, and the server refuses to act on a mismatch. The stamp is
 * `X-Business-Id`, put on every Inertia visit by `router.on('before')` and taken
 * from the last page THIS tab rendered.
 *
 * See BUSINESS-SWITCHING-ARCHITECTURE-REVIEW.md §13.
 */
class EnsurePageBusinessMatches
{
    public const HEADER = 'X-Business-Id';

    /**
     * Joy's wording, revised 2026-08-02 once he saw the behaviour. The first
     * version said "Please reload", but the rejection redirects back, so the tab
     * has already reloaded itself by the time anybody reads it. This says what
     * actually happened, and says plainly that nothing was written.
     */
    public const MESSAGE = 'This page belongs to a different business because you switched businesses in another tab. No changes were saved. Please reopen this page from the current business.';

    /**
     * ⚠⚠ The switch endpoint is not optional here. After switching in tab B,
     * tab A's switcher page still asserts the old business - guarding it would
     * make it impossible to switch back from the stale tab, so the single action
     * that resolves the problem would be the one action refused.
     *
     * Logout is exempt for the same shape of reason: ending a session must never
     * depend on which tenant a page was built for.
     *
     * @var array<int, string>
     */
    private const EXEMPT_ROUTES = [
        'businesses.switch.store',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->guards($request)) {
            return $next($request);
        }

        $current = app(BusinessContext::class)->id();
        $stamped = $request->header(self::HEADER);

        // Nothing resolved means there is no tenant to disagree with. A user in
        // that state cannot write anything anyway, since BusinessScope is
        // already failing closed on 1 = 0.
        if ($current === null) {
            return $next($request);
        }

        if ($stamped === null) {
            // ⚠ Fail closed, but only for our own front end. An Inertia request
            // without a stamp means the client did not do its job; nothing else
            // in this app writes. Non-Inertia callers pass through untouched so
            // tooling and tests are unaffected.
            //
            // ⚠ The risk is named in §13.6 and accepted: if the stamp ever stops
            // being sent, every write fails. That failure is loud and instantly
            // reversible; the one it prevents is a silent write into another
            // company's books.
            return $request->inertia()
                ? $this->reject()
                : $next($request);
        }

        return (int) $stamped === $current
            ? $next($request)
            : $this->reject();
    }

    /**
     * Only mutating requests, and only when somebody is signed in. A stale GET
     * shows stale data and writes nothing; the next navigation corrects it.
     */
    private function guards(Request $request): bool
    {
        return $request->user() !== null
            && ! $request->isMethodSafe()
            && ! in_array($request->route()?->getName(), self::EXEMPT_ROUTES, true);
    }

    /**
     * ⚠⚠ 303, not the default 302, and a browser is the only thing that shows
     * why. A 302 answer to a PUT/PATCH/DELETE is followed by the browser **with
     * the method preserved**, so rejecting a shift update re-issued
     * `PUT /hrm/shifts` against the index route and produced a raw 405
     * "Method Not Allowed" exception page instead of the message. 303 See Other
     * is defined to make the follow-up a GET, which is exactly why Inertia's own
     * middleware rewrites redirects this way for those verbs.
     *
     * ⚠ The Pest tests asserted a redirect and passed against the broken
     * version, because a test client does not follow one. Only the browser
     * failed. Same lesson as the switcher page in 2026-07-29.
     */
    private function reject(): Response
    {
        return back(Response::HTTP_SEE_OTHER)->with('error', self::MESSAGE);
    }
}
