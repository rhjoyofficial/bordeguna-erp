<?php

use App\Models\User;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Enums\CertificationType;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierCertification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * CRM lead slice 2e, part 4: a supplier's certifications.
 *
 * ⭐⭐ JOY'S ANSWER 18 ON TRIAL HERE: certifications ONLY WHEN RELEVANT, as
 * optional entries on the supplier record - type (BSTI CM, BFSA, Halal, ISO
 * 22000, HACCP or Other), number, expiry, scan - editable from the supplier
 * screen. Nothing requires one, and nothing is blocked without one.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-15 10:00:00');
    Storage::fake('local');

    $this->business = Business::create(['name' => 'Cert Co', 'slug' => 'cert-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff', 'Self-service'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }
    (new PurchasingPermissionsSeeder)->run();
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->person = function (string $role): User {
        $user = User::factory()->create(['name' => $role]);
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->owner = ($this->person)('Owner');
    $this->staff = ($this->person)('Staff');
    $this->nobody = ($this->person)('Self-service');

    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'code' => 'SMT', 'name' => 'Sirajganj Mustard Traders']);
    $this->other = Supplier::create(['business_id' => $this->business->id, 'code' => 'OTH', 'name' => 'Other Trader']);

    $this->as = function (User $user) {
        app()->forgetScopedInstances();

        return $this->actingAs($user)->withHeader('X-Business-Id', (string) $this->business->id);
    };

    $this->base = fn (?Supplier $supplier = null) => '/purchasing/suppliers/'.($supplier ?? $this->supplier)->id.'/certifications';

    $this->certify = fn (array $data = []) => SupplierCertification::create(array_merge([
        'business_id' => $this->business->id, 'supplier_id' => $this->supplier->id, 'type' => 'bsti_cm',
        'number' => 'BSTI-2025-0042', 'expires_on' => '2027-03-31',
    ], $data));
});

afterEach(fn () => Carbon::setTestNow());

it('records a certification with its number, expiry and scan', function () {
    ($this->as)($this->owner)->post(($this->base)(), [
        'type' => 'bsti_cm', 'number' => 'BSTI-2025-0042', 'expires_on' => '2027-03-31',
        'scan' => [UploadedFile::fake()->image('bsti.jpg')],
    ])->assertSessionHasNoErrors();

    $cert = SupplierCertification::sole();

    expect($cert->type)->toBe(CertificationType::BstiCm)
        ->and($cert->only(['supplier_id', 'number', 'recorded_by']))->toBe(['supplier_id' => $this->supplier->id, 'number' => 'BSTI-2025-0042', 'recorded_by' => $this->owner->id])
        ->and($cert->expires_on->toDateString())->toBe('2027-03-31')
        ->and($cert->attachments()->where('collection', 'scan')->pluck('original_name')->all())->toBe(['bsti.jpg']);

    $shown = ($this->as)($this->staff)->get("/purchasing/suppliers/{$this->supplier->id}")->assertOk()->viewData('page')['props'];

    expect($shown['certifications'][0])->toMatchArray(['label' => 'BSTI CM', 'number' => 'BSTI-2025-0042', 'is_expired' => false])
        ->and($shown['certifications'][0]['scans'][0]['name'])->toBe('bsti.jpg')
        ->and($shown['can_manage_certifications'])->toBeFalse()
        ->and(collect($shown['certification_types'])->pluck('label')->all())->toBe(['BSTI CM', 'BFSA', 'Halal', 'ISO 22000', 'HACCP', 'Other']);
});

it('needs only the type, and names an Other certification by what was typed', function () {
    ($this->as)($this->owner)->post(($this->base)(), ['type' => 'other', 'name' => 'Organic (BD)'])->assertSessionHasNoErrors();
    ($this->as)($this->owner)->post(($this->base)(), ['type' => 'halal', 'name' => 'ignored for a known type'])->assertSessionHasNoErrors();
    ($this->as)($this->owner)->post(($this->base)(), ['number' => 'no type'])->assertSessionHasErrors('type');
    ($this->as)($this->owner)->post(($this->base)(), ['type' => 'fssc_22000'])->assertSessionHasErrors('type');

    expect(SupplierCertification::orderBy('id')->get()->map->label()->all())->toBe(['Organic (BD)', 'Halal'])
        ->and(SupplierCertification::where('type', 'halal')->sole()->name)->toBeNull();
});

