<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\Money;

/**
 * What posting a landed cost WOULD do, line by line and batch by batch: the
 * allocation preview the screen shows before posting and the exact plan the
 * posting writes. One computation, two readers, so the preview can never say
 * one thing and the posting do another.
 *
 * `refusals` non-empty means the document cannot be posted as it stands, and
 * every sentence says what needs resolution (ruling Q4: refuse and explain,
 * never dump onto write-off).
 */
final class LandedCostPlan
{
    /**
     * @param  array<int, array{
     *   line_id: int,
     *   treatment: string,
     *   expense_account: ?Account,
     *   allocations: list<array{
     *     receipt_line_id: int, batch_id: int, batch_number: ?string, product: ?string,
     *     quantity_received: string, quantity_on_hand: string, quantity_consumed: string,
     *     share_paisa: int, on_hand_share_paisa: int, consumed_share_paisa: int,
     *     old_unit_cost: string, new_unit_cost: ?string,
     *   }>,
     * }>  $lines
     * @param  list<string>  $refusals
     */
    public function __construct(
        public readonly array $lines,
        public readonly Money $capitalised,
        public readonly Money $onHand,
        public readonly Money $consumed,
        public readonly Money $expensed,
        public readonly array $refusals,
    ) {}

    public function isPostable(): bool
    {
        return $this->refusals === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'lines' => array_map(fn (array $line) => [
                'line_id' => $line['line_id'],
                'treatment' => $line['treatment'],
                'expense_account' => $line['expense_account']?->label(),
                'allocations' => $line['allocations'],
            ], array_values($this->lines)),
            'capitalised' => $this->capitalised->toTaka(),
            'on_hand' => $this->onHand->toTaka(),
            'consumed' => $this->consumed->toTaka(),
            'expensed' => $this->expensed->toTaka(),
            'refusals' => $this->refusals,
        ];
    }
}
