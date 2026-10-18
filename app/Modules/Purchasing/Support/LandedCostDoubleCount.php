<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Enums\VoucherStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Accounting\Support\Money;
use App\Modules\Purchasing\Enums\LandedCostTreatment;
use App\Modules\Purchasing\Enums\LandedCostType;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\LandedCostLine;
use Illuminate\Support\Carbon;

/**
 * Has this cost already been booked as an expense? (18 Oct 2026, decision D-A:
 * "no double counting: a cost already posted as an expense and later
 * capitalised must be corrected through the controlled correction process".)
 *
 * ⚠ An expense voucher carries no link to a goods receipt, so the check is a
 * WARNING built from evidence, never a refusal and never an automatic
 * reversal: a live (not reversed) expense voucher line, or a posted payment /
 * journal voucher debit, that is
 *   - on a head that holds this KIND of cost (the type's own mapped head, or a
 *     head whose name says so: "freight", "labour", "travel", ...), OR whose
 *     voucher names the purchase order or the supplier's challan;
 *   - dated from a week before the goods arrived to 45 days after;
 *   - for a SIMILAR amount: within 10% of the landed-cost line.
 * The person reads the list and, if it is the same money, reverses that
 * voucher through Corrections before posting the landed cost.
 */
final class LandedCostDoubleCount
{
    public const SIMILAR_PERCENT = 10;

    public const DAYS_BEFORE = 7;

    public const DAYS_AFTER = 45;

    /**
     * Every expense booked around this receipt that could be one of its costs,
     * each tagged with the cost types it could be (all types when the voucher
     * names the order or the challan).
     *
     * @return list<array{source: string, id: int, number: string, date: string, amount: string, amount_paisa: int, head: ?string, url: string, types: list<string>, names_document: bool}>
     */
    public function candidatesFor(GoodsReceipt $receipt): array
    {
        $receipt->loadMissing('purchaseOrder:id,po_number');
        $arrived = Carbon::parse($receipt->received_at ?? $receipt->created_at)->startOfDay();
        $from = $arrived->copy()->subDays(self::DAYS_BEFORE);
        $to = $arrived->copy()->addDays(self::DAYS_AFTER);
        $headTypes = $this->headTypes();
        $mentions = array_values(array_filter([$receipt->purchaseOrder?->po_number, $receipt->supplier_challan_number]));
        $allTypes = array_map(fn (LandedCostType $type) => $type->value, LandedCostType::cases());
        $candidates = [];

        $vouchers = ExpenseVoucher::query()->live()
            ->whereDate('spent_on', '>=', $from)->whereDate('spent_on', '<=', $to)
            ->with('lines.account:id,code,name')
            ->get();

        foreach ($vouchers as $voucher) {
            foreach ($voucher->lines as $line) {
                $named = $this->names($mentions, [$voucher->reference, $voucher->note, $voucher->paid_to, $line->description]);
                $types = $named ? $allTypes : ($headTypes[$line->account_id] ?? []);

                if ($types === []) {
                    continue;
                }

                $amount = Money::ofTaka((string) $line->amount);
                $candidates[] = [
                    'source' => 'expense_voucher',
                    'id' => $voucher->id,
                    'number' => (string) $voucher->voucher_number,
                    'date' => $voucher->spent_on->toDateString(),
                    'amount' => $amount->toTaka(),
                    'amount_paisa' => $amount->minor,
                    'head' => $line->account?->label(),
                    'url' => route('purchasing.expenses.print', $voucher->id),
                    'types' => $types,
                    'names_document' => $named,
                ];
            }
        }

        $ledgerVouchers = Voucher::query()
            ->where('status', VoucherStatus::Posted->value)
            ->whereDate('voucher_date', '>=', $from)->whereDate('voucher_date', '<=', $to)
            ->with('lines.account:id,code,name')
            ->get();

        foreach ($ledgerVouchers as $voucher) {
            foreach ($voucher->lines as $line) {
                if ($line->side !== 'debit') {
                    continue;
                }

                $named = $this->names($mentions, [$voucher->narration, $voucher->counterparty, $line->narration]);
                $types = $named && $line->account?->type === AccountType::Expense ? $allTypes : ($headTypes[$line->account_id] ?? []);

                if ($types === []) {
                    continue;
                }

                $candidates[] = [
                    'source' => 'voucher',
                    'id' => $voucher->id,
                    'number' => (string) $voucher->voucher_number,
                    'date' => $voucher->voucher_date->toDateString(),
                    'amount' => Money::ofMinor((int) $line->amount_minor)->toTaka(),
                    'amount_paisa' => (int) $line->amount_minor,
                    'head' => $line->account?->label(),
                    'url' => route('accounting.vouchers.show', $voucher->id),
                    'types' => $types,
                    'names_document' => $named,
                ];
            }
        }

        return $candidates;
    }

