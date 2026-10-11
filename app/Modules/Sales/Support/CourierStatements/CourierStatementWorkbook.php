<?php

namespace App\Modules\Sales\Support\CourierStatements;

use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Support\Money;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\CourierStatement;
use App\Modules\Sales\Models\CourierStatementLine;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The exception workbook for one courier statement (courier hub plan F.5).
 *
 * Sheets: Summary, Matched, Differences, Unmatched in file, Missing from file,
 * Errors. "Missing from file" is this courier's parcels the courier answered
 * more than `finance_policies.courier_settlement_alert_days` ago that no posted
 * statement has paid out, and that are not on this one.
 *
 * ⚠⚠ No recipient name or phone anywhere: the ERP never read them.
 * ⚠ Text is always written as a StringCell, never auto-detected, so a value
 * beginning with "=" can never become a formula (the XlsxWriter rule).
 */
final class CourierStatementWorkbook
{
    /** Writes the workbook to a temporary file and returns its path. */
    public static function write(CourierStatement $statement): string
    {
        $review = CourierStatementReview::of($statement);
        $path = tempnam(sys_get_temp_dir(), 'courier_').'.xlsx';
        $bold = (new Style)->withFontBold(true);

        $writer = new Writer;
        $writer->openToFile($path);

        $sheets = [
            'Summary' => self::summary($statement, $review),
            'Matched' => self::matched($review),
            'Differences' => self::differences($review),
            'Unmatched in file' => self::unmatched($review),
            'Missing from file' => self::missing($statement, $review),
            'Errors' => self::errors($statement),
        ];

        $first = true;

        foreach ($sheets as $name => [$header, $rows]) {
            $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName($name);
            $first = false;

            $writer->addRow(new Row(array_map(fn (string $heading) => new StringCell($heading, $bold), $header)));

            foreach ($rows as $row) {
                $writer->addRow(new Row(array_map(fn ($value) => self::cell($value), $row)));
            }
        }

        $writer->close();

        return $path;
    }

    public static function filename(CourierStatement $statement): string
    {
        return 'courier-statement-'.preg_replace('/[^A-Za-z0-9_-]/', '-', $statement->statement_reference).'-exceptions.xlsx';
    }

    private static function cell(mixed $value): Cell
    {
        if ($value instanceof Money) {
            return new NumericCell((float) $value->toTaka(), null);
        }

        if (is_int($value) || is_float($value)) {
            return new NumericCell($value, null);
        }

        return new StringCell((string) $value, null);
    }

    /** @return array{0: list<string>, 1: list<list<mixed>>} */
    private static function summary(CourierStatement $statement, CourierStatementReview $review): array
    {
        $count = fn (string $state) => $review->rows->where('state', $state)->count();

        return [['Item', 'Value'], [
            ['Courier', (string) $statement->courier?->name],
            ['Courier code', $review->provider],
            ['Statement', $statement->statement_reference],
            ['Statement date', $statement->statement_date->toDateString()],
            ['Status', $statement->status],
            ['File', (string) $statement->source_filename],
            ['Parcels in file', $review->rows->count()],
            ['This business (matched)', $count(CourierStatementReview::OURS)],
            ['Other business on the same courier', $count(CourierStatementReview::ELSEWHERE)],
            ['Not known to the ERP', $count(CourierStatementReview::UNLISTED)],
            ['Rows that could not be read', count($statement->rowErrors())],
            ['File total payout', $review->fileTotal],
            ['This business share', $review->share],
            ['Collected', $review->collected],
            ['Delivery fees (net)', $review->deliveryFees],
            ['Return fees (net)', $review->returnFees],
            ['Additional charges', $review->additionalCharges],
            ['COD fees', $review->codFees],
            ['Compensation', $review->compensation],
            ['Adjustments', $review->adjustment],
            ['Problems blocking posting', count($review->problems)],
        ]];
    }

    /** @return list<string> */
    private static function lineHeader(): array
    {
        return ['Row', 'Consignment', 'Merchant order id', 'Courier status', 'Collectable', 'Collected', 'Delivery fee', 'Discount', 'Net fee', 'COD fee', 'Additional', 'Final fee', 'Compensation', 'Adjustment', 'Payout', 'Hub weight (g)'];
    }

    /** @return list<mixed> */
    private static function lineCells(CourierStatementLine $line): array
    {
        return [
            $line->row_number, $line->consignment_number, (string) $line->merchant_reference, (string) $line->delivery_status,
            Money::ofTaka((string) $line->collectable_amount), $line->collected(), Money::ofTaka((string) $line->delivery_fee),
            Money::ofTaka((string) $line->discount), $line->netFee(), $line->codFee(), $line->additionalCharge(),
            Money::ofTaka((string) $line->final_fee), $line->compensationAmount(), $line->adjustmentAmount(), $line->payoutAmount(),
            $line->hub_weight_grams ?? '',
        ];
    }

