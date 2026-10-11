<?php

use App\Events\Dashboards\ApprovalDecided;
use App\Events\Dashboards\ApprovalRequested;
use App\Events\Dashboards\CustomerPaymentReceived;
use App\Events\Dashboards\DashboardBroadcast;
use App\Events\Dashboards\DashboardEventType;
use App\Events\Dashboards\DashboardSignal;
use App\Events\Dashboards\ExpenseClaimSubmitted;
use App\Events\Dashboards\SalesOrderApproved;
use App\Events\Dashboards\SalesOrderCreated;
use App\Events\Dashboards\StockLow;
use App\Models\User;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\ExpenseClaim;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Event;

/**
 * Live dashboards over Reverb (Joy, 11 Oct 2026): who may subscribe to which
 * channel, which domain happenings broadcast, and that no payload ever carries
 * a figure.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Live Co', 'slug' => 'live-co']);
    $this->other = Business::create(['name' => 'Other Co', 'slug' => 'other-co']);
    app(BusinessContext::class)->set($this->business->id);
    setPermissionsTeamId($this->business->id);

    $this->member = User::factory()->create();
    $this->member->businesses()->attach($this->business->id);
    $this->outsider = User::factory()->create();
    $this->outsider->businesses()->attach($this->other->id);
});

/**
 * Point broadcasting at a real Pusher-protocol driver (Reverb) for this test, and
 * register routes/channels.php on it, so the auth endpoint runs the real
 * channel callbacks. The suite otherwise uses the null broadcaster.
 */
function useReverbForAuth(): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    app(BroadcastManager::class)->forgetDrivers();
    require base_path('routes/channels.php');
}

function channelAuth($test, User $user, string $channel)
{
    return $test->actingAs($user)->post('/broadcasting/auth', ['channel_name' => "private-{$channel}", 'socket_id' => '1234.5678']);
}

it('lets a member subscribe to their business channel and nobody else', function () {
    useReverbForAuth();

    channelAuth($this, $this->member, "business.{$this->business->id}")->assertOk()->assertJsonStructure(['auth']);
    channelAuth($this, $this->outsider, "business.{$this->business->id}")->assertForbidden();
    channelAuth($this, $this->member, "business.{$this->other->id}")->assertForbidden();
});

it('lets a person subscribe only to their own user channel', function () {
    useReverbForAuth();

    channelAuth($this, $this->member, "user.{$this->member->id}")->assertOk();
    channelAuth($this, $this->member, "user.{$this->outsider->id}")->assertForbidden();
});

it('refuses a signed-out subscriber', function () {
    useReverbForAuth();

    $this->post('/broadcasting/auth', ['channel_name' => "private-business.{$this->business->id}", 'socket_id' => '1234.5678'])
        ->assertRedirect();
});

it('sends event type, ids, widget keys and a sound class, and nothing else', function () {
    $event = SalesOrderCreated::for($this->business->id, 42, $this->member->id);
    $payload = $event->broadcastWith();

    expect(array_keys($payload))->toBe(DashboardBroadcast::PAYLOAD_KEYS)
        ->and($payload['type'])->toBe(DashboardEventType::ORDER_CREATED)
        ->and($payload['ids'])->toBe(['order' => 42])
        ->and($payload['widgets'])->toContain('orders_by_state', 'my_orders')
        ->and($payload['sound'])->toBe('success')
        ->and($payload['actor_id'])->toBe($this->member->id)
        ->and($event->broadcastAs())->toBe('dashboard.event')
        ->and(collect($event->broadcastOn())->map(fn (PrivateChannel $c) => $c->name)->all())->toBe(["private-business.{$this->business->id}"]);

    // ⚠ No amount, name or total may ever ride along.
    $flat = json_encode($payload);
    expect($flat)->not->toContain('total')->not->toContain('amount')->not->toContain('name');
});

it('sends a personal event only to that person', function () {
    $event = DashboardSignal::personal(DashboardEventType::PAYSLIP_READY, $this->business->id, $this->member->id, ['run' => 7]);

    expect(collect($event->broadcastOn())->map(fn (PrivateChannel $c) => $c->name)->all())->toBe(["private-user.{$this->member->id}"])
        ->and($event->broadcastWith()['personal'])->toBeTrue()
        ->and($event->broadcastWith()['widgets'])->toContain('my_payslip');
});

it('maps courier signals to the error sound for later slices', function () {
    $event = DashboardSignal::business(DashboardEventType::BOOKING_FAILED, $this->business->id, ['parcel' => 3]);

    expect($event->broadcastWith()['sound'])->toBe('error');
});

it('broadcasts when an approval is requested and decided, with the requester told personally', function () {
    Event::fake([ApprovalRequested::class, ApprovalDecided::class, ExpenseClaimSubmitted::class, SalesOrderApproved::class]);

    $employee = Employee::create([
        'employee_code' => 'BDG-777001', 'user_id' => $this->member->id, 'first_name' => 'A', 'last_name' => 'B',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);
    $claim = ExpenseClaim::withoutEvents(fn () => ExpenseClaim::query()->create([
        'business_id' => $this->business->id, 'employee_id' => $employee->id, 'claim_number' => 'EC-1',
        'spent_on' => '2026-10-01', 'amount' => 100, 'purpose' => 'Bus',
    ]));

    $request = ApprovalRequest::forceCreate([
        'business_id' => $this->business->id,
        'approvable_type' => $claim->getMorphClass(),
        'approvable_id' => $claim->id,
        'status' => ApprovalStatus::Pending,
        'requested_by' => $this->member->id,
    ]);

    Event::assertDispatched(ApprovalRequested::class, fn ($e) => $e->ids === ['approval' => $request->id]);
    Event::assertDispatched(ExpenseClaimSubmitted::class, fn ($e) => $e->ids === ['claim' => $claim->id]);

    $request->update(['status' => ApprovalStatus::Approved]);

    Event::assertDispatched(ApprovalDecided::class, fn ($e) => in_array("private-user.{$this->member->id}", collect($e->broadcastOn())->map->name->all(), true));
    Event::assertNotDispatched(SalesOrderApproved::class);
});

it('never lets a broadcast failure break the write it describes', function () {
    // A listener that throws stands in for a broken broadcaster.
    Event::listen(ApprovalRequested::class, fn () => throw new RuntimeException('socket down'));

    $request = ApprovalRequest::forceCreate([
        'business_id' => $this->business->id,
        'approvable_type' => 'App\\Modules\\Sales\\Models\\SalesOrder',
        'approvable_id' => 999,
        'status' => ApprovalStatus::Pending,
        'requested_by' => $this->member->id,
    ]);

    expect($request->exists)->toBeTrue();
});

it('declares the event classes the coordinator asked for', function () {
    foreach ([SalesOrderCreated::class, SalesOrderApproved::class, CustomerPaymentReceived::class, ApprovalRequested::class, ApprovalDecided::class, StockLow::class, ExpenseClaimSubmitted::class, DashboardSignal::class] as $class) {
        expect(is_subclass_of($class, DashboardBroadcast::class))->toBeTrue();
        expect(in_array(ShouldBroadcast::class, class_implements($class), true))->toBeTrue();
        expect(in_array(ShouldDispatchAfterCommit::class, class_implements($class), true))->toBeTrue();
    }
});
