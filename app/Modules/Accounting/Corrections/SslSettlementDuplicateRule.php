<?php

namespace App\Modules\Accounting\Corrections;

use App\Models\User;
use App\Modules\Accounting\Models\SslSettlement;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\Contracts\DuplicateRule;
use App\Modules\Foundation\Corrections\DuplicateCandidate;
use App\Modules\Foundation\Corrections\Tk;
use Illuminate\Support\Carbon;

/**
 * ⭐ Joy's answer 9 (25 Oct 2026): an SSL payout that looks like one already
 * recorded. The same bank and the same gross within 3 days, or the same SSL
 * reference at any date. A warning with "Save anyway", never a refusal.
 */
class SslSettlementDuplicateRule implements DuplicateRule
{
    public const DAYS = 3;

    public function key(): string
    {
        return SslSettlementActionPolicy::TYPE;
    }

    public function fingerprintOf(array $payload, int $businessId): array
    {
        return [
            'business' => $businessId,
            'bank' => (int) ($payload['bank_money_account_id'] ?? 0),
            'gross' => $this->grossMinor($payload),
            'reference' => trim((string) ($payload['reference'] ?? '')),
            'date' => Carbon::parse($payload['settled_on'] ?? 'today')->toDateString(),
        ];
    }

    public function candidates(array $payload, int $businessId, User $viewer, array $exclude = []): array
    {
        $gross = $this->grossMinor($payload);
        $reference = trim((string) ($payload['reference'] ?? ''));

        if ($gross <= 0) {
            return [];
        }

        $date = Carbon::parse($payload['settled_on'] ?? 'today')->startOfDay();
        $morph = (new SslSettlement)->getMorphClass();

        return SslSettlement::query()
            ->live()
            ->where('business_id', $businessId)
            ->where(function ($query) use ($payload, $date, $reference) {
                $query->where(fn ($q) => $q
                    ->where('bank_money_account_id', (int) ($payload['bank_money_account_id'] ?? 0))
                    ->whereBetween('settled_on', [$date->copy()->subDays(self::DAYS)->toDateString(), $date->copy()->addDays(self::DAYS)->toDateString()]));

                if ($reference !== '') {
                    $query->orWhere('reference', $reference);
                }
            })
            ->with(['bank:id,name', 'creator:id,name'])
            ->orderBy('id')
            ->get()
            ->filter(fn (SslSettlement $s) => Money::ofTaka((string) $s->gross)->minor === $gross || ($reference !== '' && $s->reference === $reference))
            ->reject(fn (SslSettlement $s) => in_array("{$morph}:{$s->id}", $exclude, true))
            ->map(fn (SslSettlement $s) => new DuplicateCandidate(
                type: $morph,
                id: (int) $s->id,
                number: (string) $s->number,
                summary: 'An SSL payout of '.Tk::of(Money::ofTaka((string) $s->gross)->minor).' gross into '.($s->bank?->name ?? 'a bank').' on '.Tk::date($s->settled_on).($s->reference ? " (ref {$s->reference})" : ''),
                savedBy: $s->creator?->name ?? 'somebody',
                savedAgo: $s->created_at?->diffForHumans() ?? '',
                status: 'Posted',
                visibleToUser: $viewer->can('accounting.view'),
                url: route('accounting.ssl-settlements.index').'#ssl-'.$s->id,
            ))
            ->values()
            ->all();
    }

    private function grossMinor(array $payload): int
    {
        $raw = $payload['gross'] ?? null;

        return is_numeric($raw) ? Money::ofTaka((string) $raw)->minor : 0;
    }
}
