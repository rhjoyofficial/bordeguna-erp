<?php

use App\Events\Realtime\RecordChanged;
use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Couriers\Events\CourierParcelChanged;
use App\Support\Realtime\RecordChangeBuffer;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * Realtime everywhere (Joy, 11 Oct 2026): every key record's change goes out
 * as kind + id on the right channel, never a field value, once per request,
 * and never at the cost of the write.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Live Co', 'slug' => 'live-co']);
    app(BusinessContext::class)->set($this->business->id);
    setPermissionsTeamId($this->business->id);

    $this->member = User::factory()->create();
    $this->member->businesses()->attach($this->business->id);
});

function rcApproval(Business $business, User $by, int $approvableId = 999): ApprovalRequest
{
    return ApprovalRequest::forceCreate([
        'business_id' => $business->id,
        'approvable_type' => 'App\\Modules\\Sales\\Models\\SalesOrder',
        'approvable_id' => $approvableId,
        'status' => ApprovalStatus::Pending,
        'requested_by' => $by->id,
    ]);
}

/** @return list<RecordChanged> */
function rcSent(string $kind): array
{
    return Event::dispatched(RecordChanged::class, fn (RecordChanged $e) => $e->kind === $kind)->map(fn ($args) => $args[0])->values()->all();
}

it('sends kind, id, action, business, actor and time, and no field value, to the business channel', function () {
    Event::fake([RecordChanged::class]);
    $this->actingAs($this->member);

    $request = rcApproval($this->business, $this->member);

    [$event] = rcSent('approval_request');
    $payload = $event->broadcastWith();

    expect(array_keys($payload))->toBe(RecordChanged::PAYLOAD_KEYS)
        ->and($payload)->toMatchArray([
            'kind' => 'approval_request', 'id' => $request->id, 'action' => 'created',
            'business_id' => $this->business->id, 'actor_id' => $this->member->id,
        ])
        ->and($event->broadcastAs())->toBe('record.changed')
        ->and(collect($event->broadcastOn())->map(fn (PrivateChannel $c) => $c->name)->all())->toBe(["private-business.{$this->business->id}"]);

    // ⚠ Not the status, not the type, not the requester: the page re-reads.
    $flat = json_encode($payload);
    expect($flat)->not->toContain('pending')->not->toContain('SalesOrder')->not->toContain('requested_by');
});

it('is queued and released only after the transaction commits', function () {
    $interfaces = class_implements(RecordChanged::class);

    expect($interfaces)->toHaveKey(ShouldBroadcast::class)->toHaveKey(ShouldDispatchAfterCommit::class);
});

it('sends a notification only to its recipient, never to the business', function () {
    Event::fake([RecordChanged::class]);
    $other = User::factory()->create();
    $other->businesses()->attach($this->business->id);

    $this->member->notifications()->create([
        'id' => (string) Str::uuid(), 'type' => 'test', 'data' => ['summary' => 'Something private', 'kind' => 'approval'],
    ]);

    [$event] = rcSent('notification');

    expect(collect($event->broadcastOn())->map(fn (PrivateChannel $c) => $c->name)->all())->toBe(["private-user.{$this->member->id}"])
        ->and(json_encode($event->broadcastWith()))->not->toContain('Something private');
});

it('announces one record once per request however often it is saved', function () {
    Event::fake([RecordChanged::class]);

    $request = rcApproval($this->business, $this->member);
    $request->update(['status' => ApprovalStatus::Approved]);
    $request->update(['decision_note' => 'fine']);
    rcApproval($this->business, $this->member, 1000);

    expect(rcSent('approval_request'))->toHaveCount(2);

    // A new request (or queue job) starts afresh.
    app()->forgetScopedInstances();
    $request->update(['status' => ApprovalStatus::Rejected]);
    expect(rcSent('approval_request'))->toHaveCount(3)
        ->and(app(RecordChangeBuffer::class))->toBeInstanceOf(RecordChangeBuffer::class);
});

it('announces stock once per product, however many movements moved it', function () {
    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    $warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $make = fn (string $sku) => Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id, 'sku' => $sku, 'name' => $sku, 'type' => ProductType::FinishedGood,
    ]);
    $ghee = $make('GHEE');
    $honey = $make('HONEY');

    Event::fake([RecordChanged::class]);
    foreach ([$ghee, $ghee, $ghee, $honey] as $product) {
        app(RecordStockIn::class)->handle($product, $warehouse, '10', '100', $this->member);
    }

    expect(collect(rcSent('stock'))->pluck('id')->all())->toEqualCanonicalizing([$ghee->id, $honey->id]);
});

it('refreshes the challan when a courier booking failed without writing a parcel', function () {
    Event::fake([RecordChanged::class]);

    CourierParcelChanged::dispatch(CourierParcelChanged::BOOKING_FAILED, $this->business->id, 77);

    expect(collect(rcSent('delivery'))->pluck('id')->all())->toBe([77]);
});

it('never lets a broadcast failure break the write it describes', function () {
    Event::listen(RecordChanged::class, fn () => throw new RuntimeException('socket down'));

    $request = rcApproval($this->business, $this->member);

    expect($request->exists)->toBeTrue()
        ->and(ApprovalRequest::query()->whereKey($request->id)->exists())->toBeTrue();
});
