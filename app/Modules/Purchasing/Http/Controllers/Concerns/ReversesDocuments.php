<?php

namespace App\Modules\Purchasing\Http\Controllers\Concerns;

use App\Modules\Accounting\Exceptions\JournalException;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * PUR-003 (owner decision 14 Oct 2026): the one form every Reverse button posts
 * - a required reason and, optionally, "date it on the document's own date" -
 * and the one way a refusal comes back: a sentence under the reason, never a
 * blank error page.
 */
trait ReversesDocuments
{
    /**
     * @param  callable(string $reason, bool $onOriginalDate): mixed  $reverse
     */
    protected function reverseFromForm(Request $request, callable $reverse, string $done): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'on_original_date' => ['nullable', 'boolean'],
        ], ['reason.required' => 'Say why it is being reversed.']);

        try {
            $reverse($data['reason'], (bool) ($data['on_original_date'] ?? false));
        } catch (PeriodClosedException|MappingNotFound|JournalException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', $done);
    }
}
