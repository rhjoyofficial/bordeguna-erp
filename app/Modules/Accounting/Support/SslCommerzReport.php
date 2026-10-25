<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\SslSettlement;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ Joy's answer 9 (25 Oct 2026): "a report of how much we got and how much
 * SSL took". For a period:
 *
 *   in clearing at the start   the clearing account's ledger before `from`
 *   gross received             money marked into SSL Commerz Clearing in the
 *                              period (paid-online orders), settlements and
 *                              their reversals left out
 *   settled gross / SSL charge / net to bank
 *                              the SSL settlements dated in the period that
 *                              still stood at its end
 *   still in clearing          the clearing account's ledger at `to`: what SSL
 *                              still owes us
 *
 * Everything is read from the ledger lines stamped with the clearing account
 * and from the settlement rows, never typed.
 */
final class SslCommerzReport
{
    /**
     * @return array{
     *   clearing: ?string,
     *   from: string, to: string,
     *   opening: int, received: int, settled_gross: int, fee: int, net: int, closing: int,
     *   settlements: list<array<string, mixed>>
     * }
     */
    public static function for(int $businessId, Carbon $from, Carbon $to): array
    {
        $clearing = MoneyAccount::withoutGlobalScopes()->where('business_id', $businessId)->where('system_key', SslClearing::KEY)->first();
        $result = [
            'clearing' => $clearing?->name,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'opening' => 0, 'received' => 0, 'settled_gross' => 0, 'fee' => 0, 'net' => 0, 'closing' => 0,
            'settlements' => [],
        ];

        if ($clearing === null) {
            return $result;
        }

        $lines = fn () => DB::table('journal_lines')
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.business_id', $businessId)
            ->where('journal_lines.money_account_id', $clearing->id);

        $result['opening'] = (int) $lines()->where('journals.journal_date', '<', $from->toDateString())->sum('journal_lines.amount_minor');
        $result['closing'] = (int) $lines()->where('journals.journal_date', '<=', $to->toDateString())->sum('journal_lines.amount_minor');

        $morph = (new SslSettlement)->getMorphClass();
        $settlementJournals = DB::table('journals')->where('business_id', $businessId)->where('source_type', $morph)->select('id');

        $result['received'] = (int) $lines()
            ->whereBetween('journals.journal_date', [$from->toDateString(), $to->toDateString()])
            ->where(fn ($q) => $q->whereNull('journals.source_type')->orWhere('journals.source_type', '!=', $morph))
            ->where(fn ($q) => $q->whereNull('journals.reverses_journal_id')->orWhereNotIn('journals.reverses_journal_id', $settlementJournals))
            ->sum('journal_lines.amount_minor');

        $settlements = SslSettlement::withoutGlobalScopes()
            ->where('business_id', $businessId)
            ->whereBetween('settled_on', [$from->toDateString(), $to->toDateString()])
            ->standingOn($to->toDateString())
            ->with('bank:id,name')
            ->orderBy('settled_on')->orderBy('id')
            ->get();

        foreach ($settlements as $settlement) {
            $gross = Money::ofTaka((string) $settlement->gross)->minor;
            $fee = Money::ofTaka((string) $settlement->fee)->minor;
            $net = Money::ofTaka((string) $settlement->net)->minor;
            $result['settled_gross'] += $gross;
            $result['fee'] += $fee;
            $result['net'] += $net;
            $result['settlements'][] = [
                'id' => $settlement->id,
                'number' => $settlement->number,
                'settled_on' => $settlement->settled_on->toDateString(),
                'bank' => $settlement->bank?->name,
                'reference' => $settlement->reference,
                'gross' => Money::ofMinor($gross)->toTaka(),
                'fee' => Money::ofMinor($fee)->toTaka(),
                'net' => Money::ofMinor($net)->toTaka(),
            ];
        }

        return $result;
    }
}
