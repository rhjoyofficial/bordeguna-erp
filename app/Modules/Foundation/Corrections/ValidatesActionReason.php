<?php

namespace App\Modules\Foundation\Corrections;

use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The server side of every action dialog's reason (PHASE-0-CONTRACT 1.6).
 *
 * ⚠ Checked HERE, not trusted from the dialog: a request sent without the UI
 * gets a 422, never a reasonless correction. The text is trimmed first, so five
 * spaces are not a reason.
 */
trait ValidatesActionReason
{
    protected function actionReasonRules(string $context): array
    {
        return [
            'reason_id' => ['nullable', 'integer', Rule::exists('reasons', 'id')
                ->where('business_id', app(BusinessContext::class)->id())
                ->where('context', $context)
                ->where('is_active', true)],
            'reason_text' => ['required', 'string', 'min:'.ActionReason::MIN, 'max:'.ActionReason::MAX],
        ];
    }

    protected function actionReasonMessages(): array
    {
        return [
            'reason_text.required' => 'Say why, in a few words.',
            'reason_text.min' => 'Say why in at least '.ActionReason::MIN.' characters.',
        ];
    }

    protected function actionReason(Request $request): ActionReason
    {
        $id = $request->input('reason_id');

        return new ActionReason($id === null || $id === '' ? null : (int) $id, trim((string) $request->input('reason_text')));
    }
}
