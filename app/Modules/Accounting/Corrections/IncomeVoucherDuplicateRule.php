<?php

namespace App\Modules\Accounting\Corrections;

use App\Models\User;
use App\Modules\Accounting\Models\IncomeVoucher;
use App\Modules\Accounting\Support\IncomeVoucherRules;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\Contracts\DuplicateRule;
use App\Modules\Foundation\Corrections\DuplicateCandidate;
use App\Modules\Foundation\Corrections\Tk;
use Illuminate\Support\Carbon;

/**
 * Other income that looks like income already recorded (PHASE-0-CONTRACT 1.11):
 * the same income head, the same amount, within 3 days. Applies to a new
 * voucher and to a correction's successor (never warning about its own
 * original or chain).
 */
class IncomeVoucherDuplicateRule implements DuplicateRule
{
    public const DAYS = 3;

    public function key(): string
    {
        return IncomeVoucherActionPolicy::TYPE;
    }

    public function fingerprintOf(array $payload, int $businessId): array
    {
        $heads = array_map('intval', array_column($payload['lines'] ?? [], 'account_id'));
        sort($heads);

        return [
            'business' => $businessId,
            'heads' => $heads,
            'total' => IncomeVoucherRules::totalMinor($payload),
            'date' => Carbon::parse($payload['received_on'] ?? 'today')->toDateString(),
            'money_account' => (int) ($payload['money_account_id'] ?? 0),
        ];
    }

    public function candidates(array $payload, int $businessId, User $viewer, array $exclude = []): array
    {
        $date = Carbon::parse($payload['received_on'] ?? 'today')->startOfDay();
        $heads = array_map('intval', array_column($payload['lines'] ?? [], 'account_id'));
        $totalMinor = IncomeVoucherRules::totalMinor($payload);

        if ($heads === [] || $totalMinor <= 0) {
            return [];
        }

        $morph = (new IncomeVoucher)->getMorphClass();

        return IncomeVoucher::query()
            ->live()
            ->where('business_id', $businessId)
            ->whereBetween('received_on', [$date->copy()->subDays(self::DAYS)->toDateString(), $date->copy()->addDays(self::DAYS)->toDateString()])
            ->whereHas('lines', fn ($q) => $q->whereIn('account_id', $heads))
            ->with(['lines.account:id,name', 'moneyAccount:id,name', 'createdBy:id,name'])
            ->orderBy('id')
            ->get()
            ->filter(fn (IncomeVoucher $v) => Money::ofTaka((string) $v->total)->minor === $totalMinor)
            ->reject(fn (IncomeVoucher $v) => in_array("{$morph}:{$v->id}", $exclude, true))
            ->map(fn (IncomeVoucher $v) => new DuplicateCandidate(
                type: $morph,
                id: (int) $v->id,
                number: (string) $v->voucher_number,
                summary: Tk::of(Money::ofTaka((string) $v->total)->minor).' to '.$v->lines->map(fn ($l) => $l->account?->name)->filter()->unique()->implode(', ')
                    .' into '.($v->moneyAccount?->name ?? 'a money account').' on '.Tk::date($v->received_on),
                savedBy: $v->createdBy?->name ?? 'somebody',
                savedAgo: $v->created_at?->diffForHumans() ?? '',
                status: 'Posted',
                visibleToUser: $viewer->can('accounting.view'),
                url: route('accounting.other-income.print', $v),
            ))
            ->values()
            ->all();
    }
}
