<?php

namespace App\Modules\Foundation\Corrections;

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Foundation\Corrections\Contracts\CorrectionDatePolicy;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\Contracts\DocumentCorrector;
use App\Modules\Foundation\Corrections\Exceptions\ClosedPeriodPassException;
use App\Modules\Foundation\Corrections\Exceptions\CorrectionBlockedException;
use App\Modules\Foundation\Corrections\Exceptions\PossibleDuplicateException;
use App\Modules\Foundation\Corrections\Exceptions\PreviewExpiredException;
use App\Modules\Foundation\Corrections\Exceptions\StaleCorrectionException;
use App\Modules\Foundation\Models\CorrectionLink;
use App\Modules\Foundation\Models\CorrectionRequest;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Reverse and Correct for every registered document type (PHASE-0-CONTRACT v2,
 * 1.4). ORCHESTRATION, NOT BUSINESS LOGIC: each type's DocumentCorrector posts
 * its own reversal and successor through its module's own actions.
 *
 * The commit sequence, one shape for every type:
 *   1. replay: a link or request with this idempotency key already exists ->
 *      the same answer again, nothing re-run;
 *   2. the preview token: same person, business, document, payload, 15 minutes;
 *   3. permission, early;
 *   4. ONE transaction: lock and re-read the original, compare its fingerprint,
 *      re-run the policy, the dates, the basis, the route and the duplicate
 *      check under the lock; then either create a request (nothing touches the
 *      ledger) or execute: the Owner's pass when the month is closed, reversal,
 *      successor, mark, link, close the pass, invariants;
 *   5. notifications after the commit, never inside it.
 *
 * ⚠⚠ The database is the last word: correction_links.live_key and the
 * idempotency keys are UNIQUE, so a race the lock missed (SQLite has no lock)
 * still cannot make a second reversal.
 */
class CorrectionService
{
    public function __construct(
        private readonly DocumentPolicyRegistry $registry,
        private readonly CorrectionDatePolicy $datePolicy,
        private readonly SecondPersonRule $secondPerson,
        private readonly DuplicateGuard $duplicates,
        private readonly ClosedPeriodExceptions $exceptions,
    ) {}

    public function actions(string $type, int $documentId, User $user): ActionSet
    {
        [$policy, , $document] = $this->resolve($type, $documentId, $user);

        return $policy->actionsFor($document, $user);
    }

    /**
     * What would happen, in plain sentences, with a token for an immediate
     * submit. Writes nothing.
     */
    public function preview(CorrectionInput $input, User $actor): CorrectionPreview
    {
        [$policy, $corrector, $document] = $this->resolve($input->type, $input->documentId, $actor);
        $this->authorise($policy, $document, $actor, $input->action);

        $plan = $this->plan($policy, $corrector, $document, $input, $actor, $actor, 'preview');
        $token = null;

        if ($plan->blockers === []) {
            $token = PreviewToken::issue([
                'uid' => $actor->id,
                'bid' => (int) app(BusinessContext::class)->id(),
                'type' => $input->type,
                'id' => $input->documentId,
                'action' => $input->action->value,
                'pfp' => $input->payloadFingerprint(),
                'dfp' => $plan->fingerprint,
                'dates' => $plan->dates->signature(),
                'route' => $plan->route->value,
            ]);
        }

        return new CorrectionPreview(
            previewToken: $token,
            documentFingerprint: $plan->fingerprint,
            dates: $plan->dates,
            effects: $plan->effects,
            stays: $plan->stays,
            blockers: $plan->blockers,
            approval: $plan->route,
            amountBasisMinor: $plan->basisMinor,
            duplicate: $plan->duplicateWarning,
            warnings: $plan->warnings,
            title: "{$input->action->label()} {$plan->number}?",
            confirmLabel: $this->confirmLabel($input->action, $plan->route),
        );
    }

