<?php

namespace App\Support\Attention\Sources;

use App\Models\User;
use App\Support\Attention\AttentionItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Imported bank lines nobody has matched to a posting yet (the same rule as the bank widget). */
class BankUnmatchedSource extends Source
{
    public const LATE_DAYS = 7;

    public function key(): string
    {
        return 'bank_unmatched';
    }

    public function area(): string
    {
        return 'money';
    }

    protected function permissions(): array
    {
        return ['accounting.view'];
    }

    public function items(User $user, int $businessId): array
    {
        $query = DB::table('bank_statement_lines')
            ->where('business_id', $businessId)
            ->where('match_status', 'unmatched');

        $count = (clone $query)->count();

        if ($count === 0) {
            return [];
        }

        $oldest = Carbon::parse((string) (clone $query)->min('txn_date'));
        // FEA-007 (14 Oct 2026): open the account that holds the oldest
        // unmatched line, not whichever account sorts first.
        $accountId = (clone $query)->orderBy('txn_date')->orderBy('id')->value('money_account_id');
        $amount = (float) (clone $query)->sum(DB::raw('ABS(amount)'));

        return [$this->item(
            'lines',
            $oldest->lt(Carbon::today()->subDays(self::LATE_DAYS)) ? AttentionItem::HIGH : AttentionItem::NORMAL,
            'bank_unmatched',
            '{count} bank lines (Tk {amount}) are not matched to a posting.',
            ['count' => $count, 'amount' => self::money($amount)],
            '/accounting/bank-statements?money_account_id='.$accountId,
            ['since' => $oldest, 'ownerRole' => 'Finance', 'count' => $count],
        )];
    }
}
