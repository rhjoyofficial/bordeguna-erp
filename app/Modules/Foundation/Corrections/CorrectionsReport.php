<?php

namespace App\Modules\Foundation\Corrections;

use App\Modules\Foundation\Models\ClosedPeriodException;
use App\Modules\Foundation\Models\CorrectionLink;
use App\Modules\Foundation\Models\CorrectionRequest;
use App\Modules\Foundation\Models\DuplicateAcknowledgement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * The monthly Corrections report (PHASE-0-CONTRACT v2, 1.14 and C16): every
 * correction, reversal, request, refusal, duplicate acknowledgement and
 * closed-month exception of one business in one calendar month, by the moment
 * it happened (never backdated).
 *
 * READ-ONLY. Each source is read separately and mapped to one row shape; the
 * volume is a month of one business, so a PHP merge is clearer than a UNION and
 * reads the same on MySQL and SQLite.
 */
final class CorrectionsReport
{
    public const KINDS = [
        'corrected' => 'Corrected',
        'reversed' => 'Reversed',
        'closed_month_exception' => 'Closed-month exception',
        'waiting_second_person' => 'Waiting for a second person',
        'waiting_owner' => 'Waiting for the Owner',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'withdrawn' => 'Withdrawn',
        'failed_stale' => 'Failed, the record changed',
        'blocked_attempt' => 'Blocked attempt',
        'saved_despite_duplicate' => 'Saved despite a possible duplicate',
        'period_closed' => 'Month closed',
        'period_unlocked' => 'Month unlocked',
        'period_relocked' => 'Month relocked',
    ];

    /** @return Collection<int, array> rows, newest first */
    public function forMonth(int $businessId, Carbon $month, ?string $kind = null): Collection
    {
        [$from, $to] = [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()];
        $registry = app(DocumentPolicyRegistry::class);
        $rows = collect();

        foreach (CorrectionLink::query()->withoutGlobalScopes()->where('business_id', $businessId)->whereBetween('created_at', [$from, $to])->with(['actor:id,name', 'approver:id,name', 'reason'])->get() as $link) {
            $rows->push($this->row($link->created_at, $link->successor_id === null ? 'reversed' : 'corrected', $link->original_number, $link->successor_number,
                $link->actor?->name, $link->approver?->name, $link->amount_basis_minor, $link->reasonDisplay(), $link->closed_period_exception,
                $this->url($registry, $link->original_type, (int) $link->original_id)));
        }

        foreach (ClosedPeriodException::query()->withoutGlobalScopes()->where('business_id', $businessId)->whereBetween('created_at', [$from, $to])->with(['grantedBy:id,name', 'executedBy:id,name'])->get() as $exception) {
            $rows->push($this->row($exception->created_at, 'closed_month_exception', $exception->original_number, null,
                $exception->grantedBy?->name, $exception->executedBy?->name, null,
                'Posted on '.implode(', ', $exception->dates ?? []).", {$exception->journals_posted} journal(s), {$exception->status}: {$exception->reason_text}", true, null));
        }

        foreach (CorrectionRequest::query()->withoutGlobalScopes()->where('business_id', $businessId)->whereBetween('created_at', [$from, $to])->with(['requestedBy:id,name', 'reason', 'approvalRequest.reviewedBy:id,name'])->get() as $request) {
            $kindOf = match ($request->status) {
                CorrectionRequestStatus::Pending => $request->approval_route->needsOwnerApproval() ? 'waiting_owner' : 'waiting_second_person',
                CorrectionRequestStatus::Executed => 'approved',
                CorrectionRequestStatus::Rejected => 'rejected',
                CorrectionRequestStatus::Withdrawn => 'withdrawn',
                CorrectionRequestStatus::FailedStale => 'failed_stale',
            };

            $rows->push($this->row($request->created_at, $kindOf, $request->document_number, null,
                $request->requestedBy?->name, $request->approvalRequest?->reviewedBy?->name, $request->amount_basis_minor,
                $request->reasonDisplay().($request->failure_message ? " ({$request->failure_message})" : ''),
                $request->approval_route->needsOwnerApproval() || $request->owner_authorised_by !== null,
                $this->url($registry, $request->document_type, (int) $request->document_id)));
        }

        $activities = Activity::query()
            ->whereIn('log_name', [CorrectionLog::NAME, 'accounting.period'])
            ->whereBetween('created_at', [$from, $to])
            ->with('causer')
            ->get();

        foreach ($activities as $activity) {
            $event = (string) ($activity->event ?? '');
            $description = (string) $activity->description;

            if ($activity->log_name === CorrectionLog::NAME && $event === 'blocked_attempt') {
                if (! $this->sameBusiness($activity, $businessId)) {
                    continue;
                }

                $rows->push($this->row($activity->created_at, 'blocked_attempt', (string) ($activity->properties['number'] ?? ''), null,
                    $activity->causer?->name, null, null, (string) ($activity->properties['message'] ?? ''), false, null));
            }

            if ($activity->log_name === 'accounting.period' && $this->causerInBusiness($activity, $businessId)) {
                $periodKind = match (true) {
                    str_starts_with($description, 'Unlocked') => 'period_unlocked',
                    str_starts_with($description, 'Relocked') => 'period_relocked',
                    str_starts_with($description, 'Closed') => 'period_closed',
                    default => null,
                };

                if ($periodKind !== null) {
                    $rows->push($this->row($activity->created_at, $periodKind, $description, null, $activity->causer?->name, null, null, (string) ($activity->properties['note'] ?? ''), false, null));
                }
            }
        }

        foreach (DuplicateAcknowledgement::query()->withoutGlobalScopes()->where('business_id', $businessId)->whereBetween('created_at', [$from, $to])->with('user:id,name')->get() as $ack) {
            $numbers = collect($ack->candidates ?? [])->pluck('number')->filter()->implode(', ');
            $saved = $this->number($registry, $ack->subject_type, (int) $ack->subject_id);
            $rows->push($this->row($ack->created_at, 'saved_despite_duplicate', $saved ?? '', null, $ack->user?->name, null, null, "Looked like {$numbers}", false,
                $this->url($registry, $ack->subject_type, (int) $ack->subject_id)));
        }

        return $rows
            ->when($kind !== null && $kind !== '', fn (Collection $c) => $c->where('kind', $kind))
            ->sortByDesc('at_sort')
            ->values()
            ->map(function (array $row) {
                unset($row['at_sort']);

                return $row;
            });
    }

