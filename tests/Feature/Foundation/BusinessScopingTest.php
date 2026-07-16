<?php

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Scopes\BusinessScope;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Tests the core row-level tenancy primitive (BelongsToBusiness + BusinessScope
 * + BusinessContext) directly, ahead of any real module (Inventory, etc.) using
 * it — this is the mechanism every future tenant-scoped model depends on, so a
 * leak here would leak everywhere downstream.
 */
class ScopedWidget extends Model
{
    use BelongsToBusiness;

    protected $table = 'scoped_widgets';

    protected $fillable = ['business_id', 'name'];
}

beforeEach(function () {
    Schema::create('scoped_widgets', function ($table) {
        $table->id();
        $table->unsignedBigInteger('business_id');
        $table->string('name');
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('scoped_widgets');
});

it('only returns rows belonging to the current business', function () {
    $laladia = Business::create(['name' => 'LaLaDia', 'slug' => 'laladia']);
    $bionic = Business::create(['name' => 'Bionic', 'slug' => 'bionic']);

    ScopedWidget::withoutGlobalScope(BusinessScope::class)->create(['business_id' => $laladia->id, 'name' => 'LaLaDia widget']);
    ScopedWidget::withoutGlobalScope(BusinessScope::class)->create(['business_id' => $bionic->id, 'name' => 'Bionic widget']);

    app(BusinessContext::class)->set($laladia->id);

    expect(ScopedWidget::pluck('name')->all())->toBe(['LaLaDia widget']);
});

it('cannot load another business\'s row by direct id lookup', function () {
    $laladia = Business::create(['name' => 'LaLaDia', 'slug' => 'laladia']);
    $bionic = Business::create(['name' => 'Bionic', 'slug' => 'bionic']);

    $bionicWidget = ScopedWidget::withoutGlobalScope(BusinessScope::class)
        ->create(['business_id' => $bionic->id, 'name' => 'Bionic widget']);

    app(BusinessContext::class)->set($laladia->id);

    expect(ScopedWidget::find($bionicWidget->id))->toBeNull();
});

it('auto-fills business_id from the current context on create', function () {
    $laladia = Business::create(['name' => 'LaLaDia', 'slug' => 'laladia']);
    app(BusinessContext::class)->set($laladia->id);

    $widget = ScopedWidget::create(['name' => 'Auto widget']);

    expect($widget->business_id)->toBe($laladia->id);
});

it('fails closed and returns no rows when no business context is set', function () {
    $laladia = Business::create(['name' => 'LaLaDia', 'slug' => 'laladia']);
    ScopedWidget::withoutGlobalScope(BusinessScope::class)->create(['business_id' => $laladia->id, 'name' => 'Widget']);

    app(BusinessContext::class)->forget();

    expect(ScopedWidget::count())->toBe(0);
});

it('only exposes cross-business data through the explicit withoutGlobalScope escape hatch', function () {
    $laladia = Business::create(['name' => 'LaLaDia', 'slug' => 'laladia']);
    $bionic = Business::create(['name' => 'Bionic', 'slug' => 'bionic']);

    ScopedWidget::withoutGlobalScope(BusinessScope::class)->create(['business_id' => $laladia->id, 'name' => 'A']);
    ScopedWidget::withoutGlobalScope(BusinessScope::class)->create(['business_id' => $bionic->id, 'name' => 'B']);

    app(BusinessContext::class)->set($laladia->id);

    expect(ScopedWidget::count())->toBe(1);
    expect(ScopedWidget::withoutGlobalScope(BusinessScope::class)->count())->toBe(2);
});
