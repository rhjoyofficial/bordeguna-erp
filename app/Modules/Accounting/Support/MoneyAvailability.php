<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\MoneyAccount;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Is the money there?" before a document pays it out (finance policy B14,
 * 29 Sep 2026).
 *
 * A cash box and a mobile wallet cannot pay money they do not hold, so their
 * floor is zero. A bank may go below zero only as far as its sanctioned
 * overdraft (Bank & cash > the account > Overdraft limit). Courier and gateway
 * accounts are settled by their own statements and share one ledger head
 * each, so they are not judged here.
 *
 * ⚠⚠ THE LOWEST RUNNING BALANCE FROM THE DOCUMENT'S DATE ONWARDS is checked,
 * not only the balance on that date: a payment back-dated into a week where
 * the box was full must not overdraw a day after it that later payments had
 * already emptied.
 */
final class MoneyAvailability
{
    public static function balanceMinor(MoneyAccount $account, ?CarbonInterface $upTo = null): int
    {
        if ($account->account_id === null) {
            return 0;
        }

        $query = self::linesOf($account);

        if ($upTo !== null) {
            $query->whereDate('journals.journal_date', '<=', $upTo->toDateString());
        }

        return (int) $query->sum('journal_lines.amount_minor');
    }

    /**
     * The lowest the running balance stands on any day from $date onwards.
     *
     * ⚠ Not just "on the date" and "today": spent on the 15th and refilled on
     * the 20th, a payment dated the 12th overdraws the 15th-19th although both
     * ends look fine.
     */
    public static function lowestFromMinor(MoneyAccount $account, CarbonInterface $date): int
    {
        $running = self::balanceMinor($account, $date);

        if ($account->account_id === null) {
            return $running;
        }

        $lowest = $running;

        $later = self::linesOf($account)
            ->whereDate('journals.journal_date', '>', $date->toDateString())
            ->groupBy('journals.journal_date')
            ->orderBy('journals.journal_date')
            ->selectRaw('journals.journal_date, SUM(journal_lines.amount_minor) AS movement')
            ->pluck('movement');

        foreach ($later as $movement) {
            $running += (int) $movement;
            $lowest = min($lowest, $running);
        }

        return $lowest;
    }

    /**
     * The journal lines that belong to one money account, joined to their journal
     * for its date.
     *
     * ⚠⚠ A courier or gateway account does NOT own its head. Every courier
     * reports under 1145 and every gateway under 1146 (MoneyLedgers), so the head
     * alone answered "what all couriers together hold" for each one of them:
     * Pathao showed Pathao's money plus Steadfast's (Slice 0, D4). For those two
     * kinds the line's own money_account_id says whose money it is. A cash, bank
     * or MFS account owns its head outright, so the head is still the answer
     * there, including any line posted to it without naming the account.
     */
    private static function linesOf(MoneyAccount $account): Builder
    {
        $query = DB::table('journal_lines')
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.business_id', $account->business_id)
            ->where('journal_lines.account_id', $account->account_id);

        if ($account->isCourier() || $account->isGateway()) {
            $query->where('journal_lines.money_account_id', $account->id);
        }

        return $query;
    }

    /** The lowest the balance may go, in paisa (zero or a negative overdraft). */
    public static function floorMinor(MoneyAccount $account): int
    {
        return $account->type === MoneyAccountType::Bank ? -((int) $account->overdraft_limit_minor) : 0;
    }

    /**
     * @throws ValidationException
     */
    public static function assertCanPay(MoneyAccount $account, Money $amount, CarbonInterface $date, string $field): void
    {
        if ($account->isCourier() || $account->isGateway() || $amount->minor <= 0) {
            return;
        }

        // ⚠ Off ONLY in the test suite (phpunit.xml), where hundreds of tests pay
        // from accounts they never funded to check a posting's shape. The guard's
        // own tests switch it back on; production has no such variable.
        if (! config('accounting.money_availability', true)) {
            return;
        }

        if (! FinancePolicy::for((int) $account->business_id)->refuse_negative_money) {
            return;
        }

        $floor = self::floorMinor($account);
        $available = self::lowestFromMinor($account, $date);

        if ($available - $amount->minor >= $floor) {
            return;
        }

        $holds = Money::ofMinor($available)->format();
        $pay = $amount->format();
        $limit = $floor < 0 ? ' plus its overdraft limit of '.Money::ofMinor(-$floor)->format() : '';

        throw ValidationException::withMessages([
            $field => "{$account->name} holds {$holds}{$limit}, not enough to pay {$pay}.",
        ]);
    }
}
