<?php

namespace App\Modules\Sales\Support\CourierStatements;

use App\Modules\Accounting\Support\Money;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\CourierParcelClaim;
use App\Modules\Sales\Models\CourierStatement;
use App\Modules\Sales\Models\CourierStatementLine;
use Illuminate\Support\Collection;

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
 * ⚠⚠ AN UNLISTED PARCEL WITH MONEY COLLECTED CANNOT BE CLAIMED. The money is a
 * sale the ERP has no record of, and claiming it would invent revenue. Its fee on
 * a parcel that collected nothing is a real cost of this business, and is.
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
     * @param  Collection<int, array{line: CourierStatementLine, state: string, consignment: ?Consignment, included: bool, problem: ?string, fee_kind: string}>  $rows
     * @param  list<string>  $problems
     */
    private function __construct(
        public readonly CourierStatement $statement,
        public readonly Collection $rows,
        public readonly array $problems,
        public readonly Money $fileTotal,
        public readonly Money $share,
        public readonly Money $collected,
        public readonly Money $deliveryFees,
        public readonly Money $returnFees,
        public readonly Money $additionalCharges,
        public readonly Money $codFees,
    ) {}

    public static function of(CourierStatement $statement): self
    {
        $statement->loadMissing('lines', 'courier');

        $provider = CourierStatementFormats::provider($statement->courier);
        $numbers = $statement->lines->map(fn (CourierStatementLine $line) => strtoupper($line->consignment_number))->all();

        $ours = self::consignments($numbers, fn ($query) => $query->where('money_account_id', $statement->money_account_id))
            ->keyBy(fn (Consignment $consignment) => strtoupper($consignment->consignment_number));

        $elsewhere = self::consignments($numbers, fn ($query) => $query->withoutGlobalScopes()
            ->where('consignments.business_id', '!=', $statement->business_id)
            ->whereIn('money_account_id', fn ($sub) => $sub->select('id')->from('money_accounts')
                ->whereRaw('LOWER(TRIM(provider)) = ?', [$provider])))
            ->keyBy(fn (Consignment $consignment) => strtoupper($consignment->consignment_number));

        $claims = CourierParcelClaim::query()
            ->whereIn('claim_key', array_map(fn ($number) => CourierParcelClaim::keyFor($provider, $number), $numbers))
            ->get()
            ->keyBy('claim_key');

        $rows = $statement->lines->map(function (CourierStatementLine $line) use ($statement, $ours, $elsewhere, $claims, $provider) {
            $number = strtoupper($line->consignment_number);
            $consignment = $ours->get($number);
            $claim = $claims->get(CourierParcelClaim::keyFor($provider, $number));

            // ⚠ A posted statement owns its own claims, so only someone else's
            // claim is a problem.
            $claimedElsewhere = $claim !== null && ! $statement->lines->contains('id', $claim->courier_statement_line_id);

            if ($consignment !== null) {
                return [
                    'line' => $line,
                    'state' => self::OURS,
                    'consignment' => $consignment,
                    'included' => true,
                    'problem' => $claimedElsewhere ? "Consignment {$line->consignment_number} has already been paid out on another statement." : self::oursProblem($line, $consignment),
                    'fee_kind' => $consignment->status === ConsignmentStatus::Returned ? 'return' : 'delivery',
                ];
            }

            if ($elsewhere->has($number)) {
                return ['line' => $line, 'state' => self::ELSEWHERE, 'consignment' => null, 'included' => false, 'problem' => null, 'fee_kind' => 'delivery'];
            }

            $included = $line->resolution === CourierStatementLine::MINE;

            $problem = match (true) {
                $line->resolution === null => "Say whether consignment {$line->consignment_number} is this business's parcel, booked outside the ERP, or not.",
                $included && ! $line->collected()->isZero() => "Consignment {$line->consignment_number} collected {$line->collected()->format()}, a sale the ERP has no record of. Record its order, challan and parcel first; it will then match.",
                $included && $claimedElsewhere => "Consignment {$line->consignment_number} has already been claimed on another statement.",
                default => null,
            };

            return ['line' => $line, 'state' => self::UNLISTED, 'consignment' => null, 'included' => $included, 'problem' => $problem, 'fee_kind' => 'delivery'];
        })->values();

        $included = $rows->where('included', true);
        $sum = fn (callable $pick, ?string $feeKind = null) => Money::sum(
            $included->when($feeKind !== null, fn ($rows) => $rows->where('fee_kind', $feeKind))->map(fn (array $row) => $pick($row['line']))
        );

        $problems = $rows->pluck('problem')->filter()->values()->all();

        if ($included->isEmpty() && $problems === []) {
            $problems[] = 'No parcel on this statement belongs to this business.';
        }

        return new self(
            statement: $statement,
            rows: $rows,
            problems: $problems,
            fileTotal: $statement->fileTotal(),
            share: $sum(fn (CourierStatementLine $line) => $line->payoutAmount()),
            collected: $sum(fn (CourierStatementLine $line) => $line->collected()),
            deliveryFees: $sum(fn (CourierStatementLine $line) => self::carriage($line), 'delivery'),
            returnFees: $sum(fn (CourierStatementLine $line) => self::carriage($line), 'return'),
            additionalCharges: $sum(fn (CourierStatementLine $line) => Money::ofTaka((string) $line->additional_charge)),
            codFees: $sum(fn (CourierStatementLine $line) => $line->codFee()),
        );
    }

    public function canPost(): bool
    {
        return $this->problems === [];
    }

    /** The courier's charge to carry a parcel, net of its own discount. */
    public static function carriage(CourierStatementLine $line): Money
    {
        return Money::ofMinor(Money::ofTaka((string) $line->delivery_fee)->minor - Money::ofTaka((string) $line->discount)->minor);
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
