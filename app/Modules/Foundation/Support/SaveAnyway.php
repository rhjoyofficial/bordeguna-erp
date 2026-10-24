<?php

namespace App\Modules\Foundation\Support;

use App\Modules\Foundation\Corrections\Contracts\DuplicateRule;
use App\Modules\Foundation\Corrections\DuplicateAck;
use App\Modules\Foundation\Corrections\DuplicateGuard;
use App\Modules\Foundation\Corrections\Exceptions\PossibleDuplicateException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ B13 (24 Oct 2026): the "Possible duplicate ... Save anyway" step every
 * list form shares (customers, suppliers, employees, products, the phone book),
 * the same shape the receipt and the supplier payment forms use (B7).
 *
 *   ask()      before anything is written: the rule's candidates, unless the
 *              form came back with an ack bound to this person and exactly
 *              this payload. A match flashes `duplicateWarning` (the shared
 *              prop the DuplicateWarning panel reads) and refuses with
 *              `_duplicate`, so nothing is saved.
 *   record()   after the save, in the same transaction: "Saved despite a
 *              possible duplicate", kept beside the record for the Owner's
 *              Corrections report.
 *
 * ⚠ A warning, never a hard rule: hard uniqueness (a SKU, a code) stays a
 * unique index and refuses as before.
 */
final class SaveAnyway
{
    /**
     * @param  list<string>  $exclude  "morph:id" of the record being edited
     *
     * @throws ValidationException
     */
    public static function ask(DuplicateRule $rule, array $payload, Request $request, int $businessId, string $question, array $exclude = []): ?DuplicateAck
    {
        try {
            return app(DuplicateGuard::class)->check($rule, $payload, $request->user(), $businessId, self::ack($request), $exclude);
        } catch (PossibleDuplicateException $e) {
            // A screen that saves with fetch (the product popup) reads the
            // warning from the 422 body; an Inertia form reads the flash.
            if ($request->wantsJson()) {
                throw new HttpResponseException(response()->json([
                    'message' => $question,
                    'errors' => ['_duplicate' => [$question]],
                    'duplicate' => $e->warning->toArray(),
                ], 422));
            }

            $request->session()->flash('duplicateWarning', $e->warning->toArray());

            throw ValidationException::withMessages(['_duplicate' => $question]);
        }
    }

    public static function record(?DuplicateAck $ack, Model $saved): void
    {
        if ($ack !== null) {
            app(DuplicateGuard::class)->record($ack, $saved);
        }
    }

    /** The validation rule a form adds for its ack. */
    public static function rule(): array
    {
        return ['duplicate_ack' => ['nullable', 'string', 'max:2000']];
    }

    private static function ack(Request $request): ?string
    {
        $ack = $request->input('duplicate_ack');

        return is_string($ack) && $ack !== '' ? $ack : null;
    }
}