it('marks a certificate expired only after its expiry day', function () {
    $yesterday = ($this->certify)(['expires_on' => '2026-09-14']);
    $today = ($this->certify)(['expires_on' => '2026-09-15', 'type' => 'bfsa']);
    $none = ($this->certify)(['expires_on' => null, 'type' => 'halal']);

    expect($yesterday->isExpired())->toBeTrue()
        ->and($today->isExpired())->toBeFalse()
        ->and($none->isExpired())->toBeFalse();
});

it('edits a renewed certificate and keeps the old scan until someone removes it', function () {
    $cert = ($this->certify)();
    app(StoreAttachment::class)->handle($cert, UploadedFile::fake()->image('old.jpg'), 'scan', $this->owner);

    ($this->as)($this->owner)->post(($this->base)()."/{$cert->id}", [
        'type' => 'bsti_cm', 'number' => 'BSTI-2026-0107', 'expires_on' => '2028-03-31',
        'scan' => [UploadedFile::fake()->image('renewed.jpg')],
    ])->assertSessionHasNoErrors();

    $cert->refresh();

    expect($cert->number)->toBe('BSTI-2026-0107')
        ->and($cert->expires_on->toDateString())->toBe('2028-03-31')
        ->and($cert->attachments()->pluck('original_name')->sort()->values()->all())->toBe(['old.jpg', 'renewed.jpg']);

    $old = $cert->attachments()->where('original_name', 'old.jpg')->sole();
    ($this->as)($this->owner)->delete(($this->base)()."/{$cert->id}/scans/{$old->id}")->assertRedirect();

    expect($cert->attachments()->pluck('original_name')->all())->toBe(['renewed.jpg']);
    // Corrections wave 3 (24 Oct 2026): a removed file goes to the Recycle bin; the stored file stays for a restore.
    Storage::disk('local')->assertExists($old->path);
});

it('removes a certification together with its scans', function () {
    $cert = ($this->certify)();
    $scan = app(StoreAttachment::class)->handle($cert, UploadedFile::fake()->image('bsti.jpg'), 'scan', $this->owner);

    ($this->as)($this->owner)->delete(($this->base)()."/{$cert->id}")->assertRedirect();

    expect(SupplierCertification::count())->toBe(0)
        ->and(Attachment::withoutGlobalScopes()->whereNull('deleted_at')->count())->toBe(0);
    // Corrections wave 3 (24 Oct 2026): a removed file goes to the Recycle bin; the stored file stays for a restore.
    Storage::disk('local')->assertExists($scan->path);
});

it('keeps changes with whoever manages suppliers', function () {
    $cert = ($this->certify)();

    ($this->as)($this->staff)->post(($this->base)(), ['type' => 'halal'])->assertForbidden();
    ($this->as)($this->staff)->post(($this->base)()."/{$cert->id}", ['type' => 'halal'])->assertForbidden();
    ($this->as)($this->staff)->delete(($this->base)()."/{$cert->id}")->assertForbidden();

    expect(SupplierCertification::sole()->type)->toBe(CertificationType::BstiCm);
});

it('finds a certification only under its own supplier', function () {
    $cert = ($this->certify)();

    ($this->as)($this->owner)->post(($this->base)($this->other)."/{$cert->id}", ['type' => 'halal'])->assertNotFound();
    ($this->as)($this->owner)->delete(($this->base)($this->other)."/{$cert->id}")->assertNotFound();

    expect(SupplierCertification::sole()->type)->toBe(CertificationType::BstiCm);
});

it('opens a certificate scan to anyone who may read purchasing, and to nobody else', function () {
    $cert = ($this->certify)();
    $scan = app(StoreAttachment::class)->handle($cert, UploadedFile::fake()->image('bsti.jpg'), 'scan', $this->owner);

    ($this->as)($this->staff)->get(route('attachments.show', $scan))->assertOk();
    ($this->as)($this->nobody)->get(route('attachments.show', $scan))->assertForbidden();
});

it('removes a scan only through the certification it belongs to', function () {
    $mine = ($this->certify)();
    $theirs = ($this->certify)(['type' => 'halal']);
    $theirScan = app(StoreAttachment::class)->handle($theirs, UploadedFile::fake()->image('halal.jpg'), 'scan', $this->owner);

    ($this->as)($this->owner)->delete(($this->base)()."/{$mine->id}/scans/{$theirScan->id}")->assertNotFound();

    expect(Attachment::withoutGlobalScopes()->whereKey($theirScan->id)->exists())->toBeTrue();
});