    /** @return array{0: list<string>, 1: list<list<mixed>>} */
    private static function matched(CourierStatementReview $review): array
    {
        return [
            [...self::lineHeader(), 'ERP parcel', 'Matched on', 'ERP status', 'ERP collected'],
            $review->rows->where('state', CourierStatementReview::OURS)->map(fn (array $row) => [
                ...self::lineCells($row['line']),
                $row['consignment']->consignment_number,
                $row['match_method'] === 'merchant_reference' ? 'Order reference' : 'Consignment id',
                $row['consignment']->status->label(),
                $row['consignment']->collectedAmount() ?? Money::zero(),
            ])->values()->all(),
        ];
    }

    /** @return array{0: list<string>, 1: list<list<mixed>>} */
    private static function differences(CourierStatementReview $review): array
    {
        return [
            ['Row', 'Consignment', 'Merchant order id', 'ERP parcel', 'Courier collected', 'ERP collected', 'Difference', 'Reason', 'Blocks posting'],
            $review->rows
                ->filter(fn (array $row) => $row['state'] === CourierStatementReview::OURS && ($row['problem'] !== null || $row['note'] !== null))
                ->map(function (array $row) {
                    $erp = $row['consignment']->collectedAmount() ?? Money::zero();

                    return [
                        $row['line']->row_number, $row['line']->consignment_number, (string) $row['line']->merchant_reference,
                        $row['consignment']->consignment_number, $row['line']->collected(), $erp,
                        Money::ofMinor($row['line']->collected()->minor - $erp->minor),
                        (string) ($row['problem'] ?? $row['note']),
                        $row['problem'] !== null ? 'Yes' : 'No',
                    ];
                })->values()->all(),
        ];
    }

    /** @return array{0: list<string>, 1: list<list<mixed>>} */
    private static function unmatched(CourierStatementReview $review): array
    {
        return [
            [...self::lineHeader(), 'Whose', 'Decision', 'Suggestion', 'Problem'],
            $review->rows->whereIn('state', [CourierStatementReview::UNLISTED, CourierStatementReview::ELSEWHERE])->map(fn (array $row) => [
                ...self::lineCells($row['line']),
                $row['state'] === CourierStatementReview::ELSEWHERE ? 'Another business on this courier' : 'Not known to the ERP',
                match ($row['line']->resolution) {
                    CourierStatementLine::MINE => 'Ours, booked outside the ERP',
                    CourierStatementLine::NOT_MINE => 'Not ours',
                    default => $row['state'] === CourierStatementReview::ELSEWHERE ? '' : 'Not decided',
                },
                (string) ($row['suggestion'] ?? $row['note'] ?? ''),
                (string) $row['problem'],
            ])->values()->all(),
        ];
    }

    /** @return array{0: list<string>, 1: list<list<mixed>>} */
    private static function missing(CourierStatement $statement, CourierStatementReview $review): array
    {
        $days = (int) (FinancePolicy::for($statement->business_id)->courier_settlement_alert_days ?: 7);
        $onThis = $review->rows->pluck('consignment')->filter()->pluck('id')->all();

        /** @var Collection<int, Consignment> $parcels */
        $parcels = Consignment::query()
            ->with('delivery:id,delivery_number')
            ->where('money_account_id', $statement->money_account_id)
            ->where('status', '!=', ConsignmentStatus::Booked->value)
            ->whereDoesntHave('statementLine')
            ->whereNotNull('outcome_on')
            ->whereDate('outcome_on', '<=', today()->subDays($days))
            ->when($onThis !== [], fn ($query) => $query->whereNotIn('id', $onThis))
            ->orderBy('outcome_on')
            ->get();

        return [
            ['Consignment', 'Challan', 'Status', 'Answered on', 'Days waiting', 'Collected', "On no statement after {$days} days"],
            $parcels->map(fn (Consignment $consignment) => [
                $consignment->consignment_number,
                (string) $consignment->delivery?->delivery_number,
                $consignment->status->label(),
                $consignment->outcome_on->toDateString(),
                (int) $consignment->outcome_on->diffInDays(today()),
                $consignment->collectedAmount() ?? Money::zero(),
                'Yes',
            ])->values()->all(),
        ];
    }

    /** @return array{0: list<string>, 1: list<list<mixed>>} */
    private static function errors(CourierStatement $statement): array
    {
        return [
            ['Row', 'Consignment', 'Problem'],
            array_map(fn (array $error) => [$error['row'], (string) ($error['consignment'] ?? ''), $error['message']], $statement->rowErrors()),
        ];
    }
}