    /**
     * Executes now, or creates a request when the route needs somebody else.
     *
     * @throws StaleCorrectionException
     * @throws CorrectionBlockedException
     * @throws PreviewExpiredException
     * @throws PossibleDuplicateException
     */
    public function submit(CorrectionInput $input, User $actor, ?string $previewToken, ?string $duplicateAck = null): CorrectionResult
    {
        $businessId = (int) app(BusinessContext::class)->id();

        if (($replay = $this->replay($input, $businessId)) !== null) {
            return $replay;
        }

        [$policy, $corrector, $document] = $this->resolve($input->type, $input->documentId, $actor);
        $this->authorise($policy, $document, $actor, $input->action);
        $number = $policy->numberOf($document);

        try {
            // The real reason first ("already reversed"), before "preview again".
            $early = $policy->availability($document, $actor, $input->action);

            if ($early->hardBlockers() !== []) {
                throw new CorrectionBlockedException($early->hardBlockers());
            }

            $claims = PreviewToken::open($previewToken, $actor->id, $businessId, $input);

            $result = DB::transaction(function () use ($policy, $corrector, $input, $actor, $claims, $duplicateAck) {
                $document = $corrector->lockForCommit($input->documentId);

                if (! hash_equals((string) $claims['dfp'], $corrector->fingerprint($document))) {
                    throw new StaleCorrectionException;
                }

                $plan = $this->plan($policy, $corrector, $document, $input, $actor, $actor, 'submit', $duplicateAck);

                if ($plan->dates->signature() !== $claims['dates'] || $plan->route->value !== $claims['route']) {
                    throw new StaleCorrectionException;
                }

                if ($plan->blockers !== []) {
                    throw new CorrectionBlockedException($plan->blockers);
                }

                if ($plan->route !== ApprovalRoute::None) {
                    return $this->request($plan, $input, $actor);
                }

                return $this->execute($plan, $corrector, $input->action, $input->reason, $actor, null, null, $input->idempotencyKey);
            });
        } catch (UniqueConstraintViolationException $e) {
            if (($replay = $this->replay($input, $businessId)) !== null) {
                return $replay;
            }

            $blocked = new CorrectionBlockedException([new Blocker('already_reversed', "{$number} was already reversed or corrected by somebody else.")]);
            CorrectionLog::blocked($actor, $document, $input->type, $input->documentId, $number, $input->action, $blocked->codes(), $blocked->getMessage());

            throw $blocked;
        } catch (StaleCorrectionException|CorrectionBlockedException|PreviewExpiredException|ClosedPeriodPassException $e) {
            $codes = match (true) {
                $e instanceof CorrectionBlockedException => $e->codes(),
                $e instanceof PreviewExpiredException => ['preview_'.$e->why],
                $e instanceof StaleCorrectionException => ['stale'],
                default => ['pass_refused'],
            };

            CorrectionLog::blocked($actor, $document, $input->type, $input->documentId, $number, $input->action, $codes, $e->getMessage());

            throw $e;
        }

        if ($result->outcome === CorrectionOutcome::Requested && $result->request !== null) {
            $this->notifyApprovers($result->request);
        }

        return $result;
    }

