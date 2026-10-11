<?php

namespace App\Events\Dashboards;

use App\Support\Dashboards\Widget;
use App\Support\Dashboards\WidgetRegistry;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something happened that a live dashboard should show at once (Joy, 11 Oct
 * 2026: WebSockets now, polling only as the fallback).
 *
 * ⚠⚠ THE PAYLOAD CARRIES NO FIGURES. Event type, record ids, the widget keys to
 * refresh, the sound class, who did it: nothing a member of the business could
 * not already be told. Every widget re-reads itself through its own authorised
 * endpoint, so a subscriber never learns a number their permissions do not
 * allow. A test asserts the payload keys.
 *
 * Queued (ShouldBroadcast) and released only after the database transaction
 * commits, so a rolled-back write never rings a bell.
 */
abstract class DashboardBroadcast implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    /** The keys a payload may contain; nothing else ever leaves. */
    public const PAYLOAD_KEYS = ['type', 'ids', 'widgets', 'sound', 'actor_id', 'personal', 'at'];

    /**
     * @param  array<string, int|string>  $ids  record ids only, never amounts or names
     * @param  list<int>  $userIds  people who also get it on their own channel
     */
    public function __construct(
        public readonly string $type,
        public readonly int $businessId,
        public readonly array $ids = [],
        public readonly ?int $actorId = null,
        public readonly array $userIds = [],
        public readonly bool $toBusiness = true,
        public readonly ?string $sound = null,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = $this->toBusiness ? [new PrivateChannel("business.{$this->businessId}")] : [];

        foreach (array_unique($this->userIds) as $userId) {
            $channels[] = new PrivateChannel("user.{$userId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'dashboard.event';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'type' => $this->type,
            'ids' => $this->ids,
            'widgets' => self::widgetsFor($this->type),
            'sound' => $this->sound ?? DashboardEventType::soundFor($this->type),
            'actor_id' => $this->actorId,
            'personal' => ! $this->toBusiness,
            'at' => now()->toIso8601String(),
        ];
    }

    /** @return list<string> the widgets that declared they refresh on this event */
    public static function widgetsFor(string $type): array
    {
        return app(WidgetRegistry::class)->all()
            ->filter(fn (Widget $widget) => in_array($type, $widget->refreshOn(), true))
            ->keys()
            ->values()
            ->all();
    }
}
