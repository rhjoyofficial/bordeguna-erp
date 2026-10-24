<?php

use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Crm\Models\Contact;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesWorld;

/**
 * ⭐ Corrections follow-up (B13, 24 Oct 2026; FINAL-PLAN section 7 "Merges
 * (customer, supplier, ledger head 'Move balance and retire'), Owner only, dry
 * run first"). All or nothing; a dry run changes nothing; the merge needs the
 * dry run's token; the balances add up.
 */
beforeEach(function () {
    $this->s = SalesWorld::make();
    $this->w = $this->s->w;

    $permissions = ['sales.customers.manage', 'sales.reports.view', 'purchasing.suppliers.manage', 'purchasing.reports.view', 'accounting.view', 'accounting.chart.manage'];
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }
    foreach (['Owner', 'Manager'] as $role) {
        Role::where('name', $role)->where('business_id', $this->w->business->id)->firstOrFail()->givePermissionTo($permissions);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Karim Traders was typed twice: "Karim Trader" got an invoice and a receipt too.
    $this->kept = $this->s->customer;
    $this->gone = Customer::query()->create(['business_id' => $this->w->business->id, 'name' => 'Karim Trader', 'type' => 'dealer', 'phone' => '01700000001']);
    $this->s->invoice($this->s->challan($this->s->order('10', '850')));

    $this->s->customer = $this->gone;
    $invoice = $this->s->invoice($this->s->challan($this->s->order('4', '850'), '4'));
    $this->s->receive($invoice, '1000', '2026-10-14');
    $this->s->customer = $this->kept;

    Contact::query()->create(['business_id' => $this->w->business->id, 'name' => 'Karim', 'phone' => '01700000001', 'linked_type' => $this->gone->getMorphClass(), 'linked_id' => $this->gone->id]);

    $this->partyBalance = fn (Customer $c) => (int) DB::table('journal_lines')->where('party_type', $c->getMorphClass())->where('party_id', $c->id)->sum('amount_minor');
    $this->merge = fn (array $body, $user = null) => $this->actingAs($user ?? $this->w->owner)
        ->postJson(route('accounting.merge.party', ['customer', $this->gone->id]), $body + ['into_id' => $this->kept->id, 'reason_text' => 'The same shop typed twice']);
});

afterEach(fn () => Carbon::setTestNow());

it('dry run shows what moves and the balances, and changes nothing', function () {
    $keptBefore = ($this->partyBalance)($this->kept);
    $goneBefore = ($this->partyBalance)($this->gone);

    $response = ($this->merge)(['dry_run' => true])->assertOk();

    expect($response->json('dry_run'))->toBeTrue()
        ->and($response->json('token'))->toBeString()
        ->and($response->json('moves')['sales_orders.customer_id'])->toBe(1)
        ->and($response->json('moves')['sales_invoices.customer_id'])->toBe(1)
        ->and($response->json('moves')['journal_lines.party_id'])->toBeGreaterThan(0)
        ->and($response->json('moves')['contacts.linked_id'])->toBe(1)
        // Nothing changed.
        ->and(SalesOrder::query()->where('customer_id', $this->gone->id)->count())->toBe(1)
        ->and(($this->partyBalance)($this->gone))->toBe($goneBefore)
        ->and(($this->partyBalance)($this->kept))->toBe($keptBefore)
        ->and($this->gone->fresh()->is_active)->toBeTrue();
});

it('merges after the dry run: every document, ledger tag and phone book link moves; the balances add up; the merged one points at the kept one', function () {
    $keptBefore = ($this->partyBalance)($this->kept);
    $goneBefore = ($this->partyBalance)($this->gone);

    // No dry run: refused.
    ($this->merge)(['dry_run' => false])->assertStatus(422)->assertJsonPath('errors.merge.0', fn ($m) => str_contains($m, 'dry run'));

    $token = ($this->merge)(['dry_run' => true])->json('token');
    ($this->merge)(['dry_run' => false, 'token' => $token])->assertOk();

    $gone = $this->gone->fresh();
    expect($gone->is_active)->toBeFalse()
        ->and((int) $gone->merged_into_id)->toBe($this->kept->id)
        ->and(SalesOrder::query()->where('customer_id', $this->gone->id)->count())->toBe(0)
        ->and(SalesInvoice::query()->where('customer_id', $this->kept->id)->count())->toBe(2)
        ->and(($this->partyBalance)($this->gone))->toBe(0)
        ->and(($this->partyBalance)($this->kept))->toBe($keptBefore + $goneBefore)
        ->and((int) Contact::query()->sole()->linked_id)->toBe($this->kept->id)
        ->and(Activity::query()->where('log_name', 'corrections')->where('event', 'merged')->count())->toBe(1)
        ->and($this->s->reconciliationProblems())->toBe([]);

    // The merged one cannot be reactivated, and cannot be merged again.
    expect($gone->whyNotReactivatable())->toContain('merged into');
    ($this->merge)(['dry_run' => true])->assertStatus(422);
});