    /**
     * Called by the approval controller after the final stage approved. Never
     * reads a preview token: everything is checked again from the request's
     * stored intent, under the lock (v2, C9).
     *
     * @throws StaleCorrectionException
     * @throws CorrectionBlockedException
     */
    public function executeApproved(CorrectionRequest $request, User $approver): CorrectionResult
    {
        return DB::transaction(function () use ($request, $approver) {
            $request = CorrectionRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if (! $request->isPending()) {
                throw new CorrectionBlockedException([new Blocker('request_decided', 'This request was already decided.')]);
            }

            $policy = $this->registry->for($request->type);
            $corrector = $this->registry->corrector($request->type);
            $requester = $request->requestedBy()->firstOrFail();
            $document = $corrector->lockForCommit((int) $request->document_id);

            if (! hash_equals((string) $request->document_fingerprint, $corrector->fingerprint($document))) {
                throw new StaleCorrectionException('This record changed while the request waited, so nothing was posted. Please ask again.');
            }

            $route = $request->approval_route;
            $authority = match (true) {
                $route->needsOwnerApproval() => $approver,
                $request->owner_authorised_by !== null => $request->ownerAuthorisedBy()->first(),
                default => $requester,
            };

            $input = new CorrectionInput(
                type: $request->type,
                documentId: (int) $request->document_id,
                action: $request->action,
                reason: new ActionReason($request->reason_id === null ? null : (int) $request->reason_id, (string) $request->reason_text),
                replacement: $request->payload['replacement'] ?? null,
                dateChoice: ReverseDateChoice::from($request->payload['date_choice'] ?? ReverseDateChoice::OriginalDate->value),
                idempotencyKey: (string) $request->idempotency_key,
            );

            $plan = $this->plan($policy, $corrector, $document, $input, $requester, $authority, 'approve', null, $request->duplicate_ack ?? []);

            if ($plan->dates->signature() !== ($request->dates ?? []) || $plan->route !== $route) {
                throw new StaleCorrectionException('The amount, the date or the month changed while the request waited, so nothing was posted. Please ask again.');
            }

            if ($plan->blockers !== []) {
                throw new CorrectionBlockedException($plan->blockers);
            }

            if ($route->needsOwnerApproval() && ! Owners::mayCorrectClosedMonth($approver)) {
                throw new CorrectionBlockedException([new Blocker('owner_required', 'Only the Owner can approve a closed-month correction.')]);
            }

            $result = $this->execute($plan, $corrector, $input->action, $input->reason, $requester, $approver, $request, $input->idempotencyKey);

            $request->forceFill([
                'status' => CorrectionRequestStatus::Executed,
                'executed_link_id' => $result->link?->id,
                'execution_summary' => [
                    'date' => $plan->dates->sentence,
                    'effects' => array_map(fn (EffectLine $line) => $line->sentence, $plan->effects),
                    'basis' => Tk::of($plan->basisMinor),
                    'approved_by' => $approver->name,
                ],
                'decided_at' => now(),
                'pending_key' => null,
            ])->save();

            return $result;
        });
    }

    /** The request is closed as failed: the record changed, nothing was posted. */
    public function markFailed(CorrectionRequest $request, string $message): void
    {
        $request->forceFill([
            'status' => CorrectionRequestStatus::FailedStale,
            'failure_message' => $message,
            'decided_at' => now(),
            'pending_key' => null,
        ])->save();
    }

    // ─── the plan ───────────────────────────────────────────────────────────

