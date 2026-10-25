<?php

use App\Modules\Foundation\database\seeders\ApprovalWorkflowSeeder;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Inventory\Support\ProductLifetime;
use App\Modules\Sales\Models\SampleIssue;
use App\Modules\Sales\Reporting\Definitions\SamplesGivenReport;
use App\Support\Lifetime\LifetimeRange;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SalesWorld;

/**
 * ⭐ Samples (Joy, 25 Oct 2026), the screens: Sales > Sell > Samples (route,
 * screen, the popup's post), the approvals inbox's approve and reject, the
 * Samples given report, the customer page and the product lifetime.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->s = SalesWorld::make();
    (new ApprovalWorkflowSeeder)->run();
});

afterEach(fn () => Carbon::setTestNow());

function samplePost(SalesWorld $s, array $overrides = []): array
{
    return $overrides + [
        'given_on' => '2026-10-15',
        'recipient_kind' => 'customer',
        'recipient_id' => $s->customer->id,
        'purpose' => 'gift',
        'warehouse_id' => $s->warehouse->id,
        'lines' => [['product_id' => $s->honey->id, 'stock_batch_id' => '', 'quantity' => '2']],
        'delivery_method' => 'rider',
        'delivery_charge' => '60',
        'delivery_money_account_id' => $s->w->cash->id,
    ];
}

it('lets Staff give a sample from the Samples screen, and shows it in the list', function () {
    $this->actingAs($this->s->cashier)->post('/sales/samples', samplePost($this->s))->assertSessionHasNoErrors();

    $issue = SampleIssue::query()->sole();

    expect($issue->isPosted())->toBeTrue()
        ->and($issue->given_by)->toBe($this->s->cashier->id);

    $this->actingAs($this->s->cashier)->get('/sales/samples')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('sales/samples/index')
            ->where('canManage', true)
            ->where('samples.data.0.number', $issue->number)
            ->where('samples.data.0.purpose', 'gift')
            ->where('totals.count', 1)
            ->has('options.batches'));
});

it('opens Give sample with the product preset from the product page', function () {
    $this->actingAs($this->s->w->manager)->get("/sales/samples?give={$this->s->honey->id}")
        ->assertInertia(fn (Assert $page) => $page->where('give', $this->s->honey->id));
});

it('refuses somebody without the samples permission', function () {
    $outsider = $this->s->w->member('Finance', 'Rafi');

    $this->actingAs($outsider)->post('/sales/samples', samplePost($this->s))->assertForbidden();
});

it('shows a sentence on the form when stock is short', function () {
    $this->actingAs($this->s->w->manager)
        ->post('/sales/samples', samplePost($this->s, ['lines' => [['product_id' => $this->s->honey->id, 'quantity' => '500']]]))
        ->assertSessionHasErrors('lines.0.quantity');

    expect(SampleIssue::query()->count())->toBe(0);
});

it('approves and rejects from the approvals inbox: only the Owner or a Manager', function () {
    $this->actingAs($this->s->cashier)->post('/sales/samples', samplePost($this->s, ['lines' => [['product_id' => $this->s->honey->id, 'quantity' => '50']]]));
    $issue = SampleIssue::query()->sole();

    expect($issue->isWaiting())->toBeTrue();

    // The inbox draws its card (who, why, the lines) for the Manager.
    $this->actingAs($this->s->w->manager)->get('/approvals')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('requests.0.type', 'Sample'));

    $this->actingAs($this->s->cashier)->post("/sales/samples/{$issue->id}/approve")->assertForbidden();
    $this->actingAs($this->s->w->manager)->post("/sales/samples/{$issue->id}/approve", ['note' => 'Launch'])->assertSessionHas('success');

    expect($issue->fresh()->isPosted())->toBeTrue();

    $this->actingAs($this->s->cashier)->post('/sales/samples', samplePost($this->s, ['lines' => [['product_id' => $this->s->honey->id, 'quantity' => '45']]]));
    $second = SampleIssue::query()->latest('id')->first();
    $this->actingAs($this->s->w->owner)->post("/sales/samples/{$second->id}/reject", ['review_note' => 'Too many'])->assertSessionHas('success');

    expect($second->fresh()->status)->toBe(SampleIssue::REJECTED);

    $this->actingAs($this->s->w->manager)->get('/approvals')->assertOk();
});

it('reports samples by purpose and by product, with the delivery shared to the paisa', function () {
    $this->actingAs($this->s->w->manager)->post('/sales/samples', samplePost($this->s));
    $this->actingAs($this->s->w->manager)->post('/sales/samples', samplePost($this->s, ['purpose' => 'tasting', 'delivery_charge' => '']));

    $byPurpose = app(SamplesGivenReport::class)->rows(new ReportParameters(['given_from' => '2026-10-01', 'given_to' => '2026-10-31', 'by' => 'purpose']))->collect()->keyBy('group');
    $byProduct = app(SamplesGivenReport::class)->rows(new ReportParameters(['by' => 'product']))->collect();

    expect($byPurpose['Gift']['cost'])->toBe(1000.0)
        ->and($byPurpose['Gift']['delivery'])->toBe(60.0)
        ->and($byPurpose['Tasting']['delivery'])->toBe(0.0)
        ->and($byProduct->sole()['samples'])->toBe(2)
        ->and($byProduct->sole()['quantity'])->toBe(4.0)
        ->and($byProduct->sole()['total'])->toBe(2060.0);
});

it('shows the samples a customer received on their page', function () {
    $this->actingAs($this->s->w->manager)->post('/sales/samples', samplePost($this->s));

    $this->actingAs($this->s->w->owner)->get("/sales/customers/{$this->s->customer->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('samples.count', 1)->where('samples.rows.0.purpose', 'gift'));
});

it('counts given as sample in the product lifetime', function () {
    $this->actingAs($this->s->w->manager)->post('/sales/samples', samplePost($this->s));

    $totals = collect((new ProductLifetime($this->s->honey, $this->s->w->owner, LifetimeRange::fromRequest(request())))->totals())->keyBy('key');

    expect($totals['sample_out']['quantity'])->toBe('2');
});

it('fills the delivery charge from the courier zone', function () {
    $this->actingAs($this->s->w->manager)->getJson('/sales/samples/zone?district=Bagerhat')
        ->assertOk()
        ->assertJsonPath('zone', 'Outside Dhaka');
});
