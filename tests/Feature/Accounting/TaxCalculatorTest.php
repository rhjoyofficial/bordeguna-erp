<?php

use App\Modules\Accounting\Models\TaxClass;
use App\Modules\Accounting\Models\TaxRate;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\TaxCalculator;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The one place VAT is calculated. These pin the arithmetic both ways round,
 * the date rule, and the refusal that stops a missing rate taxing at zero.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Tax Co', 'slug' => 'tax-co']);
    app(BusinessContext::class)->set($this->business->id);

    foreach (['STANDARD' => 'Standard', 'ZERO' => 'Zero-rated', 'EXEMPT' => 'Exempt'] as $code => $name) {
        $this->{strtolower($code)} = TaxClass::create(['business_id' => $this->business->id, 'code' => $code, 'name' => $name]);
    }

    $this->calculator = new TaxCalculator;
});

function switchVatOn(Business $business, string $from = '2026-10-01'): Business
{
    $business->forceFill(['vat_enabled' => true, 'vat_effective_from' => $from])->save();

    return $business->fresh();
}

function standardRate(string $rate, string $from = '2026-10-01'): void
{
    TaxRate::create([
        'business_id' => test()->business->id, 'tax_class_id' => test()->standard->id,
        'rate' => $rate, 'effective_from' => $from,
    ]);
}

it('adds VAT on top of an exclusive price', function () {
    $split = $this->calculator->split(Money::ofTaka('1000'), '15', inclusive: false);

    expect($split->net->toTaka())->toBe('1000.00')
        ->and($split->tax->toTaka())->toBe('150.00')
        ->and($split->gross->toTaka())->toBe('1150.00')
        ->and($split->rate)->toBe('15.0000');
});

it('extracts VAT from an inclusive price, and the customer pays exactly the price', function () {
    $split = $this->calculator->split(Money::ofTaka('1150'), '15', inclusive: true);

    expect($split->gross->toTaka())->toBe('1150.00')
        ->and($split->tax->toTaka())->toBe('150.00')
        ->and($split->net->toTaka())->toBe('1000.00');
});

it('never loses a paisa: net plus tax is the gross, whatever the figure', function () {
    foreach (['0.01', '0.07', '1', '99.99', '350', '1234.56', '999999.99'] as $amount) {
        foreach (['15', '7.5', '5', '4', '2.4'] as $rate) {
            foreach ([true, false] as $inclusive) {
                $split = $this->calculator->split(Money::ofTaka($amount), $rate, $inclusive);

                expect($split->net->minor + $split->tax->minor)->toBe($split->gross->minor);
                expect($split->tax->minor)->toBeGreaterThanOrEqual(0);
            }
        }
    }
});

it('rounds half a paisa away from zero', function () {
    // 0.10 at 15% exclusive is 0.015 -> 0.02.
    expect($this->calculator->split(Money::ofTaka('0.10'), '15', false)->tax->toTaka())->toBe('0.02');
});

it('reverses a return with the sign of the amount', function () {
    $split = $this->calculator->split(Money::ofTaka('-1150'), '15', inclusive: true);

    expect($split->tax->toTaka())->toBe('-150.00')->and($split->net->toTaka())->toBe('-1000.00');
});

it('charges nothing while VAT is switched off', function () {
    standardRate('15', '2026-01-01');

    expect($this->calculator->rateFor($this->business, null, Carbon::parse('2026-10-05')))->toBe('0.0000');
});

it('charges nothing before the effective date, and the rate from it', function () {
    standardRate('15', '2026-01-01');
    $business = switchVatOn($this->business, '2026-10-01');

    expect($this->calculator->rateFor($business, null, Carbon::parse('2026-09-30')))->toBe('0.0000');
    expect($this->calculator->rateFor($business, null, Carbon::parse('2026-10-01')))->toBe('15.0000');
});

it('takes the rate in force on the document date, not today\'s', function () {
    standardRate('15', '2026-01-01');
    standardRate('7.5', '2027-01-01');
    $business = switchVatOn($this->business, '2026-01-01');

    expect($this->calculator->rateFor($business, $this->standard->id, Carbon::parse('2026-12-31')))->toBe('15.0000');
    expect($this->calculator->rateFor($business, $this->standard->id, Carbon::parse('2027-01-01')))->toBe('7.5000');
});

it('treats zero-rated and exempt as zero without needing a rate row', function () {
    standardRate('15', '2026-01-01');
    $business = switchVatOn($this->business, '2026-01-01');
    $on = Carbon::parse('2026-10-05');

    expect($this->calculator->rateFor($business, $this->zero->id, $on))->toBe('0.0000');
    expect($this->calculator->rateFor($business, $this->exempt->id, $on))->toBe('0.0000');
});

it('refuses rather than taxing at zero when VAT is on and no standard rate is set', function () {
    $business = switchVatOn($this->business, '2026-10-01');

    expect(fn () => $this->calculator->rateFor($business, null, Carbon::parse('2026-10-05')))
        ->toThrow(ValidationException::class);
});

it('never reads another business\'s tax class', function () {
    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-co']);
    $foreign = TaxClass::withoutGlobalScopes()->create(['business_id' => $other->id, 'code' => 'ZERO', 'name' => 'Zero-rated']);
    standardRate('15', '2026-01-01');
    $business = switchVatOn($this->business, '2026-01-01');

    // A foreign class id is "not found", so the business's own standard applies.
    expect($this->calculator->rateFor($business, $foreign->id, Carbon::parse('2026-10-05')))->toBe('15.0000');
});