    /**
     * @param  'preview'|'submit'|'approve'  $mode
     * @param  list<string>  $ackedIds  approve mode: the candidates the requester acknowledged
     */
    public function plan(
        DocumentActionPolicy $policy,
        DocumentCorrector $corrector,
        Model $document,
        CorrectionInput $input,
        User $actor,
        ?User $authority,
        string $mode,
        ?string $ackToken = null,
        array $ackedIds = [],
    ): CorrectionPlan {
        $businessId = (int) app(BusinessContext::class)->id();
        $number = $policy->numberOf($document);
        $action = $input->action;

        if (! in_array($action, [DocumentAction::Reverse, DocumentAction::Correct], true)) {
            throw new CorrectionBlockedException([new Blocker('not_offered', "{$action->label()} is not offered for this record.")]);
        }

        $replacement = $action === DocumentAction::Correct
            ? $corrector->validateReplacement($document, $input->replacement ?? [])
            : null;

        $dates = $this->datePolicy->resolve(
            $corrector->originalDate($document),
            $action,
            $input->dateChoice,
            $replacement?->date,
            $authority,
        );

        $availability = $policy->availability($document, $actor, $action);

        // At approval, the request being approved is the "waiting request".
        $all = $mode === 'approve'
            ? array_values(array_filter($availability->blockers, fn (Blocker $b) => $b->code !== 'request_waiting'))
            : [...$availability->blockers];

        if ($dates->blocker !== null) {
            $all[] = $dates->blocker;
        }

        // One sentence per reason, even when the policy and the dates both said it.
        $byCode = [];
        foreach ($all as $blocker) {
            $byCode[$blocker->code] ??= $blocker;
        }

        $hard = array_values(array_filter($byCode, fn (Blocker $b) => ! $b->requestable));
        $requestable = array_values(array_filter($byCode, fn (Blocker $b) => $b->requestable));

        $originalMinor = $corrector->totalMinor($document);
        $successorMinor = $replacement?->totalMinor;
        $basis = $this->secondPerson->basis($originalMinor, $successorMinor);
        $above = $this->secondPerson->applies($businessId, $basis);
        $askerIsOwner = Owners::mayCorrectClosedMonth($actor);
        $route = $this->secondPerson->route($dates->needsClosedPeriodPass, $askerIsOwner, $above);

        // In approve mode the Owner who approves is the closed-month authority.
        if ($mode === 'approve' && $route->needsOwnerApproval()) {
            $requestable = [];
        }

        if ($requestable !== [] && ! $route->needsOwnerApproval()) {
            $hard = [...$hard, ...$requestable];
            $requestable = [];
        }

        $excluded = array_values(array_unique(array_map('intval', $corrector->makerAndLastEditorIds($document))));

        if ($hard === [] && $route !== ApprovalRoute::None && $mode !== 'approve'
            && $this->secondPerson->eligibleApprovers($businessId, $route, $actor->id, $excluded)->isEmpty()) {
            $hard[] = Blocker::noSecondPerson();
        }

        // ── duplicates (Correct only) ─────────────────────────────────────
        $warning = null;
        $ack = null;
        $duplicateIds = [];

        if ($replacement !== null && ($rule = $this->registry->duplicateRule($input->type)) !== null) {
            $payload = $corrector->replacementInput($replacement);
            $exclude = CorrectionChain::keys($document);
            $candidates = $rule->candidates($payload, $businessId, $actor, $exclude);
            $duplicateIds = array_map(fn (DuplicateCandidate $c) => $c->type.':'.$c->id, $candidates);
            sort($duplicateIds);

            if ($mode === 'preview') {
                $warning = $this->duplicates->warning($rule, $payload, $actor, $businessId, $exclude);
            } elseif ($mode === 'submit' && $hard === []) {
                $ack = $this->duplicates->check($rule, $payload, $actor, $businessId, $ackToken, $exclude);
            } elseif ($mode === 'approve' && array_diff($duplicateIds, $ackedIds) !== []) {
                throw new StaleCorrectionException('A similar record was saved while this request waited, so nothing was posted. Please ask again.');
            } elseif ($mode === 'approve' && $candidates !== []) {
                $ack = new DuplicateAck($actor->id, $businessId, $rule->key(), hash('sha256', CorrectionInput::canonical($rule->fingerprintOf($payload, $businessId))), array_map(
                    fn (DuplicateCandidate $c) => ['type' => $c->type, 'id' => $c->id, 'number' => $c->number],
                    $candidates,
                ));
            }
        }

        $planned = 0;

        if ($dates->needsClosedPeriodPass) {
            $planned = 1 + ($action === DocumentAction::Correct && in_array($dates->successorDate?->toDateString(), $dates->closedDates, true) ? 1 : 0);
        }

        return new CorrectionPlan(
            document: $document,
            number: $number,
            fingerprint: $corrector->fingerprint($document),
            replacement: $replacement,
            dates: $dates,
            originalMinor: $originalMinor,
            successorMinor: $successorMinor,
            basisMinor: $basis,
            route: $route,
            blockers: $hard,
            requestable: $requestable,
            effects: $hard === [] ? $corrector->describeEffects($document, $action, $replacement, $dates) : [],
            stays: $corrector->describeStays($document, $action),
            warnings: $this->warnings($route, $dates, $excluded, $document),
            excluded: $excluded,
            duplicateWarning: $warning,
            duplicateAck: $ack,
            duplicateIds: $duplicateIds,
            plannedClosedJournals: $planned,
        );
    }

    // ─── writing ────────────────────────────────────────────────────────────