it('is the Owner\'s alone, and refuses a closed month, a system customer and the same record twice', function () {
    ($this->merge)(['dry_run' => true], $this->w->manager)->assertStatus(422)->assertJsonPath('errors.merge.0', 'Only the Owner can merge two records.');

    $this->actingAs($this->w->owner)->postJson(route('accounting.merge.party', ['customer', $this->kept->id]), ['into_id' => $this->kept->id, 'reason_text' => 'Typed twice', 'dry_run' => true])
        ->assertStatus(422)->assertJsonPath('errors.merge.0', 'Pick two different records.');

    $walkIn = Customer::query()->create(['business_id' => $this->w->business->id, 'name' => 'Walk-in', 'code' => 'WALK-IN', 'type' => 'retail', 'is_system' => true]);
    $this->actingAs($this->w->owner)->postJson(route('accounting.merge.party', ['customer', $walkIn->id]), ['into_id' => $this->kept->id, 'reason_text' => 'Typed twice', 'dry_run' => true])
        ->assertStatus(422);

    $this->w->closeThrough('2026-10-15');
    ($this->merge)(['dry_run' => true])->assertStatus(422)->assertJsonPath('errors.merge.0', fn ($m) => str_contains($m, 'closed month'));
});

it('merges two suppliers the same way', function () {
    $keep = Supplier::query()->create(['business_id' => $this->w->business->id, 'code' => 'PADMA', 'name' => 'Padma Oil', 'is_active' => true]);
    $drop = Supplier::query()->create(['business_id' => $this->w->business->id, 'code' => 'PADMA2', 'name' => 'Padma Oil Mills', 'is_active' => true]);
    $url = route('accounting.merge.party', ['supplier', $drop->id]);

    $token = $this->actingAs($this->w->owner)->postJson($url, ['into_id' => $keep->id, 'reason_text' => 'Typed twice', 'dry_run' => true])->assertOk()->json('token');
    $this->actingAs($this->w->owner)->postJson($url, ['into_id' => $keep->id, 'reason_text' => 'Typed twice', 'dry_run' => false, 'token' => $token])->assertOk();

    expect((int) $drop->fresh()->merged_into_id)->toBe($keep->id)->and($drop->fresh()->is_active)->toBeFalse();
});

// ─── Ledger head: Move balance and retire ───────────────────────────────────

it('moves a head\'s balance with one journal and retires it; history stays where it was', function () {
    $electricity = Account::query()->where('code', '6051')->sole();
    $twin = Account::query()->create(['code' => 'X6051', 'name' => 'Electric bill', 'type' => $electricity->type->value, 'parent_id' => $electricity->parent_id]);
    $cash = Account::bySlug('cash_in_hand');
    app(PostJournal::class)->handle(new JournalDraft(Carbon::parse('2026-10-05'), [
        JournalLineDraft::debit($twin->id, Money::ofTaka('700'), 'October bill'),
        JournalLineDraft::credit($cash->id, Money::ofTaka('700'), 'October bill'),
    ], 'Electric bill'), $this->w->owner);

    $url = route('accounting.accounts.retire', $twin->id);
    $body = ['into_id' => $electricity->id, 'on' => '2026-10-17', 'reason_text' => 'Two heads for one cost'];

    $this->actingAs($this->w->manager)->postJson($url, $body + ['dry_run' => true])->assertStatus(422);

    $journals = Journal::query()->count();
    $dry = $this->actingAs($this->w->owner)->postJson($url, $body + ['dry_run' => true])->assertOk();
    expect($dry->json('balances.from_before'))->toBe('Tk 700')
        ->and(Journal::query()->count())->toBe($journals);

    $this->actingAs($this->w->owner)->postJson($url, $body + ['dry_run' => false, 'token' => $dry->json('token')])->assertOk();

    expect($this->w->balance($twin))->toBe(0)
        ->and($this->w->balance($electricity))->toBe(70000)
        ->and($this->w->balance($twin, '2026-10-01', '2026-10-16'))->toBe(70000)
        ->and($twin->fresh()->is_active)->toBeFalse()
        ->and((int) $twin->fresh()->merged_into_id)->toBe($electricity->id);
});

it('refuses to retire a system head, a head of another kind, and a closed day', function () {
    $electricity = Account::query()->where('code', '6051')->sole();
    $url = route('accounting.accounts.retire', Account::bySlug('cash_in_hand')->id);

    $this->actingAs($this->w->owner)->postJson($url, ['into_id' => $electricity->id, 'on' => '2026-10-17', 'reason_text' => 'Two heads for one cost', 'dry_run' => true])
        ->assertStatus(422);

    $twin = Account::query()->create(['code' => 'X6051', 'name' => 'Electric bill', 'type' => $electricity->type->value, 'parent_id' => $electricity->parent_id]);
    $this->w->closeThrough('2026-09-30');
    $this->actingAs($this->w->owner)->postJson(route('accounting.accounts.retire', $twin->id), ['into_id' => $electricity->id, 'on' => '2026-09-20', 'reason_text' => 'Two heads for one cost', 'dry_run' => true])
        ->assertStatus(422)->assertJsonPath('errors.merge.0', fn ($m) => str_contains($m, 'closed month'));
});
