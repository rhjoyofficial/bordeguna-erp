<?php

namespace App\Modules\Hrm\Dashboard\Widgets;

use App\Models\User;
use App\Modules\Hrm\Dashboard\Contracts\DashboardWidget;
use App\Modules\Hrm\Dashboard\DashboardFilters;
use App\Modules\Hrm\Dashboard\WidgetValue;
use App\Modules\Hrm\Reporting\ReportColumnType;
use App\Support\ApprovalInbox;
use Illuminate\Support\Facades\Auth;

/**
 * RPT-04 #3 — Pending approvals.
 *
 * **The whole widget is one call to `ApprovalInbox::countFor()`**, and that is
 * the point rather than a shortcut. The inbox already answers the only sensible
 * version of this question — *how many approvals can **this** user action* —
 * stage-aware, with the legacy null-workflow fallback for stock requests and
 * purchase orders. Counting pending rows here instead would have produced a
 * second, larger number: approvals the viewer cannot touch, on a tile that
 * exists to tell them what to do next.
 *
 * ⚠ **It is the same number the sidebar badge shows, by construction.** Both
 * read `countFor()`. A dashboard tile and a nav badge disagreeing about "pending
 * approvals" is exactly the drift the reporting framework was built to prevent,
 * arriving in a different corner of the application.
 *
 * ⚠ **Cached for zero seconds — computed live on every request.** Correction
 * C11e let each widget declare its own tolerance precisely so this one could
 * decline. This is the figure somebody is about to act on; a minute-old count
 * sends them to an inbox that has already changed, and the cost of being right
 * is one query somebody was going to make anyway by clicking through.
 *
 * ⚠ **Visibility is not a single permission**, which is why `isVisibleTo()`
 * exists on the contract. Approvals are cross-module and per-user, so the rule
 * mirrors the one the sidebar already uses: a legacy approver keeps the tile
 * even at zero, and anybody else earns it once they actually have something to
 * act on.
 *
 * **The filters do not apply**, and the description says so. An approval is
 * assigned to a person, not to a department or a date range, and quietly
 * narrowing it would hide work somebody is still expected to do.
 */
class PendingApprovalsWidget implements DashboardWidget
{
    public function __construct(
        private readonly ApprovalInbox $inbox,
    ) {}

    public function key(): string
    {
        return 'approvals_pending';
    }

    public function title(): string
    {
        return 'Waiting for you';
    }

    public function description(): string
    {
        return 'Approvals you can action right now, across every module. Not narrowed by the filters above — an approval belongs to a person, not to a department or a period.';
    }

    public function group(): string
    {
        // ⚠ Its own group, deliberately not 'Payroll'. ACL-02 withholds payroll
        // *figures* from a Department Manager; a count of things they are
        // authorised to approve is neither a salary figure nor something the
        // inbox would have shown them if they could not act on it. Filing it
        // under Payroll would have withheld a manager's own to-do list from them.
        return 'Approvals';
    }

    public function permission(): string
    {
        // Declared for the contract and for readers; the real rule is below,
        // because no single permission expresses "can review approvals".
        return 'hrm.attendance.manage';
    }

    public function isVisibleTo(User $user): bool
    {
        // The same rule `HandleInertiaRequests::canReviewApprovals()` applies to
        // the nav: legacy approvers keep it at zero, everyone else sees it once
        // they have something to act on. Reusing the rule rather than restating
        // it is what stops the tile and the nav item appearing on different days.
        return $user->can('inventory.stock.approve')
            || $user->can('purchasing.po.approve')
            || $this->inbox->countFor($user) > 0;
    }

    public function cacheSeconds(): int
    {
        return 0;
    }

    public function value(DashboardFilters $filters): WidgetValue
    {
        // ⚠ The acting user, not a filter. The inbox is inherently per-person,
        // and the registry has already decided this user may see the tile.
        $user = Auth::user();

        $count = $user === null ? 0 : $this->inbox->countFor($user);

        return new WidgetValue(
            value: $count,
            type: ReportColumnType::Integer,
            note: $count === 0
                ? 'Nothing is waiting on you.'
                : 'Stage-aware: only what you can action at its current stage.',
        );
    }
}
