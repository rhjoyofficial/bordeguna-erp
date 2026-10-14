<?php

namespace App\Modules\Accounting\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A date a report reads from its address bar (FEA-008, 14 Oct 2026).
 *
 * Every report parsed `?from=` / `?to=` / `?as_at=` with `Carbon::parse` on
 * the raw string, so a truncated or hand-edited link (`from=2026-13-40`,
 * `from=abc`) threw InvalidFormatException and the reader met an error page.
 * The date inputs on the pages only ever send real dates, so this is about
 * bookmarks and shared links: the reader is sent back with a sentence on the
 * field instead.
 */
final class ReportDates
{
    /**
     * @throws ValidationException
     */
    public static function parse(Request $request, string $key): Carbon
    {
        Validator::make(
            [$key => $request->query($key, $request->input($key))],
            [$key => ['date']],
            [$key.'.date' => 'The report link has a date that is not a real date ('.$key.'). Pick the dates again.'],
        )->validate();

        return Carbon::parse($request->string($key)->toString());
    }
}
