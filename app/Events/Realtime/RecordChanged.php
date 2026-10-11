<?php

namespace App\Events\Realtime;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A record of some kind changed (Joy, 11 Oct 2026: "Everything shows real
 * time, no reload, good decision for everywhere"). Every open page that shows
 * that kind re-reads itself through its own controller (useLiveReload).
 *
 * ⚠⚠ THE PAYLOAD CARRIES NO FIELD VALUES. Kind, id, created/updated/deleted,
 * the business, who did it and when: nothing else, not even the new status.
 * The page re-reads through its own authorised controller, so a subscriber
 * never learns more than their permissions already show. A test asserts the
 * keys.
 *
 * Queued and released only after the transaction commits, so a rolled-back
 * write never refreshes anybody's screen.
 */
class RecordChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    /** The keys a payload may contain; nothing else ever leaves. */
    public const PAYLOAD_KEYS = ['kind', 'id', 'action', 'business_id', 'actor_id', 'at'];

    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const DELETED = 'deleted';

    /**
     * @param  int|null  $userId  set = only that person's own channel (a notification), never the business
     */
    public function __construct(
        public readonly string $kind,
        public readonly int|string $id,
        public readonly string $action,
        public readonly ?int $businessId,
        public readonly ?int $actorId = null,
        public readonly ?int $userId = null,
    ) {}

    public static function forBusiness(string $kind, int|string $id, string $action, int $businessId, ?int $actorId = null): self
    {
        return new self($kind, $id, $action, $businessId, $actorId);
    }

    public static function forUser(string $kind, int|string $id, string $action, int $userId, ?int $businessId = null, ?int $actorId = null): self
    {
        return new self($kind, $id, $action, $businessId, $actorId, $userId);
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        if ($this->userId !== null) {
            return [new PrivateChannel("user.{$this->userId}")];
        }

        return $this->businessId === null ? [] : [new PrivateChannel("business.{$this->businessId}")];
    }

    public function broadcastAs(): string
    {
        return 'record.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'kind' => $this->kind,
            'id' => $this->id,
            'action' => $this->action,
            'business_id' => $this->businessId,
            'actor_id' => $this->actorId,
            'at' => now()->toIso8601String(),
        ];
    }

    /** Nothing to send to when neither a business nor a person is known. */
    public function broadcastWhen(): bool
    {
        return $this->broadcastOn() !== [];
    }
}
