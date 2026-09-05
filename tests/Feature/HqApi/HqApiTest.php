<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
 | The HQ read-first API. What matters most is the negative space: a token for business A must
 | see NOTHING of business B, and a token without an ability must get 403, never a thinner answer.
 */

beforeEach(function () {
    $this->a = Business::create(['name' => 'Alpha Foods', 'slug' => 'alpha', 'fiscal_year_start_month' => 7]);
    $this->b = Business::create(['name' => 'Beta Foods', 'slug' => 'beta', 'fiscal_year_start_month' => 7]);
    $this->hq = User::factory()->create(['email' => 'hq@bordeguna.test']);
    $this->hq->businesses()->attach([$this->a->id, $this->b->id]);

    foreach ([$this->a, $this->b] as $biz) {
        $wh = Warehouse::withoutGlobalScopes()->create(['business_id' => $biz->id, 'name' => 'Main', 'code' => 'MAIN-'.$biz->id, 'is_default' => true]);
        $unit = UnitOfMeasure::withoutGlobalScopes()->create(['business_id' => $biz->id, 'name' => 'Piece', 'abbreviation' => 'pcs']);
        $product = Product::withoutGlobalScopes()->create(['business_id' => $biz->id, 'unit_of_measure_id' => $unit->id, 'sku' => 'SKU-'.$biz->slug, 'name' => $biz->name.' jar', 'type' => ProductType::FinishedGood, 'is_active' => true, 'low_stock_threshold' => 10]);
        StockBatch::withoutGlobalScopes()->create(['business_id' => $biz->id, 'product_id' => $product->id, 'warehouse_id' => $wh->id, 'batch_number' => 'B-'.$biz->id, 'unit_cost' => '100', 'conversion_factor' => '1', 'initial_quantity' => '5', 'current_quantity' => '5', 'status' => 'active', 'received_at' => now()]);
        Customer::withoutGlobalScopes()->create(['business_id' => $biz->id, 'code' => 'C-'.$biz->id, 'name' => $biz->name.' customer', 'type' => 'retail', 'phone' => '0171100000'.$biz->id, 'is_active' => true]);
    }

    $this->tokenFor = fn (Business $biz, array $abilities = ['inventory.view', 'inventory.reports.view', 'sales.customers.view', 'sales.reports.view', 'crm.tickets.view', 'crm.tickets.manage']) => Sanctum::actingAs($this->hq, array_merge($abilities, ["hq:business:{$biz->id}"]));
});

it('refuses anonymous callers and tokens issued for another business', function () {
    $this->getJson("/api/hq/v1/{$this->a->id}/overview")->assertStatus(401);

    ($this->tokenFor)($this->a);
    $this->getJson("/api/hq/v1/{$this->b->id}/overview")->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    $this->getJson("/api/hq/v1/{$this->b->id}/stock")->assertStatus(403);
    $this->getJson("/api/hq/v1/{$this->b->id}/customers")->assertStatus(403);
});

it('lists only the businesses the token names', function () {
    ($this->tokenFor)($this->a);
    $this->getJson('/api/hq/v1/businesses')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Alpha Foods');
});

it('scopes every read to the token business, so business B data never appears', function () {
    ($this->tokenFor)($this->a);
    $stock = $this->getJson("/api/hq/v1/{$this->a->id}/stock")->assertOk()->assertHeader('X-Business-Id', (string) $this->a->id);
    expect(collect($stock->json('data'))->pluck('sku')->all())->toBe(['SKU-alpha']);

    $products = $this->getJson("/api/hq/v1/{$this->a->id}/products")->assertOk();
    expect(collect($products->json('data'))->pluck('sku')->all())->toBe(['SKU-alpha'])
        ->and((float) $products->json('data.0.avg_unit_cost'))->toBe(100.0);
    $this->getJson("/api/hq/v1/{$this->a->id}/products/SKU-beta")->assertStatus(404);
    $this->getJson("/api/hq/v1/{$this->a->id}/products/SKU-alpha")->assertOk()->assertJsonPath('data.on_hand', 5);

    $customers = $this->getJson("/api/hq/v1/{$this->a->id}/customers")->assertOk();
    expect(collect($customers->json('data'))->pluck('name')->all())->toBe(['Alpha Foods customer']);
    $betaCustomer = Customer::withoutGlobalScopes()->where('business_id', $this->b->id)->first();
    $this->getJson("/api/hq/v1/{$this->a->id}/customers/{$betaCustomer->id}")->assertStatus(404);

    $this->getJson("/api/hq/v1/{$this->a->id}/stock/low")->assertOk()->assertJsonPath('meta.count', 1)->assertJsonPath('data.0.sku', 'SKU-alpha');
    $this->getJson("/api/hq/v1/{$this->a->id}/overview")->assertOk()
        ->assertJsonPath('data.inventory.active_products', 1)->assertJsonPath('data.inventory.stock_value', 500)->assertJsonPath('data.sales.customers', 1);
});