    private function request(CorrectionPlan $plan, CorrectionInput $input, User $actor): CorrectionResult
    {
        $document = $plan->document;
        $pendingKey = CorrectionLink::liveKeyFor($document);

        if (CorrectionRequest::query()->where('pending_key', $pendingKey)->exists()) {
            throw new CorrectionBlockedException([new Blocker('request_waiting', "A correction of {$plan->number} is already waiting for approval.")]);
        }

        $request = CorrectionRequest::create([
            'type' => $input->type,
            'document_type' => $document->getMorphClass(),
            'document_id' => $document->getKey(),
            'document_number' => $plan->number,
            'action' => $input->action,
            'payload' => [
                'replacement' => $plan->replacement === null ? null : $input->replacement,
                'date_choice' => $input->dateChoice->value,
            ],
            'dates' => $plan->dates->signature(),
            'reason_id' => $input->reason->reasonId,
            'reason_text' => $input->reason->text,
            'document_fingerprint' => $plan->fingerprint,
            'amount_basis_minor' => $plan->basisMinor,
            'original_minor' => $plan->originalMinor,
            'successor_minor' => $plan->successorMinor,
            'approval_route' => $plan->route,
            'workflow_key' => $plan->route->workflowKey(),
            // ⚠ The Owner who asked authorises the closed month; checked again
            // when the second person's approval executes it (v2, C2).
            'owner_authorised_by' => $plan->dates->needsClosedPeriodPass && ! $plan->route->needsOwnerApproval() ? $actor->id : null,
            'requested_by' => $actor->id,
            'excluded_approvers' => $plan->excluded,
            'status' => CorrectionRequestStatus::Pending,
            'duplicate_ack' => $plan->duplicateAck === null ? [] : $plan->duplicateIds,
            'idempotency_key' => $input->idempotencyKey,
            'pending_key' => $pendingKey,
        ]);

        $request->submitForApproval($actor);

        CorrectionLog::event('requested', $actor, $document, [
            'request_id' => $request->id,
            'route' => $plan->route->value,
            'basis' => Tk::of($plan->basisMinor),
            'reason' => $input->reason->text,
        ]);

        $message = $plan->route->needsOwnerApproval()
            ? "Sent to the Owner. Nothing changes until the Owner approves it."
            : "Sent for approval. Nothing changes until a second person approves it.";

        return new CorrectionResult(CorrectionOutcome::Requested, null, $request, null, null, $message);
    }

    private function execute(
        CorrectionPlan $plan,
        DocumentCorrector $corrector,
        DocumentAction $action,
        ActionReason $reason,
        User $actor,
        ?User $approver,
        ?CorrectionRequest $request,
        string $idempotencyKey,
    ): CorrectionResult {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('A correction executes only inside its transaction.');
        }

        $document = $plan->document;
        $dates = $plan->dates;
        $pass = null;

        if ($dates->needsClosedPeriodPass) {
            $owner = match (true) {
                $request === null => $actor,
                $request->owner_authorised_by !== null => $request->ownerAuthorisedBy()->first(),
                default => $approver,
            };

            if ($owner === null) {
                throw new ClosedPeriodPassException('Only the Owner can correct a closed month.');
            }

            $pass = $this->exceptions->grant(
                original: $document,
                originalNumber: $plan->number,
                action: $action->value,
                successorType: (new ($corrector->successorClass()))->getMorphClass(),
                dates: $dates->closedDates,
                maxJournals: $plan->plannedClosedJournals,
                owner: $owner,
                executedBy: $approver ?? $actor,
                reason: $reason,
                request: $request,
            );
        }

        $ctx = new CommitContext($actor, $approver, $reason, $dates, $pass, $idempotencyKey, $plan->number);

        $originalJournal = $corrector->journalOf($document);
        $reversal = $corrector->reverse($document, $ctx);
        $successor = $action === DocumentAction::Correct ? $corrector->createSuccessor($document, $plan->replacement, $ctx) : null;
        $corrector->markOriginal($document, $reversal, $ctx);
        $successorJournal = $successor === null ? null : $corrector->journalOfSuccessor($successor);
        $policy = $corrector->policy();

