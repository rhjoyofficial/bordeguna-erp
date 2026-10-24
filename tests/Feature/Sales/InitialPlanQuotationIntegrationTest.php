<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\SalesWorld;

/**
 * ⭐ Integration (25 Oct 2026): IP3's quotation Edit meets IP2's "for sale"
 * switch. A product taken off sale is refused on an edited quote as on a new one.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->s = SalesWorld::make();
    $this->quote = fn (array $lines) => [
        'customer_id' => $this->s->customer->id,
        'valid_until' => '2026-10-31',
        'lines' => $lines,
    ];
});

it('refuses a product that is not for sale when a quotation is edited', function () {
    $this->actingAs($this->s->w->manager)
        ->post('/sales/quotations', ($this->quote)([['product_id' => $this->s->honey->id, 'quantity' => '10', 'unit_price' => '800']]))
        ->assertSessionHasNoErrors();
    $quote = DB::table('quotations')->sole();

    $this->s->honey->forceFill(['can_be_sold' => false])->save();

    $this->actingAs($this->s->w->manager)
        ->put("/sales/quotations/{$quote->id}", ($this->quote)([['product_id' => $this->s->honey->id, 'quantity' => '12', 'unit_price' => '800']]))
        ->assertSessionHasErrors('lines.0.product_id');

    expect((float) DB::table('quotation_lines')->sole()->quantity)->toBe(10.0);
});
