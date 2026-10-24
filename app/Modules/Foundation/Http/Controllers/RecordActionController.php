<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Corrections\CorrectionInput;
use App\Modules\Foundation\Corrections\CorrectionService;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use App\Modules\Foundation\Corrections\Exceptions\ClosedPeriodPassException;
use App\Modules\Foundation\Corrections\Exceptions\CorrectionBlockedException;
use App\Modules\Foundation\Corrections\Exceptions\PossibleDuplicateException;
use App\Modules\Foundation\Corrections\Exceptions\PreviewExpiredException;
use App\Modules\Foundation\Corrections\Exceptions\StaleCorrectionException;
use App\Modules\Foundation\Corrections\RecordHistory;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use App\Modules\Foundation\Corrections\ValidatesActionReason;
use App\Modules\Foundation\Support\HistoryOnlyTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The generic action routes (PHASE-0-CONTRACT v2, 2.3):
 *
 *   GET  /records/{type}/{id}/actions           the buttons, blockers and dates
 *   GET  /records/{type}/{id}/history           the History tab
 *   POST /records/{type}/{id}/{action}/preview  date, effect, what stays (JSON)
 *   POST /records/{type}/{id}/{action}          do it (Inertia redirect)
 *
 * ⚠ Only a type in the DocumentPolicyRegistry is accepted (anything else is a
 * 404), and the policy checks the record's own view right before anything else.
 * Every refusal arrives as a plain sentence; nothing here decides the rules.
 */
class RecordActionController extends Controller
{
    use ValidatesActionReason;

    private const PHASE0_ACTIONS = ['reverse', 'correct'];

    public function __construct(
        private readonly CorrectionService $service,
        private readonly DocumentPolicyRegistry $registry,
    ) {}

    public function actions(Request $request, string $type, int $id): JsonResponse
    {
        return response()->json($this->service->actions($type, $id, $request->user())->toArray());
    }

    public function history(Request $request, string $type, int $id): JsonResponse
    {
        // ⭐ 24 Oct 2026 (wave 1 gaps): a record with a History tab but no
        // correction actions (a supplier advance, a sales order).
        $historyOnly = app(HistoryOnlyTypes::class);

        if (! $this->registry->has($type) && $historyOnly->has($type)) {
            $record = $historyOnly->find($type, $id, $request->user()) ?? throw new NotFoundHttpException;

            return response()->json(RecordHistory::for($record, $request->user()));
        }

        if (! $this->registry->has($type)) {
            throw new NotFoundHttpException;
        }

        $policy = $this->registry->for($type);
        $document = $this->registry->corrector($type)->find($id) ?? throw new NotFoundHttpException;

        abort_unless($policy->canView($document, $request->user()), 404);

        return response()->json(RecordHistory::for($document, $request->user()));
    }

    public function preview(Request $request, string $type, int $id, string $action): JsonResponse
    {
        $input = $this->input($request, $type, $id, $action, requireToken: false);

        return response()->json($this->service->preview($input, $request->user())->toArray());
    }

    public function act(Request $request, string $type, int $id, string $action): RedirectResponse|JsonResponse
    {
        $input = $this->input($request, $type, $id, $action, requireToken: true);

        try {
            $result = $this->service->submit($input, $request->user(), $request->input('preview_token'), $request->input('duplicate_ack'));
        } catch (PossibleDuplicateException $e) {
            if ($request->expectsJson()) {
                return response()->json(['code' => 'possible_duplicate'] + $e->warning->toArray(), 409);
            }

            $request->session()->flash('duplicateWarning', $e->warning->toArray());

            throw ValidationException::withMessages(['_duplicate' => 'Possible duplicate. Is this a different income?']);
        } catch (StaleCorrectionException $e) {
            return $this->refuse($request, 'stale', $e->getMessage(), 409);
        } catch (PreviewExpiredException $e) {
            return $this->refuse($request, 'preview_token', $e->getMessage(), 409);
        } catch (CorrectionBlockedException|ClosedPeriodPassException $e) {
            return $this->refuse($request, 'correction', $e->getMessage(), 422);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'outcome' => $result->outcome->value,
                'message' => $result->message,
                'link_id' => $result->link?->id,
                'request_id' => $result->request?->id,
            ]);
        }

        $to = $request->input('redirect_to');

        return ($this->isLocal($to) ? redirect($to) : back())->with('success', $result->message);
    }

    private function input(Request $request, string $type, int $id, string $action, bool $requireToken): CorrectionInput
    {
        if (! $this->registry->has($type) || ! in_array($action, self::PHASE0_ACTIONS, true)) {
            throw new NotFoundHttpException;
        }

        $verb = DocumentAction::from($action);
        $context = $verb === DocumentAction::Correct ? 'correction' : 'reversal';

        $request->validate([
            ...$this->actionReasonRules($context),
            'replacement' => [$verb === DocumentAction::Correct ? 'required' : 'prohibited', 'array'],
            'date_choice' => ['nullable', Rule::in(array_column(ReverseDateChoice::cases(), 'value'))],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:64'],
            'preview_token' => [$requireToken ? 'required' : 'nullable', 'string'],
            'duplicate_ack' => ['nullable', 'string', 'max:2000'],
            'redirect_to' => ['nullable', 'string', 'max:500'],
        ], $this->actionReasonMessages());

        return new CorrectionInput(
            type: $type,
            documentId: $id,
            action: $verb,
            reason: $this->actionReason($request),
            replacement: $verb === DocumentAction::Correct ? (array) $request->input('replacement') : null,
            dateChoice: ReverseDateChoice::tryFrom((string) $request->input('date_choice')) ?? ReverseDateChoice::OriginalDate,
            idempotencyKey: (string) $request->input('idempotency_key'),
        );
    }

    private function refuse(Request $request, string $key, string $message, int $status): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['code' => $key, 'message' => $message], $status);
        }

        throw ValidationException::withMessages([$key => $message]);
    }

    /** Only a path on this site: never an open redirect. */
    private function isLocal(?string $to): bool
    {
        return is_string($to) && str_starts_with($to, '/') && ! str_starts_with($to, '//') && ! str_contains($to, '\\');
    }
}
