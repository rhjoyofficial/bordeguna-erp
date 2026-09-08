<?php

namespace App\Modules\Foundation\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The one-shot permission a browser is given to fetch one document, once.
 *
 * ⭐⭐ WHY THIS EXISTS RATHER THAN A LOGIN. The PDF is produced by pointing a
 * headless browser at the very page a person opens, so that the PDF and the
 * printed paper cannot disagree - there is no second template. But that browser
 * is not signed in, and this ERP holds payroll, so it is not being handed a
 * session.
 *
 * ⚠⚠ THREE THINGS BOUND TOGETHER, AND ALL THREE MATTER:
 *  - **one user**, so the document is fetched as the person who asked for it
 *    and every existing permission on that route still decides;
 *  - **one exact path**, so a token minted for a payslip cannot fetch an
 *    invoice - the commonest shape of this mistake;
 *  - **one use**, claimed atomically, so a token that reaches a log or a process
 *    list has already been spent by the time anybody reads it.
 *
 * ⚠ `Cache::add()` is the atomic part and is not decoration. On the database
 * store it is an INSERT that fails on a duplicate key, so two requests racing
 * the same token cannot both win. A get-then-forget would let them.
 */
final class DocumentRenderToken
{
    private const PREFIX = 'document-render:';

    /**
     * @return string the token to put on the URL
     */
    public static function mint(int $userId, ?int $businessId, string $path): string
    {
        $token = Str::random(64);

        Cache::put(self::PREFIX.$token, [
            'user_id' => $userId,
            'business_id' => $businessId,
            // Stored WITHOUT the query string and leading slash, and compared
            // the same way, so the token names one document and nothing else.
            'path' => trim($path, '/'),
        ], config('pdf.token_ttl'));

        return $token;
    }

    /**
     * Claim a token for this exact path, or refuse.
     *
     * @return array{user_id:int, business_id:?int}|null
     */
    public static function claim(string $token, string $path): ?array
    {
        $payload = Cache::get(self::PREFIX.$token);

        if (! is_array($payload) || $payload['path'] !== trim($path, '/')) {
            return null;
        }

        // ⚠ The claim, not the read, is what makes this single use. Whoever
        // wins this insert owns the token; everyone else is refused.
        if (! Cache::add(self::PREFIX.$token.':claimed', 1, config('pdf.token_ttl') + 60)) {
            return null;
        }

        Cache::forget(self::PREFIX.$token);

        return ['user_id' => $payload['user_id'], 'business_id' => $payload['business_id']];
    }

    /**
     * How the render actually ended.
     *
     * ⚠⚠ WITHOUT THIS THE PDF LIES. A browser given a page it may not see gets
     * a 403 and prints it perfectly happily, and the person who asked for an
     * invoice is handed a tidy PDF of an error message. The middleware records
     * the real status here and the controller refuses anything but 200.
     */
    public static function recordOutcome(string $token, int $status): void
    {
        Cache::put(self::PREFIX.$token.':status', $status, config('pdf.token_ttl') + 60);
    }

    public static function outcome(string $token): ?int
    {
        return Cache::get(self::PREFIX.$token.':status');
    }
}
