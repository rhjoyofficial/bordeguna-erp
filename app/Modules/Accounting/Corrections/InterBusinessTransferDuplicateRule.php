<?php

namespace App\Modules\Accounting\Corrections;

use App\Models\User;
use App\Modules\Accounting\Models\InterBusinessTransfer;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\Contracts\DuplicateRule;
use App\Modules\Foundation\Corrections\DuplicateCandidate;
use App\Modules\Foundation\Corrections\Tk;
use Illuminate\Support\Carbon;

/**
 * ⭐ Corrections wave 2 (B7, 24 Oct 2026; FINAL-PLAN 5, the money transfer
 * row applied to its group form): the same from and to accounts, the same
 * amount, within 3 days. A warning with "Save anyway", never a refusal.
 */
class InterBusinessTransferDuplicateRule implements DuplicateRule
{
    public const DAYS = 3;

    public function key(): string
    {
        return InterBusinessTransferActionPolicy::TYPE;
    }

    public function fingerprintOf(array $payload, int $businessId): array
    {
        return [
            'business' => $businessId,
            'from' => (int) ($payload['from_money_account_id'] ?? 0),
            'to' => (int) ($payload['to_money_account_id'] ?? 0),
            'amount' => is_numeric($payload['amount'] ?? null) ? Money::ofTaka((string) $payload['amount'])->minor : 0,
            'date' => Carbon::parse($payload['transferred_on'] ?? 'today')->toDateString(),
        ];
    }

    public function candidates(array $payload, int $businessId, User $viewer, array $exclude = []): array
    {
        $amount = is_numeric($payload['amount'] ?? null) ? Money::ofTaka((string) $payload['amount'])->minor : 0;

        if ($amount <= 0) {
            return [];
        }

        $date = Carbon::parse($payload['transferred_on'] ?? 'today')->startOfDay();
        $morph = (new InterBusinessTransfer)->getMorphClass();

        return InterBusinessTransfer::query()
            ->live()
            ->where('from_business_id', $businessId)
            ->where('from_money_account_id', (int) ($payload['from_money_account_id'] ?? 0))
            ->where('to_money_account_id', (int) ($payload['to_money_account_id'] ?? 0))
            ->whereBetween('transferred_on', [$date->copy()->subDays(self::DAYS)->toDateString(), $date->copy()->addDays(self::DAYS)->toDateString()])
            ->with(['toBusiness:id,name', 'createdBy:id,name'])
            ->orderBy('id')
            ->get()
            ->filter(fn (InterBusinessTransfer $t) => $t->money()->minor === $amount)
            ->reject(fn (InterBusinessTransfer $t) => in_array("{$morph}:{$t->id}", $exclude, true))
            ->map(fn (InterBusinessTransfer $t) => new DuplicateCandidate(
                type: $morph,
                id: (int) $t->id,
                number: 'IBT-'.str_pad((string) $t->id, 4, '0', STR_PAD_LEFT),
                summary: 'A transfer of '.Tk::of($amount).' to '.($t->toBusiness?->name ?? 'another business').' on '.Tk::date($t->transferred_on),
                savedBy: $t->createdBy?->name ?? 'somebody',
                savedAgo: $t->created_at?->diffForHumans() ?? '',
                status: 'Posted',
                visibleToUser: $viewer->can('accounting.view'),
                url: route('accounting.inter-business.index').'#transfer-'.$t->id,
            ))
            ->values()
            ->all();
    }
}
