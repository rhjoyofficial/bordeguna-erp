<?php

namespace App\Modules\Sales\Actions\Commission;

use App\Models\User;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Models\CommissionAgreement;
use App\Modules\Sales\Support\CommissionTracker;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Make or change a commission agreement (Joy's Q18, 25 Oct 2026).
 *
 * `$data` is already validated by the controller (shape and existence); this
 * holds the rules that need the record: the deal's own sense, and what may
 * still change once money has been settled under it.
 *
 * ⚠ Once a live settlement exists only the end date and the note may change:
 * the settled money was worked out on these terms. The end date may not cut
 * off sales already settled. A new deal is a new agreement.
 *
 * ⚠ A target deal promising more than the Finance policy's second-approval
 * limit waits for a second person (the voucher workflow); below it, or a deal
 * without an amount of its own, is active at once.
 */
class SaveCommissionAgreement
{
    public function __construct(
        private readonly NotifyStageApprovers $notify,
        private readonly CommissionTracker $tracker,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User $by): CommissionAgreement
    {
        $this->assertSensible($data);
        $businessId = (int) app(BusinessContext::class)->id();

        $agreement = DocumentNumber::claim('number', fn () => $this->nextNumber($businessId), function (string $number) use ($data, $by, $businessId) {
            $agreement = CommissionAgreement::create([
                'business_id' => $businessId,
                'number' => $number,
                'created_by' => $by->id,
                'status' => CommissionAgreement::ACTIVE,
                ...$this->columns($data),
            ]);
            $this->writeLines($agreement, $data);
            $this->routeForApproval($agreement->fresh(['tiers']), $by);

            return $agreement;
        });

        $this->notifyIfWaiting($agreement->fresh(['tiers', 'approvalRequest']));

        return $agreement->fresh(['items', 'tiers']);
    }

    /** @param  array<string, mixed>  $data */
    public function update(CommissionAgreement $agreement, array $data, User $by): CommissionAgreement
    {
        $saved = DB::transaction(function () use ($agreement, $data, $by) {
            /** @var CommissionAgreement $agreement */
            $agreement = CommissionAgreement::query()->whereKey($agreement->id)->lockForUpdate()->firstOrFail();

            if (! in_array($agreement->status, [CommissionAgreement::ACTIVE, CommissionAgreement::PENDING], true)) {
                throw ValidationException::withMessages(['agreement' => "{$agreement->number} is {$agreement->statusLabel()}, so it cannot be changed. Make a new agreement."]);
            }

            $settledThrough = $this->tracker->lastSettledThrough($agreement);

            if ($settledThrough !== null) {
                $validTo = ($data['valid_to'] ?? null) === null ? null : Carbon::parse($data['valid_to'])->startOfDay();

                if ($validTo !== null && $validTo->lt($settledThrough)) {
                    throw ValidationException::withMessages(['valid_to' => "Sales through {$settledThrough->format('d/m/Y')} are already settled, so the agreement cannot end before that day."]);
                }

                // Settled money was worked out on these terms: only the end
                // date and the note may still change.
                $agreement->update(['valid_to' => $validTo, 'note' => $data['note'] ?? null]);

                return $agreement;
            }

            $this->assertSensible($data);
            $agreement->update($this->columns($data));
            $agreement->items()->delete();
            $agreement->tiers()->delete();
            $this->writeLines($agreement, $data);

            if ($agreement->status === CommissionAgreement::ACTIVE) {
                $this->routeForApproval($agreement->fresh(['tiers']), $by);
            }

            return $agreement;
        });

        $this->notifyIfWaiting($saved->fresh(['tiers', 'approvalRequest']));

        return $saved->fresh(['items', 'tiers']);
    }

    /** The second person says yes: the agreement becomes active (nothing posts). */
    public function approve(CommissionAgreement $agreement, User $by, ?string $note = null): bool
    {
        $approval = $this->pendingRequest($agreement, $by);

        return DB::transaction(function () use ($agreement, $approval, $by, $note) {
            $agreement = CommissionAgreement::query()->whereKey($agreement->id)->lockForUpdate()->firstOrFail();

            if (! $agreement->isPending()) {
                throw ValidationException::withMessages(['agreement' => "{$agreement->number} is no longer waiting for approval."]);
            }

            if (! $approval->approve($by, $note)) {
                return false;
            }

            $agreement->update(['status' => CommissionAgreement::ACTIVE, 'decision_note' => $note]);

            return true;
        });
    }

    public function reject(CommissionAgreement $agreement, User $by, ?string $note): void
    {
        $approval = $this->pendingRequest($agreement, $by);

        DB::transaction(function () use ($agreement, $approval, $by, $note) {
            $approval->reject($by, $note);
            $agreement->update(['status' => CommissionAgreement::REJECTED, 'decision_note' => $note]);
        });
    }

    private function pendingRequest(CommissionAgreement $agreement, User $by): ApprovalRequest
    {
        $approval = $agreement->approvalRequest;

        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($by), 403);

        return $approval;
    }

    /** @param  array<string, mixed>  $data */
    private function assertSensible(array $data): void
    {
        $from = Carbon::parse($data['valid_from'])->startOfDay();

        if (($data['valid_to'] ?? null) !== null && Carbon::parse($data['valid_to'])->lt($from)) {
            throw ValidationException::withMessages(['valid_to' => 'The end date cannot be before the start date.']);
        }

        if ($data['deal_type'] === 'target') {
            if (($data['valid_to'] ?? null) === null) {
                throw ValidationException::withMessages(['valid_to' => 'A target deal needs an end date: the target is reached inside these dates.']);
            }

            if (($data['tiers'] ?? []) === []) {
                throw ValidationException::withMessages(['tiers' => 'Add at least one step: sell this much, get this amount.']);
            }

            $thresholds = array_map(fn ($tier) => (float) $tier['threshold'], $data['tiers']);

            if (count($thresholds) !== count(array_unique($thresholds))) {
                throw ValidationException::withMessages(['tiers' => 'Two steps have the same target. Give each step its own target.']);
            }
        } elseif (! isset($data['rate']) || (float) $data['rate'] <= 0) {
            throw ValidationException::withMessages(['rate' => $data['deal_type'] === 'percent' ? 'Type the percent.' : 'Type the amount per unit.']);
        }

        if ($data['deal_type'] === 'percent' && (float) $data['rate'] > 100) {
            throw ValidationException::withMessages(['rate' => 'A percent cannot be more than 100.']);
        }

        if (! ($data['counts_credited_sales'] ?? false) && ! ($data['counts_own_purchases'] ?? false)) {
            throw ValidationException::withMessages(['counts_credited_sales' => 'Choose which sales count.']);
        }

        if (($data['counts_own_purchases'] ?? false) && $data['party_type'] !== 'customer') {
            throw ValidationException::withMessages(['counts_own_purchases' => "Only a customer's agreement can count their own purchases."]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        $type = $data['party_type'];

        return [
            'agreed_on' => $data['agreed_on'],
            'party_type' => $type,
            'employee_id' => $type === 'employee' ? $data['party_id'] : null,
            'customer_id' => $type === 'customer' ? $data['party_id'] : null,
            'supplier_id' => $type === 'supplier' ? $data['party_id'] : null,
            'valid_from' => $data['valid_from'],
            'valid_to' => $data['valid_to'] ?? null,
            'deal_type' => $data['deal_type'],
            'rate' => $data['deal_type'] === 'target' ? null : $data['rate'],
            'target_basis' => $data['deal_type'] === 'target' ? ($data['target_basis'] ?? 'amount') : null,
            'counts_credited_sales' => (bool) ($data['counts_credited_sales'] ?? false),
            'counts_own_purchases' => $type === 'customer' && (bool) ($data['counts_own_purchases'] ?? false),
            'note' => $data['note'] ?? null,
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function writeLines(CommissionAgreement $agreement, array $data): void
    {
        foreach (array_unique(array_map('intval', $data['product_ids'] ?? [])) as $productId) {
            $agreement->items()->create(['product_id' => $productId]);
        }

        foreach (array_unique(array_map('intval', $data['category_ids'] ?? [])) as $categoryId) {
            $agreement->items()->create(['category_id' => $categoryId]);
        }

        if ($data['deal_type'] === 'target') {
            foreach ($data['tiers'] as $tier) {
                $agreement->tiers()->create(['threshold' => $tier['threshold'], 'reward' => $tier['reward']]);
            }
        }
    }

    private function routeForApproval(CommissionAgreement $agreement, User $by): void
    {
        $promised = $agreement->promisedMaximum();

        if ($promised === null) {
            return;
        }

        $limit = FinancePolicy::for((int) $agreement->business_id)->money('payment_second_approval_above_minor');

        if (! ($limit->minor > 0 && Money::ofTaka($promised)->minor > $limit->minor)) {
            return;
        }

        $agreement->update(['status' => CommissionAgreement::PENDING]);
        $agreement->resubmitForApproval($by);
    }

    private function notifyIfWaiting(CommissionAgreement $agreement): void
    {
        if ($agreement->isPending() && $agreement->approvalRequest !== null) {
            $this->notify->handle($agreement->approvalRequest, "Commission agreement {$agreement->number} ({$agreement->partyName()}, up to Tk ".Money::ofTaka((string) $agreement->promisedMaximum())->format().') is waiting for your approval');
        }
    }

    private function nextNumber(int $businessId): string
    {
        $last = CommissionAgreement::query()->withoutGlobalScopes()->where('business_id', $businessId)->count();

        return 'CA-'.str_pad((string) (((int) $last) + 1), 4, '0', STR_PAD_LEFT);
    }
}
