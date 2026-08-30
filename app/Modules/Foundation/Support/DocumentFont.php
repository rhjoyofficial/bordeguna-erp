<?php

namespace App\Modules\Foundation\Support;

use App\Modules\Foundation\Models\Business;
use Throwable;

/**
 * Which face this business sets its documents in.
 *
 * ⚠⚠ THIS IS CALLED FROM THE ROOT LAYOUT, on every single page including the
 * login screen, the error pages and anything rendered before a business is in
 * context. So it can never throw and can never assume a session: it answers
 * with the default and moves on. A typography setting that could 500 the login
 * page would be a remarkably expensive way to choose a font.
 */
final class DocumentFont
{
    public static function current(): string
    {
        try {
            $id = app(BusinessContext::class)->id();

            if ($id === null) {
                return FontCatalogue::DEFAULT;
            }

            // ⚠ withoutGlobalScopes for the same reason BusinessIdentity uses
            // it: the business is resolved BY ID from the request's own
            // context, not filtered by the tenant scope that context sets up.
            $business = Business::withoutGlobalScopes()->find($id);

            return $business?->document_font ?: FontCatalogue::DEFAULT;
        } catch (Throwable) {
            // No session, no database, a request that never had a business:
            // all of them mean "nobody has chosen", which is the default.
            return FontCatalogue::DEFAULT;
        }
    }
}
