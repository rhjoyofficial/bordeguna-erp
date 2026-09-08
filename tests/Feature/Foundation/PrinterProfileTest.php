<?php

use App\Models\User;
use App\Modules\Foundation\database\seeders\FoundationPermissionsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\PrinterProfile;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\ThermalProfile;
use App\Modules\Inventory\Models\Warehouse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Printer profiles: what the paper at each counter is (Joy, 8 September 2026).
 *
 * ⭐ The point of the whole feature is that NO PRINTER MODEL IS NAMED ANYWHERE.
 * A profile is three numbers - roll width, printable width, dots per inch - and
 * every thermal document lays itself out from them. These tests pin the two
 * rules that are easy to get wrong: which profile wins, and how the barcode is
 * sized so a scanner can read it.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Printer Co', 'slug' => 'printer-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }
    (new FoundationPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Shop', 'code' => 'SH']);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->asOwner = fn () => $this->actingAs($this->owner)
        ->withHeader('X-Business-Id', (string) $this->business->id);
});

it('opens the screen for somebody who may manage the organization', function () {
    ($this->asOwner)()->get(route('printers.index'))->assertOk();
});

it('keeps the screen shut to everybody else', function () {
    // ⚠ Gated on foundation.organization.manage - the same permission as the
    // business profile, deliberately not a new one nobody has been granted.
    $bystander = User::factory()->create();
    $bystander->businesses()->attach($this->business->id);

    $this->actingAs($bystander)->withHeader('X-Business-Id', (string) $this->business->id)
        ->get(route('printers.index'))
        ->assertForbidden();
});

it('refuses a second business default, which the database cannot refuse itself', function () {
    // ⚠⚠ A UNIQUE index on (business_id, warehouse_id) does NOT enforce this:
    // SQL treats every NULL as distinct, so both rows would be accepted and the
    // resolver would silently pick whichever came back first.
    PrinterProfile::create([
        'business_id' => $this->business->id, 'warehouse_id' => null,
        'name' => 'First', 'paper_width_mm' => 80, 'print_width_mm' => 72, 'dpi' => 203,
    ]);

    ($this->asOwner)()->post(route('printers.store'), [
        'warehouse_id' => null, 'name' => 'Second', 'paper_width_mm' => 80, 'print_width_mm' => 72,
        'dpi' => 203, 'base_font_pt' => 8, 'line_height' => 1.3,
        'show_logo' => false, 'show_barcode' => true, 'footer_note' => null, 'is_active' => true,
    ])->assertSessionHasErrors('warehouse_id');

    expect(PrinterProfile::count())->toBe(1);
});

it('refuses a second profile for the same outlet', function () {
    PrinterProfile::create([
        'business_id' => $this->business->id, 'warehouse_id' => $this->warehouse->id,
        'name' => 'Counter', 'paper_width_mm' => 80, 'print_width_mm' => 72, 'dpi' => 203,
    ]);

    ($this->asOwner)()->post(route('printers.store'), [
        'warehouse_id' => $this->warehouse->id, 'name' => 'Counter again', 'paper_width_mm' => 80,
        'print_width_mm' => 72, 'dpi' => 203, 'base_font_pt' => 8, 'line_height' => 1.3,
        'show_logo' => false, 'show_barcode' => true, 'footer_note' => null, 'is_active' => true,
    ])->assertSessionHasErrors('warehouse_id');
});

it('refuses a printable width wider than the paper it is printed on', function () {
    ($this->asOwner)()->post(route('printers.store'), [
        'warehouse_id' => null, 'name' => 'Impossible', 'paper_width_mm' => 58, 'print_width_mm' => 72,
        'dpi' => 203, 'base_font_pt' => 8, 'line_height' => 1.3,
        'show_logo' => false, 'show_barcode' => true, 'footer_note' => null, 'is_active' => true,
    ])->assertSessionHasErrors('print_width_mm');
});

it('makes one barcode bar a whole number of printer dots', function () {
    // ⭐⭐ THE WHOLE POINT OF STORING dpi. A head cannot print 2.16 dots: asked
    // for that width it prints some bars at 2 and some at 3, a 50% error on the
    // element every other measurement in the symbol is judged against. That is
    // the barcode that scans from some angles and not others.
    $at203 = new ThermalProfile(null, '80mm', 80, 72, 203, 8.0, 1.3, false, true, null, 'business');
    $at300 = new ThermalProfile(null, '80mm 300dpi', 80, 72, 300, 8.0, 1.3, false, true, null, 'business');

    expect($at203->moduleDots())->toBe(2)
        ->and($at300->moduleDots())->toBe(3);

    // ⚠ And the resulting bar is never below the physical minimum a thermal
    // head and a counter scanner can agree on, which is why it rounds UP.
    expect($at203->moduleMm())->toBeGreaterThanOrEqual(0.25)
        ->and($at300->moduleMm())->toBeGreaterThanOrEqual(0.25);

    // The printed width is then an exact multiple of that bar.
    $plan = $at203->barcode('INV-2026-27-0001');
    expect(round($plan['width_mm'], 2))->toBe(round($plan['modules'] * $at203->moduleMm(), 2));
});

it('falls back to the built-in paper rather than failing to print', function () {
    // ⚠ A counter that cannot hand over a receipt until somebody visits a
    // settings screen is a counter that stops selling.
    expect(ThermalProfile::resolve(null)->source)->toBe('builtin')
        ->and(ThermalProfile::resolve($this->warehouse->id)->source)->toBe('builtin');
});

it('falls back to the business default when an outlet has no profile of its own', function () {
    PrinterProfile::create([
        'business_id' => $this->business->id, 'warehouse_id' => null,
        'name' => 'Company standard', 'paper_width_mm' => 80, 'print_width_mm' => 72, 'dpi' => 203,
    ]);

    $resolved = ThermalProfile::resolve($this->warehouse->id);

    expect($resolved->source)->toBe('business')
        ->and($resolved->name)->toBe('Company standard');
});

it('ignores a profile that has been switched off', function () {
    PrinterProfile::create([
        'business_id' => $this->business->id, 'warehouse_id' => $this->warehouse->id,
        'name' => 'Broken counter printer', 'paper_width_mm' => 58, 'print_width_mm' => 48,
        'dpi' => 203, 'is_active' => false,
    ]);

    // Switching a profile off is how a shop says "that machine is away being
    // repaired" - the counter must then use the company standard, not stop.
    expect(ThermalProfile::resolve($this->warehouse->id)->source)->toBe('builtin');
});

it('keeps one business away from another business printer profiles', function () {
    $other = Business::create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);

    // ⚠ The context is switched to create the row, not `business_id` passed in
    // the array: BelongsToBusiness stamps the CURRENT business on create and
    // `business_id` is deliberately not fillable, so passing it does nothing.
    // Writing this test the obvious way silently created the row under the
    // wrong business and then proved nothing.
    app(BusinessContext::class)->set($other->id);
    PrinterProfile::create([
        'warehouse_id' => null,
        'name' => 'Their printer', 'paper_width_mm' => 58, 'print_width_mm' => 48, 'dpi' => 203,
    ]);
    app(BusinessContext::class)->set($this->business->id);

    expect(ThermalProfile::resolve(null)->source)->toBe('builtin');
});
