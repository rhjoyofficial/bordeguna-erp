<?php

namespace App\Modules\Sales\Support;

use App\Modules\Accounting\Support\Money;
use App\Modules\Sales\Models\CommissionAgreement;
use App\Modules\Sales\Models\CommissionPayment;
use App\Modules\Sales\Models\CommissionSettlement;
use Illuminate\Support\Carbon;

/**
 * ⭐ Commission earned, settled, paid and owed, by person, for a period (Joy's
 * Q18, 25 Oct 2026).
 *
 *   earned    what the person's agreements earned on sales in the period
 *             (cumulative earned at the end less at the day before the start,
 *             so a target reached in the period lands in the period)
 *   settled   settlements dated in the period that stand (posted, not reversed)
 *   paid      money that left in the period: settlements paid now, payments of
 *             owed commission, and settlements put on payroll
 *   owed      today, what posted owed settlements still wait to be paid
 *   to settle today, what was earned and is not settled yet
 *
 * ⚠ Reversed documents are left out entirely (the report reads what stands).
 */
final class CommissionReport
{
    public function __construct(private readonly CommissionTracker $tracker) {}

    /** @return list<array<string, mixed>> */
    public function rows(Carbon $from, Carbon $to): array
    {
        $agreements = CommissionAgreement::query()
            ->where('status', CommissionAgreement::ACTIVE)
            ->with(['items', 'tiers', 'employee', 'customer', 'supplier'])
            ->orderBy('id')
            ->get();

        $people = [];

        foreach ($agreements as $agreement) {
            $key = $agreement->party_type.':'.$agreement->partyId();
            $people[$key] ??= [
                'key' => $key,
                'name' => $agreement->partyName(),
                'party_type' => $agreement->party_type,
                'agreements' => [],
                'earned' => Money::zero(), 'settled' => Money::zero(), 'paid' => Money::zero(),
                'owed' => Money::zero(), 'to_settle' => Money::zero(),
            ];

            $earned = $this->tracker->earnedThrough($agreement, $to->copy())
                ->plus($this->tracker->earnedThrough($agreement, $from->copy()->subDay())->negated());

            $settlements = CommissionSettlement::query()
                ->where('commission_agreement_id', $agreement->id)
                ->where('status', CommissionSettlement::POSTED)
                ->whereNull('reversed_at')
                ->get();

            $inPeriod = $settlements->filter(fn (CommissionSettlement $s) => $s->settled_on->betweenIncluded($from, $to));
            $settled = Money::sum($inPeriod->map(fn (CommissionSettlement $s) => $s->money()));
            $paidAtOnce = Money::sum($inPeriod->where('method', '!=', 'payable')->map(fn (CommissionSettlement $s) => $s->money()));
            $payments = Money::sum(CommissionPayment::query()
                ->whereIn('commission_settlement_id', $settlements->modelKeys() ?: [0])
                ->whereNull('reversed_at')
                ->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])
                ->pluck('amount')
                ->map(fn ($amount) => Money::ofTaka((string) $amount)));
            $owed = Money::sum($settlements->where('method', 'payable')->map(fn (CommissionSettlement $s) => $s->owed()));
            $progress = $this->tracker->progress($agreement);

            $person = &$people[$key];
            $person['agreements'][] = ['id' => $agreement->id, 'number' => $agreement->number];
            $person['earned'] = $person['earned']->plus($earned);
            $person['settled'] = $person['settled']->plus($settled);
            $person['paid'] = $person['paid']->plus($paidAtOnce)->plus($payments);
            $person['owed'] = $person['owed']->plus($owed);
            $person['to_settle'] = $person['to_settle']->plus(Money::ofTaka($progress['to_settle']));
            unset($person);
        }

        return array_values(array_map(fn (array $p) => [
            ...$p,
            'earned' => $p['earned']->toTaka(), 'settled' => $p['settled']->toTaka(), 'paid' => $p['paid']->toTaka(),
            'owed' => $p['owed']->toTaka(), 'to_settle' => $p['to_settle']->toTaka(),
        ], $people));
    }
}
