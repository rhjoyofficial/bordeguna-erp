<?php

namespace App\Modules\Sales\Support\CourierStatements;

use App\Modules\Accounting\Support\Money;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\CourierParcelClaim;
use App\Modules\Sales\Models\CourierStatement;
use App\Modules\Sales\Models\CourierStatementLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What a courier statement would do to this business if it were posted now, and
 * everything that stops it.
 *
 * ⭐ EVERY LINE IS ONE OF THREE THINGS:
 * - OURS: a parcel this business handed to this courier. Settled here.
 * - ELSEWHERE: a parcel another business handed to the same courier (one Pathao
 *   account serves both brands, Joy 13 September). Left for that business.
 * - UNLISTED: a parcel no business in the ERP knows, booked by hand in the
 *   courier's panel. A person says whether it is this business's.
 *
 * ⭐ MATCHING ORDER (courier hub plan F.2, v2 11 Oct 2026):
 * 1. the courier's consignment id, ignoring case;
 * 2. the merchant order id (a leading # ignored) against the parcel's challan
 *    number, its order number or its shop reference; two parcels answering to
 *    one reference is no match;
 * 3. same amount within a week, ONLY as a suggestion a person checks. Never a
 *    match.
 *
 * ⚠⚠ AN UNLISTED PARCEL WITH MONEY COLLECTED CANNOT BE CLAIMED. The money is a
 * sale the ERP has no record of, and claiming it would invent revenue. Its fee on
 * a parcel that collected nothing is a real cost of this business, and is.
 *
 * ⚠ A row the file could not be read on blocks posting: the money on it is not
 * explained, and leaving it out would leave the statement short.
 *
 * ⚠ Matching is computed on every read, never stored on a draft, so a parcel
 * recorded in the ERP after the import is matched the next time anybody looks,
 * and a read never writes. Posting freezes the match.
 */
final class CourierStatementReview
{
    public const OURS = 'ours';

    public const ELSEWHERE = 'elsewhere';

    public const UNLISTED = 'unlisted';

    /**
     * @param  Collection<int, array{line: CourierStatementLine, state: string, consignment: ?Consignment, included: bool, problem: ?string, note: ?string, suggestion: ?string, fee_kind: string, match_method: ?string}>  $rows
     * @param  list<string>  $problems
     */
    private function __construct(
        public readonly CourierStatement $statement,
        public readonly string $provider,
        public readonly Collection $rows,
        public readonly array $problems,
        public readonly Money $fileTotal,
        public readonly Money $share,
        public readonly Money $collected,
        public readonly Money $deliveryFees,
        public readonly Money $returnFees,
        public readonly Money $additionalCharges,
        public readonly Money $codFees,
        public readonly Money $compensation,
        public readonly Money $adjustment,
    ) {}

    public static function of(CourierStatement $statement): self
    {
        $statement->loadMissing('lines', 'courier');

        $provider = $statement->provider_code ?: CourierStatementFormats::provider($statement->courier);
        $numbers = $statement->lines->map(fn (CourierStatementLine $line) => strtoupper($line->consignment_number))->all();

        $ours = self::consignments($numbers, fn ($query) => $query->where('money_account_id', $statement->money_account_id))
            ->keyBy(fn (Consignment $consignment) => strtoupper($consignment->consignment_number));

        $sameCourier = array_values(array_diff(CourierStatementFormats::moneyAccountIdsFor($provider), [(int) $statement->money_account_id]));
        $elsewhere = $sameCourier === [] ? collect() : self::consignments($numbers, fn ($query) => $query->withoutGlobalScopes()
            ->where('consignments.business_id', '!=', $statement->business_id)
            ->whereIn('money_account_id', $sameCourier))
            ->keyBy(fn (Consignment $consignment) => strtoupper($consignment->consignment_number));

        $byReference = self::byMerchantReference($statement, $ours->pluck('id')->all());

        $claims = CourierParcelClaim::query()
            ->whereIn('claim_key', array_map(fn ($number) => CourierParcelClaim::keyFor($provider, $number), $numbers))
            ->get()
            ->keyBy('claim_key');

        $matchedIds = [];

        $rows = $statement->lines->map(function (CourierStatementLine $line) use ($statement, $ours, $elsewhere, $byReference, $claims, $provider, &$matchedIds) {
            $number = strtoupper($line->consignment_number);
            $consignment = $ours->get($number);
            $method = $consignment === null ? null : CourierStatementLine::BY_CONSIGNMENT;
            $note = null;

            if ($consignment === null && ($reference = self::normaliseReference($line->merchant_reference)) !== null) {
                $candidates = $byReference[$reference] ?? [];

                if (count($candidates) === 1 && ! in_array(reset($candidates)->id, $matchedIds, true)) {
                    $consignment = reset($candidates);
                    $method = CourierStatementLine::BY_MERCHANT_REFERENCE;
                    $note = "Matched on order reference {$line->merchant_reference}: the ERP records this parcel as {$consignment->consignment_number}.";
                } elseif (count($candidates) > 1) {
                    $note = "Order reference {$line->merchant_reference} fits more than one parcel; not matched.";
                }
            }

            $claim = $claims->get(CourierParcelClaim::keyFor($provider, $number));

            // ⚠ A posted statement owns its own claims, so only someone else's
            // claim is a problem.
            $claimedElsewhere = $claim !== null && ! $statement->lines->contains('id', $claim->courier_statement_line_id);

            if ($consignment !== null) {
                $matchedIds[] = $consignment->id;
                $note ??= self::statusNote($line, $consignment);

                return [
                    'line' => $line,
                    'state' => self::OURS,
                    'consignment' => $consignment,
                    'included' => true,
                    'problem' => $claimedElsewhere ? "Consignment {$line->consignment_number} has already been paid out on another statement." : self::oursProblem($line, $consignment),
                    'note' => $note,
                    'suggestion' => null,
                    'fee_kind' => $consignment->status->isReturn() ? 'return' : 'delivery',
                    'match_method' => $method,
                ];
            }

            if ($elsewhere->has($number)) {
                return ['line' => $line, 'state' => self::ELSEWHERE, 'consignment' => null, 'included' => false, 'problem' => null, 'note' => null, 'suggestion' => null, 'fee_kind' => 'delivery', 'match_method' => null];
            }

            $included = $line->resolution === CourierStatementLine::MINE;

            $problem = match (true) {
                $line->resolution === null => "Say whether consignment {$line->consignment_number} is this business's parcel, booked outside the ERP, or not.",
                $included && ! $line->collected()->isZero() => "Consignment {$line->consignment_number} collected {$line->collected()->format()}, a sale the ERP has no record of. Record its order, challan and parcel first; it will then match.",
                $included && $claimedElsewhere => "Consignment {$line->consignment_number} has already been claimed on another statement.",
                default => null,
            };

            return ['line' => $line, 'state' => self::UNLISTED, 'consignment' => null, 'included' => $included, 'problem' => $problem, 'note' => $note, 'suggestion' => null, 'fee_kind' => 'delivery', 'match_method' => null];
        })->values();

        $rows = self::withSuggestions($statement, $rows, $matchedIds);

        $included = $rows->where('included', true);
        $sum = fn (callable $pick, ?string $feeKind = null) => Money::sum(
            $included->when($feeKind !== null, fn ($rows) => $rows->where('fee_kind', $feeKind))->map(fn (array $row) => $pick($row['line']))
        );

        $problems = [];

        if (($errors = count($statement->rowErrors())) > 0) {
            $problems[] = "{$errors} ".($errors === 1 ? 'row' : 'rows').' of the file could not be read (see Errors). Fix the file at the courier, discard this draft and import it again.';
        }

        $problems = [...$problems, ...$rows->pluck('problem')->filter()->values()->all()];

        // ⭐ Risk 4: the share must add up from its own columns, or the journal
        // below would only balance through a rounding line nobody asked for.
        $shareEquation = self::equationOf($included->pluck('line'));

        if (! $shareEquation['difference']->isZero()) {
            $problems[] = "This business's parcels do not add up: collections less fees plus compensation and adjustments come to {$shareEquation['expected']->format()}, but the payout column says {$shareEquation['payout']->format()} (difference {$shareEquation['difference']->format()}). Check the file with the courier.";
        }

        if ($included->isEmpty() && $problems === []) {
            $problems[] = 'No parcel on this statement belongs to this business.';
        }

        return new self(
            statement: $statement,
            provider: $provider,
            rows: $rows,
            problems: $problems,
            fileTotal: $statement->fileTotal(),
            share: $sum(fn (CourierStatementLine $line) => $line->payoutAmount()),
            collected: $sum(fn (CourierStatementLine $line) => $line->collected()),
            deliveryFees: $sum(fn (CourierStatementLine $line) => self::carriage($line), 'delivery'),
            returnFees: $sum(fn (CourierStatementLine $line) => self::carriage($line), 'return'),
            additionalCharges: $sum(fn (CourierStatementLine $line) => $line->additionalCharge()),
            codFees: $sum(fn (CourierStatementLine $line) => $line->codFee()),
            compensation: $sum(fn (CourierStatementLine $line) => $line->compensationAmount()),
            adjustment: $sum(fn (CourierStatementLine $line) => $line->adjustmentAmount()),
        );
    }

    /**
     * ⭐ Risk 4: collections - net fees - COD fees - additional charges
     * + compensation + adjustment = payout, for any set of lines.
     *
     * @param  iterable<CourierStatementLine>  $lines
     * @return array{collected: Money, net_fees: Money, cod_fees: Money, additional: Money, compensation: Money, adjustment: Money, expected: Money, payout: Money, difference: Money}
     */
    public static function equationOf(iterable $lines): array
    {
        $lines = collect($lines);
        $sum = fn (callable $pick) => Money::sum($lines->map($pick));

        $collected = $sum(fn (CourierStatementLine $line) => $line->collected());
        $netFees = $sum(fn (CourierStatementLine $line) => $line->netFee());
        $codFees = $sum(fn (CourierStatementLine $line) => $line->codFee());
        $additional = $sum(fn (CourierStatementLine $line) => $line->additionalCharge());
        $compensation = $sum(fn (CourierStatementLine $line) => $line->compensationAmount());
        $adjustment = $sum(fn (CourierStatementLine $line) => $line->adjustmentAmount());
        $payout = $sum(fn (CourierStatementLine $line) => $line->payoutAmount());

        $expected = Money::ofMinor($collected->minor - $netFees->minor - $codFees->minor - $additional->minor + $compensation->minor + $adjustment->minor);

        return [
            'collected' => $collected,
            'net_fees' => $netFees,
            'cod_fees' => $codFees,
            'additional' => $additional,
            'compensation' => $compensation,
            'adjustment' => $adjustment,
            'expected' => $expected,
            'payout' => $payout,
            'difference' => Money::ofMinor($payout->minor - $expected->minor),
        ];
    }

    /**
     * The equation for the whole file and for this business's share, as the
     * statement screen shows it.
     *
     * @return array<string, array<string, string|bool>>
     */
    public function equations(): array
    {
        $shape = fn (array $equation) => [
            ...array_map(fn (Money $money) => $money->toTaka(), $equation),
            'balanced' => $equation['difference']->isZero(),
        ];

        return [
            'file' => $shape(self::equationOf($this->statement->lines)),
            'share' => $shape(self::equationOf($this->rows->where('included', true)->pluck('line'))),
        ];
    }

    public function canPost(): bool
    {
        return $this->problems === [];
    }

    /** The courier's charge to carry a parcel, net of its own discount (ChatGPT ruling 4). */
    public static function carriage(CourierStatementLine $line): Money
    {
        return $line->netFee();
    }

    /** "#LLD-20261003-4NIJ" and "lld-20261003-4nij" are one reference. */
    public static function normaliseReference(?string $reference): ?string
    {
        $clean = strtoupper(ltrim(trim((string) $reference), '#'));

        return in_array($clean, ['', 'N/A'], true) ? null : $clean;
    }

    private static function oursProblem(CourierStatementLine $line, Consignment $consignment): ?string
    {
        if ($consignment->isBooked()) {
            return "Consignment {$line->consignment_number} is still recorded as with the courier. Record the courier's answer on its order first.";
        }

        $recorded = $consignment->collectedAmount() ?? Money::zero();

        if ($recorded->minor !== $line->collected()->minor) {
            return "Consignment {$line->consignment_number}: the courier says it collected {$line->collected()->format()}, the ERP recorded {$recorded->format()}.";
        }

        return null;
    }

    /** The courier's own word on the parcel, when it disagrees with the ERP's. */
    private static function statusNote(CourierStatementLine $line, Consignment $consignment): ?string
    {
        $status = strtolower((string) $line->delivery_status);

        if ($status === '') {
            return null;
        }

        $courierSaysReturned = str_contains($status, 'return');
        $erpSaysReturned = $consignment->status->isReturn();

        return $courierSaysReturned !== $erpSaysReturned
            ? "The courier calls it {$line->delivery_status}; the ERP recorded {$consignment->status->label()}."
            : null;
    }

    /**
     * Parcels of this courier in this business, keyed by every reference they
     * answer to: challan number, order number, shop reference.
     *
     * @param  list<int>  $exclude  parcels already matched on their number
     * @return array<string, array<int, Consignment>>
     */
    private static function byMerchantReference(CourierStatement $statement, array $exclude): array
    {
        $references = $statement->lines
            ->map(fn (CourierStatementLine $line) => self::normaliseReference($line->merchant_reference))
            ->filter()->unique()->values()->all();

        if ($references === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($references), '?'));
        $rows = DB::table('consignments')
            ->join('deliveries', 'deliveries.id', '=', 'consignments.delivery_id')
            ->leftJoin('delivery_lines', 'delivery_lines.delivery_id', '=', 'deliveries.id')
            ->leftJoin('sales_order_lines', 'sales_order_lines.id', '=', 'delivery_lines.sales_order_line_id')
            ->leftJoin('sales_orders', 'sales_orders.id', '=', 'sales_order_lines.sales_order_id')
            ->where('consignments.business_id', $statement->business_id)
            ->where('consignments.money_account_id', $statement->money_account_id)
            ->when($exclude !== [], fn ($query) => $query->whereNotIn('consignments.id', $exclude))
            ->where(fn ($query) => $query
                ->whereRaw("UPPER(REPLACE(deliveries.delivery_number, '#', '')) IN ({$placeholders})", $references)
                ->orWhereRaw("UPPER(REPLACE(sales_orders.order_number, '#', '')) IN ({$placeholders})", $references)
                ->orWhereRaw("UPPER(REPLACE(sales_orders.shop_reference, '#', '')) IN ({$placeholders})", $references))
            ->distinct()
            ->get(['consignments.id', 'deliveries.delivery_number', 'sales_orders.order_number', 'sales_orders.shop_reference']);

        if ($rows->isEmpty()) {
            return [];
        }

        $consignments = Consignment::query()->whereIn('id', $rows->pluck('id')->unique())->get()->keyBy('id');
        $map = [];

        foreach ($rows as $row) {
            foreach ([$row->delivery_number, $row->order_number, $row->shop_reference] as $value) {
                $key = self::normaliseReference($value);

                if ($key !== null && in_array($key, $references, true) && $consignments->has($row->id)) {
                    $map[$key][$row->id] = $consignments->get($row->id);
                }
            }
        }

        return $map;
    }

    /**
     * For a line nothing matched: this courier's unsettled parcels that
     * collected the same amount within a week. Text for a person, never a match.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<int>  $matchedIds
     * @return Collection<int, array<string, mixed>>
     */
    private static function withSuggestions(CourierStatement $statement, Collection $rows, array $matchedIds): Collection
    {
        if (! $statement->isDraft() || $rows->where('state', self::UNLISTED)->isEmpty()) {
            return $rows;
        }

        $open = Consignment::query()
            ->where('money_account_id', $statement->money_account_id)
            ->where('status', '!=', ConsignmentStatus::Booked->value)
            ->whereDoesntHave('statementLine')
            ->when($matchedIds !== [], fn ($query) => $query->whereNotIn('id', $matchedIds))
            ->get();

        if ($open->isEmpty()) {
            return $rows;
        }

        return $rows->map(function (array $row) use ($open) {
            if ($row['state'] !== self::UNLISTED) {
                return $row;
            }

            $line = $row['line'];
            $on = self::dateOf($line->delivered_on) ?? self::dateOf($line->booked_at);

            $fits = $open->filter(function (Consignment $consignment) use ($line, $on) {
                if (($consignment->collectedAmount() ?? Money::zero())->minor !== $line->collected()->minor) {
                    return false;
                }

                return $on === null || $consignment->outcome_on === null || abs($consignment->outcome_on->diffInDays($on)) <= 7;
            })->take(3);

            if ($fits->isNotEmpty()) {
                $row['suggestion'] = 'Same amount, near the same day: '.$fits->pluck('consignment_number')->implode(', ')
                    .'. Check whether one of them is this parcel.';
            }

            return $row;
        });
    }

    private static function dateOf(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $numbers
     * @return Collection<int, Consignment>
     */
    private static function consignments(array $numbers, callable $scope): Collection
    {
        if ($numbers === []) {
            return collect();
        }

        $query = Consignment::query();
        $scope($query);

        return $query
            ->whereRaw('UPPER(consignment_number) IN ('.implode(',', array_fill(0, count($numbers), '?')).')', $numbers)
            ->get();
    }
}
