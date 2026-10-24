<?php

namespace App\Modules\Accounting\Corrections;

use App\Models\User;
use App\Modules\Accounting\Models\MoneyTransfer;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\Contracts\DuplicateRule;
use App\Modules\Foundation\Corrections\DuplicateCandidate;
use App\Modules\Foundation\Corrections\Tk;
use Illuminate\Support\Carbon;

/**
 * ⭐ Corrections wave 2 (B7, 24 Oct 2026; FINAL-PLAN 5): a transfer that looks
 * like one already recorded. The same from and to accounts, the same amount,
 * within 3 days. A warning with "Save anyway", never a refusal.
 */
class MoneyTransferDuplicateRule implements DuplicateRule
{
    public const DAYS = 3;

    public function key(): string
    {
        return MoneyTransferActionPolicy::TYPE;
    }

    public function fingerprintOf(array $payload, int $businessId): array
    {
        return [
            'business' => $businessId,
            'from' => (int) ($payload['from_money_account_id'] ?? 0),
            'to' => (int) ($payload['to_money_account_id'] ?? 0),
            'amount' => $this->amountMinor($payload),
            'date' => Carbon::parse($payload['transferred_on'] ?? 'today')->toDateString(),
        ];
    }

    public function candidates(array $payload, int $businessId, User $viewer, array $exclude = []): array
    {
        $amount = $this->amountMinor($payload);

        if ($amount <= 0 || empty($payload['from_money_account_id']) || empty($payload['to_money_account_id'])) {
            return [];
        }

        $date = Carbon::parse($payload['transferred_on'] ?? 'today')->startOfDay();
        $morph = (new MoneyTransfer)->getMorphClass();

        return MoneyTransfer::query()
            ->live()
            ->where('business_id', $businessId)
            ->where('from_money_account_id', (int) $payload['from_money_account_id'])
            ->where('to_money_account_id', (int) $payload['to_money_account_id'])
            ->whereBetween('transferred_on', [$date->copy()->subDays(self::DAYS)->toDateString(), $date->copy()->addDays(self::DAYS)->toDateString()])
            ->with(['from:id,name', 'to:id,name', 'creator:id,name'])
            ->orderBy('id')
            ->get()
            ->filter(fn (MoneyTransfer $t) => Money::ofTaka((string) $t->amount)->minor === $amount)
            ->reject(fn (MoneyTransfer $t) => in_array("{$morph}:{$t->id}", $exclude, true))
            ->map(fn (MoneyTransfer $t) => new DuplicateCandidate(
                type: $morph,
                id: (int) $t->id,
                number: (string) $t->transfer_number,
                summary: 'A transfer of '.Tk::of($amount).' from '.($t->from?->name ?? 'an account').' to '.($t->to?->name ?? 'an account').' on '.Tk::date($t->transferred_on),
                savedBy: $t->creator?->name ?? 'somebody',
                savedAgo: $t->created_at?->diffForHumans() ?? '',
                status: 'Posted',
                visibleToUser: $viewer->can('accounting.view'),
                url: route('accounting.money-transfers.index').'#transfer-'.$t->id,
            ))
            ->values()
            ->all();
    }

    private function amountMinor(array $payload): int
    {
        $raw = $payload['amount'] ?? null;

        return is_numeric($raw) ? Money::ofTaka((string) $raw)->minor : 0;
    }
}