        $link = new CorrectionLink;
        $link->forceFill([
            'business_id' => (int) app(BusinessContext::class)->id(),
            'action' => $action->value,
            'original_type' => $document->getMorphClass(),
            'original_id' => $document->getKey(),
            'original_number' => $plan->number,
            'original_date' => $corrector->originalDate($document)->toDateString(),
            'original_journal_id' => $originalJournal?->id,
            'reversal_journal_id' => $reversal?->id,
            'successor_type' => $successor?->getMorphClass(),
            'successor_id' => $successor?->getKey(),
            'successor_number' => $successor === null ? null : $policy->numberOf($successor),
            'successor_date' => $successor === null ? null : $corrector->originalDate($successor)->toDateString(),
            'successor_journal_id' => $successorJournal?->id,
            'reason_id' => $reason->reasonId,
            'reason_text' => $reason->text,
            'actor_id' => $actor->id,
            'approved_by' => $approver?->id,
            'correction_request_id' => $request?->id,
            'date_used' => $dates->reversalDate->toDateString(),
            'date_rule' => $dates->rule,
            'closed_period_exception' => $pass !== null,
            'period_exception_id' => $pass?->exceptionId,
            'amount_basis_minor' => $plan->basisMinor,
            'original_minor' => $plan->originalMinor,
            'successor_minor' => $plan->successorMinor,
            'idempotency_key' => $idempotencyKey,
            'live_key' => CorrectionLink::liveKeyFor($document),
            'created_at' => now(),
        ])->save();

        if ($pass !== null) {
            $this->exceptions->close($pass, $link, $plan->plannedClosedJournals);
        }

        $this->assertSharedInvariants($originalJournal, $reversal, $successorJournal);
        $corrector->assertInvariants($document->fresh(), $successor?->fresh());

        if ($plan->duplicateAck !== null && $successor !== null) {
            $this->duplicates->record($plan->duplicateAck, $successor);
        }

        $properties = array_filter([
            'link_id' => $link->id,
            'reason' => $reason->text,
            'reversal_date' => $dates->reversalDate->toDateString(),
            'successor_date' => $dates->successorDate?->toDateString(),
            'closed_period_exception' => $pass !== null,
            'approved_by' => $approver?->name,
            'successor' => $link->successor_number,
        ], fn ($v) => $v !== null);

        CorrectionLog::event($successor === null ? 'reversed' : 'corrected', $actor, $document, $properties);

        if ($successor !== null) {
            CorrectionLog::event('correction_of', $actor, $successor, $properties + ['original' => $plan->number]);
        }

        $message = $successor === null
            ? "{$plan->number} reversed."
            : "{$plan->number} corrected. The new record is {$link->successor_number}.";