    /**
     * The warnings for each line of an open landed cost, by line id.
     *
     * @return array<int, list<string>>
     */
    public function warningsFor(LandedCost $landedCost): array
    {
        $receipt = $landedCost->goodsReceipt;

        if ($receipt === null || $landedCost->lines->isEmpty()) {
            return [];
        }

        $candidates = $this->candidatesFor($receipt);
        $warnings = [];

        foreach ($landedCost->lines as $line) {
            foreach ($this->matching($line, $candidates) as $match) {
                $warnings[$line->id][] = self::sentence($line, $match);
            }
        }

        return $warnings;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    public function matching(LandedCostLine $line, array $candidates): array
    {
        return array_values(array_filter($candidates, fn (array $c) => in_array($line->cost_type->value, $c['types'], true)
            && self::similar((int) $line->amount_paisa, (int) $c['amount_paisa'])));
    }

    /** Within 10% of the landed-cost line's amount. */
    public static function similar(int $lineMinor, int $otherMinor): bool
    {
        return $lineMinor > 0 && abs($lineMinor - $otherMinor) * 100 <= self::SIMILAR_PERCENT * $lineMinor;
    }

    /** @param  array<string, mixed>  $match */
    public static function sentence(LandedCostLine $line, array $match): string
    {
        $again = $line->treatment === LandedCostTreatment::Capitalize
            ? 'capitalising it again counts it twice'
            : 'expensing it again counts it twice';

        return "{$line->cost_type->label()} line of {$line->amount()->format()}: already posted as an expense on {$match['number']} ({$match['date']}, "
            .($match['head'] ? "{$match['head']}, " : '')."Tk {$match['amount']}): {$again}; reverse that voucher first through Corrections.";
    }

    /**
     * Expense head id => the cost types it holds: each type's mapped head,
     * and the heads whose names carry the type's words.
     *
     * @return array<int, list<string>>
     */
    private function headTypes(): array
    {
        $map = [];
        $heads = Account::query()->where('type', AccountType::Expense)->get(['id', 'name']);

        foreach (LandedCostType::cases() as $type) {
            $ids = [];

            if (($key = $type->expenseMappingKey()) !== null && ($mapped = AccountMapping::forKeyOn($key, Carbon::today())?->account_id) !== null) {
                $ids[] = (int) $mapped;
            }

            foreach ($heads as $head) {
                $name = mb_strtolower((string) $head->name);

                foreach ($type->headKeywords() as $word) {
                    if (str_contains($name, $word)) {
                        $ids[] = (int) $head->id;
                    }
                }
            }

            foreach (array_unique($ids) as $id) {
                $map[$id][] = $type->value;
            }
        }

        return $map;
    }

    /**
     * @param  list<string>  $needles
     * @param  list<?string>  $texts
     */
    private function names(array $needles, array $texts): bool
    {
        $haystack = mb_strtolower(implode(' ', array_filter($texts)));

        foreach ($needles as $needle) {
            if ($haystack !== '' && str_contains($haystack, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
