<?php

namespace App\Support\Attention\Sources;

use App\Models\User;
use App\Support\ApprovalInbox;
use App\Support\Attention\AttentionItem;

/**
 * Approvals this person can decide now, from ApprovalInbox: the same source as
 * the inbox, its sidebar badge and the dashboard widget. Personal by
 * construction, so it needs no permission of its own.
 */
class ApprovalsWaitingSource extends Source
{
    public function __construct(private readonly ApprovalInbox $inbox) {}

    public function key(): string
    {
        return 'approvals_waiting';
    }

    public function area(): string
    {
        return 'work';
    }

    protected function permissions(): array
    {
        return [];
    }

    public function items(User $user, int $businessId): array
    {
        $pending = $this->inbox->pendingActionableFor($user);

        if ($pending->isEmpty()) {
            return [];
        }

        return [$this->item(
            'mine',
            AttentionItem::HIGH,
            'approvals_waiting',
            '{count} approvals are waiting for your decision.',
            ['count' => $pending->count()],
            '/approvals',
            ['since' => $pending->min('created_at'), 'ownerUserId' => (int) $user->id, 'count' => $pending->count()],
        )];
    }
}
