<?php

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Models\Supplier;
use Spatie\Activitylog\Models\Activity;

/**
 * ⚠⚠ WHY THIS FILE EXISTS.
 *
 * On 19 August a supplier's code was corrected (PREMI to PREME) and two
 * warehouse codes were renamed on production, and activity_log did not move -
 * while the same session's Product edits were logged. The warehouse CODE is
 * the opening-stock import's matching key, written once per warehouse for the
 * life of the business, and the supplier CODE is how the AP subledger names a
 * party. The identities everything matches on were the only master data that
 * could change without a trail.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Audit Co', 'slug' => 'audit-co']);
    app(BusinessContext::class)->set($this->business->id);
});

it('logs a warehouse identity change', function () {
    $warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Adabor', 'code' => 'ADABOR']);

    $warehouse->update(['code' => 'BDGO', 'name' => 'Bor de Guna Office']);

    $entry = Activity::query()
        ->where('subject_type', Warehouse::class)
        ->where('subject_id', $warehouse->id)
        ->where('description', 'updated')
        ->sole();

    expect($entry->properties['attributes']['code'])->toBe('BDGO')
        ->and($entry->properties['old']['code'])->toBe('ADABOR');
});

it('logs a supplier identity change and skips the chatty contact card', function () {
    $supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Premi Farm', 'code' => 'PREMI']);

    $supplier->update(['code' => 'PREME', 'name' => 'Preme Farm']);

    $entry = Activity::query()
        ->where('subject_type', Supplier::class)
        ->where('subject_id', $supplier->id)
        ->where('description', 'updated')
        ->sole();

    expect($entry->properties['attributes']['code'])->toBe('PREME')
        ->and($entry->properties['old']['code'])->toBe('PREMI');

    // A contact-card edit alone writes NO row - the log records identity and
    // money-relevant fields, not every phone number correction.
    $supplier->update(['contact_name' => 'Somebody New']);

    expect(Activity::query()
        ->where('subject_type', Supplier::class)
        ->where('subject_id', $supplier->id)
        ->count())->toBe(2); // created + the identity update, nothing more
});
