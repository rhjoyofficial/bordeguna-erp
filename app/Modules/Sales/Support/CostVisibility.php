<?php

namespace App\Modules\Sales\Support;

use App\Models\User;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;

/**
 * Who may read what goods cost on a sales document (SAL-004, audit 14 Oct 2026).
 *
 * ⚠⚠ The 11 Oct reports pack put the margin and delivery economics reports
 * behind `sales.reports.costs` (Owner, Manager, Finance), because Staff hold
 * `sales.reports.view` and must not read costs. The invoice and return pages
 * still carried the same figures to Staff: "Cost of these goods", each line's
 * cost, and the cost lines of the journal. This is the one rule both pages use.
 *
 * The journal stays on the page for everybody (the "show me what this did to
 * the books" decision); only its lines on the cost heads are taken out for a
 * viewer without the permission.
 */
final class CostVisibility
{
    public const PERMISSION = 'sales.reports.costs';

    /** The heads a sales or return journal moves cost on. */
    private const COST_HEADS = [
        'sales.cost_of_goods_sold',
        'sales.goods_delivered_not_invoiced',
        'inventory.stock_on_hand',
        'inventory.adjustment.damaged',
        // ⭐ Initial plan B6: a damaged return's write-off has its own head.
        'inventory.adjustment.damaged_return',
    ];

    public static function allows(User $user): bool
    {
        return $user->can(self::PERMISSION);
    }

    /**
     * The journal as this viewer may see it: whole for a cost reader, without
     * its cost-head lines for everybody else.
     */
    public static function journal(?Journal $journal, User $user): ?Journal
    {
        if ($journal === null || self::allows($user)) {
            return $journal;
        }

        // Every account any of the heads was ever mapped to, so a remap later
        // in the year cannot uncover an older document's cost line.
        $costAccounts = AccountMapping::query()->whereIn('key', self::COST_HEADS)->pluck('account_id')->map(fn ($id) => (int) $id)->all();

        $journal->setRelation('lines', $journal->lines->reject(fn ($line) => in_array((int) $line->account_id, $costAccounts, true))->values());

        return $journal;
    }
}