        return new CorrectionResult(CorrectionOutcome::Executed, $link, $request, $successor, $reversal, $message);
    }

    /**
     * v2 (C11): what is true of every correction, whatever the type. Every
     * journal posted balances, and the reversal mirrors the original exactly on
     * every account. "Document = journal" is each corrector's own invariant.
     */
    private function assertSharedInvariants(?Journal $original, ?Journal $reversal, ?Journal $successor): void
    {
        foreach (array_filter([$reversal, $successor]) as $journal) {
            if ((int) $journal->lines()->sum('amount_minor') !== 0) {
                throw new LogicException("Journal {$journal->journal_number} does not balance.");
            }
        }

        if ($original === null || $reversal === null) {
            return;
        }

        if ((int) $reversal->reverses_journal_id !== (int) $original->id) {
            throw new LogicException('The reversal does not point at the journal it reverses.');
        }

        $net = DB::table('journal_lines')
            ->whereIn('journal_id', [$original->id, $reversal->id])
            ->groupBy('account_id')
            ->havingRaw('SUM(amount_minor) <> 0')
            ->count();

        if ($net !== 0) {
            throw new LogicException('The reversal does not mirror the original on every account.');
        }
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    /** @return array{0: DocumentActionPolicy, 1: DocumentCorrector, 2: Model} */
    private function resolve(string $type, int $documentId, User $user): array
    {
        if (! $this->registry->has($type)) {
            throw new NotFoundHttpException;
        }

        $policy = $this->registry->for($type);
        $corrector = $this->registry->corrector($type);
        $document = $corrector->find($documentId) ?? throw new NotFoundHttpException;

        if (! $policy->canView($document, $user)) {
            throw new NotFoundHttpException;
        }

        return [$policy, $corrector, $document];
    }

    private function authorise(DocumentActionPolicy $policy, Model $document, User $user, DocumentAction $action): void
    {
        $availability = $policy->availability($document, $user, $action);

        if (! $user->can($availability->permission)) {
            throw new AuthorizationException("You are not allowed to {$action->value} this record.");
        }
    }

    private function replay(CorrectionInput $input, int $businessId): ?CorrectionResult
    {
        $link = CorrectionLink::query()->where('business_id', $businessId)->where('idempotency_key', $input->idempotencyKey)->first();

        if ($link !== null) {
            $this->assertSameDocument($input, $link->original_type, (int) $link->original_id);

            return new CorrectionResult(CorrectionOutcome::AlreadyDone, $link, null, null, null, $link->successor_number === null
                ? "{$link->original_number} reversed."
                : "{$link->original_number} corrected. The new record is {$link->successor_number}.");
        }

        $request = CorrectionRequest::query()->where('business_id', $businessId)->where('idempotency_key', $input->idempotencyKey)->first();

        if ($request !== null) {
            $this->assertSameDocument($input, $request->document_type, (int) $request->document_id);

            return new CorrectionResult(CorrectionOutcome::AlreadyDone, $request->executedLink, $request, null, null, 'Already sent for approval.');
        }

        return null;
    }

    private function assertSameDocument(CorrectionInput $input, string $morph, int $id): void
    {
        $type = $this->registry->typeOfMorph($morph);

        if ($type !== $input->type || $id !== $input->documentId) {
            throw new PreviewExpiredException('reused_key');
        }
    }

    private function confirmLabel(DocumentAction $action, ApprovalRoute $route): string
    {
        return match ($route) {
            ApprovalRoute::None => $action->label(),
            ApprovalRoute::SecondPerson => 'Send for approval',
            ApprovalRoute::OwnerClosedPeriod, ApprovalRoute::Both => 'Ask the Owner',
        };
    }

    /** @param list<int> $excluded */
    private function warnings(ApprovalRoute $route, CorrectionDates $dates, array $excluded, Model $document): array
    {
        $warnings = [];
        $limit = Tk::of($this->secondPerson->limitMinor((int) app(BusinessContext::class)->id()));

        if ($route->needsSecondPerson()) {
            $makers = User::query()->whereIn('id', $excluded)->pluck('name')->filter()->implode(' or ');
            $warnings[] = "This moves more than {$limit}, so a second person must approve it. Nothing changes until they do. You cannot approve it yourself"
                .($makers === '' ? '.' : ", and neither can {$makers}, who recorded or last changed the original.");
        }

        if ($route->needsOwnerApproval()) {
            $warnings[] = 'Only the Owner can correct a closed month. This goes to the Owner, and nothing changes until the Owner approves it.';
        } elseif ($dates->needsClosedPeriodPass) {
            $warnings[] = 'This changes a closed month. It will be listed in the Corrections report, and the month\'s figures will change.';
        }

        return $warnings;
    }

    private function notifyApprovers(CorrectionRequest $request): void
    {
        try {
            $approval = $request->approvalRequest()->first();

            if ($approval !== null) {
                app(NotifyStageApprovers::class)->handle($approval, $request->summary().', asked by '.$request->requestedBy?->name);
            }
        } catch (Throwable $e) {
            // ⚠ A mail failure never undoes a request that is already saved.
            Log::warning('Correction request notification failed', ['request' => $request->id, 'error' => $e->getMessage()]);
        }
    }

    /** For tests and the preflight: a Carbon of the document's own date. */
    public function originalDateOf(string $type, Model $document): Carbon
    {
        return $this->registry->corrector($type)->originalDate($document);
    }
}