it('withholds sections and endpoints the token has no ability for, and hides cost without the reports ability', function () {
    ($this->tokenFor)($this->a, ['inventory.view']);
    $this->getJson("/api/hq/v1/{$this->a->id}/stock")->assertStatus(403)->assertJsonPath('error.message', 'Token lacks ability inventory.reports.view.');
    $this->getJson("/api/hq/v1/{$this->a->id}/customers")->assertStatus(403);
    $this->getJson("/api/hq/v1/{$this->a->id}/tickets")->assertStatus(403);
    $this->postJson("/api/hq/v1/{$this->a->id}/tickets", ['subject' => 'x', 'caller_name' => 'y', 'category' => 'other', 'priority' => 'normal'])->assertStatus(403);
    expect($this->getJson("/api/hq/v1/{$this->a->id}/products")->assertOk()->json('data.0'))->not->toHaveKey('avg_unit_cost');
    $overview = $this->getJson("/api/hq/v1/{$this->a->id}/overview")->assertOk()->json('data');
    expect($overview['sales'])->toBeNull()->and($overview['inventory'])->toBeNull()->and($overview['tickets'])->toBeNull();
});

it('opens a ticket with the CRM numbering and SLA, replays on the same idempotency key, and validates like the screen', function () {
    ($this->tokenFor)($this->a);
    $customer = Customer::withoutGlobalScopes()->where('business_id', $this->a->id)->first();
    $payload = ['subject' => 'Late delivery', 'description' => 'from HQ conversation', 'customer_id' => $customer->id, 'category' => 'delivery', 'priority' => 'high', 'idempotency_key' => 'conv-77'];

    $first = $this->postJson("/api/hq/v1/{$this->a->id}/tickets", $payload)->assertStatus(201)->assertJsonPath('data.replayed', false);
    $second = $this->postJson("/api/hq/v1/{$this->a->id}/tickets", $payload)->assertOk()->assertJsonPath('data.replayed', true);
    expect($second->json('data.ticket_number'))->toBe($first->json('data.ticket_number'))->and(DB::table('crm_tickets')->count())->toBe(1);

    $row = DB::table('crm_tickets')->first();
    expect($row->business_id)->toBe($this->a->id)->and($row->status)->toBe('open')->and($row->external_ref)->toBe('hq:conv-77')
        ->and($row->ticket_number)->toStartWith('TK-')->and($row->created_by)->toBe($this->hq->id)
        ->and(now()->diffInHours($row->resolve_by, false))->toBeGreaterThan(23.0)->toBeLessThanOrEqual(24.0);

    $this->getJson("/api/hq/v1/{$this->a->id}/tickets")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject', 'Late delivery');

    // Business B's customer cannot be attached to business A's ticket; an unknown priority is refused.
    $beta = Customer::withoutGlobalScopes()->where('business_id', $this->b->id)->first();
    $this->postJson("/api/hq/v1/{$this->a->id}/tickets", ['idempotency_key' => 'conv-78', 'customer_id' => $beta->id] + $payload)->assertStatus(422);
    $this->postJson("/api/hq/v1/{$this->a->id}/tickets", ['subject' => 'x', 'caller_name' => 'y', 'category' => 'other', 'priority' => 'urgent'])->assertStatus(422);
});

it('lists reports the token may run and runs one inside the business scope', function () {
    ($this->tokenFor)($this->a, ['inventory.reports.view']);
    $list = $this->getJson("/api/hq/v1/{$this->a->id}/reports")->assertOk()->json('data');
    foreach ($list as $r) {
        expect($r['permission'])->toBe('inventory.reports.view');
    }
    $this->postJson("/api/hq/v1/{$this->a->id}/reports/does-not-exist/run")->assertStatus(404);
    if ($list !== []) {
        $this->postJson("/api/hq/v1/{$this->a->id}/reports/{$list[0]['key']}/run", ['limit' => 10])->assertOk()->assertJsonStructure(['data' => ['columns', 'rows', 'truncated']]);
    }
});

it('issues a business-scoped token from the console and refuses abilities outside the allow-list', function () {
    $this->artisan('hq:api:token', ['email' => 'hq@bordeguna.test', 'business' => $this->a->id, '--abilities' => 'inventory.view,payroll.run'])->assertFailed();
    $this->artisan('hq:api:token', ['email' => 'hq@bordeguna.test', 'business' => $this->a->id, '--abilities' => 'inventory.view'])->assertSuccessful();
    $token = $this->hq->tokens()->first();
    expect($token->name)->toBe("hq:business:{$this->a->id}")->and($token->abilities)->toEqualCanonicalizing(['inventory.view', "hq:business:{$this->a->id}"]);
    $this->artisan('hq:api:token', ['email' => 'hq@bordeguna.test', 'business' => $this->a->id, '--revoke' => true])->assertSuccessful();
    expect($this->hq->tokens()->count())->toBe(0);
});
