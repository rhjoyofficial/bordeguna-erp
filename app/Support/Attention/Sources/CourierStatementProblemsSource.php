<?php

namespace App\Support\Attention\Sources;

use App\Models\User;
use App\Modules\Sales\Models\CourierStatement;
use App\Modules\Sales\Support\CourierStatements\CourierStatementReview;
use App\Support\Attention\AttentionItem;

/**
 * Draft courier statements that cannot be posted yet: rows that match no
 * parcel, rows whose money differs from the ERP, rows the import could not
 * read. Read through CourierStatementReview, the statement screen's own check,
 * so the two can never disagree.
 */
class CourierStatementProblemsSource extends Source
{
    public function key(): string
    {
        return 'courier_statement_problems';
    }

    public function area(): string
    {
        return 'couriers';
    }

    protected function permissions(): array
    {
        return ['sales.reports.view'];
    }

    public function items(User $user, int $businessId): array
    {
        $drafts = CourierStatement::query()
            ->with('courier:id,name')
            ->where('business_id', $businessId)
            ->where('status', CourierStatement::DRAFT)
            ->orderBy('created_at')
            ->get();

        $items = [];

        foreach ($drafts as $statement) {
            $review = CourierStatementReview::of($statement);
            $unmatched = $review->rows
                ->filter(fn (array $row) => $row['state'] === CourierStatementReview::UNLISTED && $row['line']->resolution === null)
                ->count();
            $problems = count($review->problems);
            $rowErrors = count($statement->rowErrors());

            if ($problems === 0 && $rowErrors === 0) {
                continue;
            }

            $items[] = $this->item(
                (string) $statement->id,
                AttentionItem::HIGH,
                'statement_problems',
                'Statement {reference} from {courier}: {problems} rows to fix ({unmatched} unmatched), {errors} rows the import could not read.',
                [
                    'reference' => (string) $statement->statement_reference,
                    'courier' => (string) ($statement->courier?->name ?? ''),
                    'problems' => $problems,
                    'unmatched' => $unmatched,
                    'errors' => $rowErrors,
                ],
                "/sales/courier-statements/{$statement->id}",
                ['since' => $statement->created_at, 'ownerRole' => 'Finance', 'count' => $problems + $rowErrors],
            );
        }

        return $items;
    }
}