    /** @return array<string, int> */
    public function summary(int $businessId, Carbon $month): array
    {
        return $this->forMonth($businessId, $month)->countBy('kind')->all();
    }

    private function row(?Carbon $at, string $kind, string $document, ?string $successor, ?string $actor, ?string $approver, ?int $basis, string $reason, bool $closed, ?string $url): array
    {
        $at ??= now();

        return [
            'at' => $at->format('j M Y, g:i a'),
            'at_sort' => $at->getTimestamp(),
            'kind' => $kind,
            'kind_label' => self::KINDS[$kind] ?? $kind,
            'document' => $document,
            'successor' => $successor,
            'actor' => $actor,
            'approver' => $approver,
            'amount_basis' => $basis === null ? null : Tk::of($basis),
            'reason' => $reason,
            'closed_period_exception' => $closed,
            'url' => $url,
        ];
    }

    private function url(DocumentPolicyRegistry $registry, ?string $morph, int $id): ?string
    {
        if ($morph === null || ($type = $registry->typeOfMorph($morph)) === null) {
            return null;
        }

        $class = $registry->for($type)->modelClass();
        $model = $class::query()->withoutGlobalScopes()->find($id);

        return $model === null ? null : $registry->for($type)->urlOf($model);
    }

    private function number(DocumentPolicyRegistry $registry, ?string $morph, int $id): ?string
    {
        if ($morph === null || ($type = $registry->typeOfMorph($morph)) === null) {
            return null;
        }

        $class = $registry->for($type)->modelClass();
        $model = $class::query()->withoutGlobalScopes()->find($id);

        return $model === null ? null : $registry->for($type)->numberOf($model);
    }

    /** A blocked attempt belongs to the business of the record it was about. */
    private function sameBusiness(Activity $activity, int $businessId): bool
    {
        if ($activity->subject_type === null) {
            return false;
        }

        $class = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($activity->subject_type) ?? $activity->subject_type;
        $subject = class_exists($class) ? $class::query()->withoutGlobalScopes()->find($activity->subject_id) : null;

        return $subject !== null && (int) ($subject->business_id ?? 0) === $businessId;
    }

    private function causerInBusiness(Activity $activity, int $businessId): bool
    {
        $causer = $activity->causer;

        return $causer !== null && method_exists($causer, 'businesses') && $causer->businesses()->where('businesses.id', $businessId)->exists();
    }
}
