<?php

namespace App\Support\Dashboards\Widgets;

use App\Events\Dashboards\DashboardEventType;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Support\ApprovalCards;
use App\Support\ApprovalInbox;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\Widget;
use App\Support\Dashboards\WidgetContext;
use App\Support\Dashboards\WidgetScope;
use Illuminate\Support\Collection;

/**
 * Approvals this person can action right now, from ApprovalInbox: the same
 * source as the inbox screen and the sidebar badge, so the three never
 * disagree. Personal by construction (stage-aware, per user), never cached.
 */
class ApprovalsWaitingWidget extends Widget
{
    private const SHOWN = 6;

    public function __construct(
        protected readonly ApprovalInbox $inbox,
        protected readonly ApprovalCards $cards,
    ) {}

    public function key(): string
    {
        return 'approvals_waiting';
    }

    public function title(): string
    {
        return 'Waiting for you';
    }

    public function description(): ?string
    {
        return 'Approvals you can decide now, across every module. Not narrowed by the date range: an approval belongs to a person, not a period.';
    }

    public function category(): string
    {
        return 'Work';
    }

    public function dashboards(): array
    {
        return [DashboardKey::Owner, DashboardKey::Manager, DashboardKey::Sales];
    }

    public function scope(): WidgetScope
    {
        return WidgetScope::Self;
    }

    public function size(): string
    {
        return 'md';
    }

    public function refreshSeconds(): int
    {
        return 20;
    }

    public function cacheSeconds(): int
    {
        return 0;
    }

    public function drillDown(WidgetContext $context): ?string
    {
        return '/approvals';
    }

    public function emptyText(): string
    {
        return 'Nothing is waiting on you.';
    }

    public function refreshOn(): array
    {
        return [DashboardEventType::APPROVAL_REQUESTED, DashboardEventType::APPROVAL_DECIDED, DashboardEventType::CLAIM_SUBMITTED, DashboardEventType::ORDER_CREATED];
    }

    public function notifications(): array
    {
        return [['watch' => 'pending', 'sound' => 'attention', 'message' => 'A new approval is waiting for you.']];
    }

    /** @return Collection<int, ApprovalRequest> */
    protected function pending(WidgetContext $context): Collection
    {
        return $this->inbox->pendingActionableFor($context->user);
    }

    public function data(WidgetContext $context): array
    {
        $pending = $this->pending($context);

        return [
            'kind' => 'list',
            'columns' => [
                ['key' => 'type', 'label' => 'What'],
                ['key' => 'summary', 'label' => 'Details'],
                ['key' => 'since', 'label' => 'Waiting', 'align' => 'right'],
            ],
            'rows' => $pending->take(self::SHOWN)->map(function (ApprovalRequest $request) {
                $card = $this->cards->present($request);

                return [
                    'id' => $request->id,
                    'url' => '/approvals',
                    'cells' => [
                        'type' => $card['type'],
                        'summary' => str_replace('—', '-', (string) $card['summary']),
                        'since' => $request->created_at?->diffForHumans(short: true),
                    ],
                ];
            })->values()->all(),
            'total_count' => $pending->count(),
            'basis' => 'operational',
            'watch' => ['pending' => $pending->count()],
        ];
    }
}
