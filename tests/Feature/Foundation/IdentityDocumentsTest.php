<?php

use App\Models\User;
use App\Modules\Crm\database\seeders\CrmPermissionsSeeder;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Models\Lead;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Models\Customer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * CRM lead slice 2e, part 2: a customer's or supplier's trade licence and NID.
 *
 * ⭐⭐ JOY'S ANSWERS ON TRIAL HERE. Plan D11 and answer 19: an NID is a
 * restricted SCAN, opened by the Owner and nobody else by default, with no number
 * and no search. A trade licence may be seen by anyone who can see the record.
 * The rule agreed on 15 Sep: documents arrive at conversion, never on the lead.
 *
 * ⚠⚠ The gate that matters is the STREAM's. A screen that hides a link while
 * the URL still opens is a guard nothing reaches, so most of these tests request
 * the file itself.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->business = Business::create(['name' => 'Papers Co', 'slug' => 'papers-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new SalesPermissionsSeeder)->run();
    (new PurchasingPermissionsSeeder)->run();
    (new CrmPermissionsSeeder)->run();
    (new WalkInCustomerSeeder)->run();
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
    $this->manager = ($this->person)('Manager');
    $this->staff = ($this->person)('Staff');
    $this->salesLead = ($this->person)('Sales Lead');

    $this->customer = Customer::create(['business_id' => $this->business->id, 'code' => 'RAHIM', 'name' => 'Rahim Store']);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'code' => 'SMT', 'name' => 'Sirajganj Mustard Traders']);

    $this->as = function (User $user) {
        app()->forgetScopedInstances();

        return $this->actingAs($user)->withHeader('X-Business-Id', (string) $this->business->id);
    };

    $this->scan = fn (string $name = 'scan.jpg') => UploadedFile::fake()->image($name, 600, 400);

    $this->upload = fn (User $user, string $kind, array $files, string $base = 'sales/customers', ?int $id = null) => ($this->as)($user)
        ->post("/{$base}/".($id ?? $this->customer->id).'/documents', ['documents' => [$kind => $files]]);

    $this->fileOf = fn (string $kind, $record = null) => Attachment::withoutGlobalScopes()
        ->where('attachable_type', ($record ?? $this->customer)->getMorphClass())
        ->where('attachable_id', ($record ?? $this->customer)->getKey())
        ->where('collection', $kind)->latest('id')->firstOrFail();
});

it('keeps the trade licence and NID that arrive with a conversion, from the ASM who converts', function () {
    $lead = Lead::create([
        'business_id' => $this->business->id, 'lead_type' => 'retailer', 'name' => 'Adabor Grocery',
        'phone' => '01712000111', 'status' => LeadStatus::Qualified, 'assigned_to_user_id' => $this->salesLead->id,
    ]);

    ($this->as)($this->salesLead)->post("/crm/leads/{$lead->id}/convert", [
        'code' => 'ADABOR', 'name' => 'Adabor Grocery',
        'documents' => ['trade_licence' => [($this->scan)('licence.jpg')], 'nid' => [($this->scan)('nid-front.jpg'), ($this->scan)('nid-back.jpg')]],
    ])->assertSessionHasNoErrors();

    $customer = Customer::where('code', 'ADABOR')->sole();

    expect($customer->attachments()->where('collection', 'trade_licence')->pluck('original_name')->all())->toBe(['licence.jpg'])
        ->and($customer->attachments()->where('collection', 'nid')->pluck('original_name')->sort()->values()->all())->toBe(['nid-back.jpg', 'nid-front.jpg']);
});

it('opens a trade licence to anyone in the business, and an NID to the Owner alone', function () {
    ($this->upload)($this->owner, 'trade_licence', [($this->scan)('licence.jpg')])->assertSessionHasNoErrors();
    ($this->upload)($this->owner, 'nid', [($this->scan)('nid.jpg')])->assertSessionHasNoErrors();

    $licence = ($this->fileOf)('trade_licence');
    $nid = ($this->fileOf)('nid');

    foreach ([$this->owner, $this->manager, $this->staff, $this->salesLead] as $reader) {
        ($this->as)($reader)->get(route('attachments.show', $licence))->assertOk();
    }

    ($this->as)($this->owner)->get(route('attachments.show', $nid))->assertOk();

    foreach ([$this->manager, $this->staff, $this->salesLead] as $reader) {
        ($this->as)($reader)->get(route('attachments.show', $nid))->assertForbidden();
    }
});

it('tells the customer screen an NID is on file without naming it to someone who may not open it', function () {
    ($this->upload)($this->owner, 'nid', [($this->scan)('karim-nid.jpg')])->assertSessionHasNoErrors();

    $nidKind = fn (User $user) => collect(($this->as)($user)->get("/sales/customers/{$this->customer->id}")->assertOk()
        ->viewData('page')['props']['documents']['kinds'])->firstWhere('value', 'nid');

    expect($nidKind($this->manager))->toMatchArray(['count' => 1, 'may_open' => false, 'may_remove' => false, 'files' => []])
        ->and($nidKind($this->owner)['files'][0]['name'])->toBe('karim-nid.jpg')
        ->and($nidKind($this->owner)['may_remove'])->toBeTrue();
});

it('lets whoever manages the customer add an NID they cannot open, and only the Owner remove one', function () {
    ($this->upload)($this->manager, 'nid', [($this->scan)('nid.jpg')])->assertSessionHasNoErrors();
    ($this->upload)($this->manager, 'trade_licence', [($this->scan)('licence.jpg')])->assertSessionHasNoErrors();

    $nid = ($this->fileOf)('nid');
    $licence = ($this->fileOf)('trade_licence');

    ($this->as)($this->manager)->delete("/sales/customers/{$this->customer->id}/documents/{$nid->id}")->assertForbidden();
    expect(Attachment::withoutGlobalScopes()->whereKey($nid->id)->exists())->toBeTrue();

    ($this->as)($this->manager)->delete("/sales/customers/{$this->customer->id}/documents/{$licence->id}")->assertRedirect();
    ($this->as)($this->owner)->delete("/sales/customers/{$this->customer->id}/documents/{$nid->id}")->assertRedirect();

    // Corrections wave 3 (24 Oct 2026): a removed file goes to the Recycle bin; the stored file stays for a restore.
    expect(Attachment::withoutGlobalScopes()->whereNull('deleted_at')->whereIn('id', [$nid->id, $licence->id])->exists())->toBeFalse();
    Storage::disk('local')->assertExists($nid->path);
});

it('keeps adding scans away from someone who may not manage customers', function () {
    ($this->upload)($this->staff, 'trade_licence', [($this->scan)()])->assertForbidden();

    expect(Attachment::withoutGlobalScopes()->count())->toBe(0);
});

it('refuses a kind nobody defined, a file that is not a scan, and more than four pages', function (string $kind, Closure $files, string $error) {
    ($this->upload)($this->owner, $kind, $files())->assertSessionHasErrors($error);

    expect(Attachment::withoutGlobalScopes()->count())->toBe(0);
})->with([
    'a passport' => ['passport', fn () => [UploadedFile::fake()->image('passport.jpg')], 'documents'],
    'a spreadsheet' => ['nid', fn () => [UploadedFile::fake()->create('nid.xlsx', 20)], 'documents.nid.0'],
    'five pages' => ['trade_licence', fn () => array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg"), range(1, 5)), 'documents.trade_licence'],
]);

it('will not remove another customer\'s file through this customer', function () {
    $other = Customer::create(['business_id' => $this->business->id, 'code' => 'OTHER', 'name' => 'Other Store']);
    ($this->upload)($this->owner, 'trade_licence', [($this->scan)()], 'sales/customers', $other->id)->assertSessionHasNoErrors();

    $theirs = ($this->fileOf)('trade_licence', $other);

    ($this->as)($this->owner)->delete("/sales/customers/{$this->customer->id}/documents/{$theirs->id}")->assertNotFound();

    expect(Attachment::withoutGlobalScopes()->whereKey($theirs->id)->exists())->toBeTrue();
});

it('holds a supplier to the same rule, under the purchasing identity permission', function () {
    ($this->upload)($this->manager, 'nid', [($this->scan)('trader-nid.jpg')], 'purchasing/suppliers', $this->supplier->id)->assertSessionHasNoErrors();
    ($this->upload)($this->staff, 'nid', [($this->scan)()], 'purchasing/suppliers', $this->supplier->id)->assertForbidden();

    $nid = ($this->fileOf)('nid', $this->supplier);

    ($this->as)($this->manager)->get(route('attachments.show', $nid))->assertForbidden();
    ($this->as)($this->owner)->get(route('attachments.show', $nid))->assertOk();

    $kind = collect(($this->as)($this->manager)->get("/purchasing/suppliers/{$this->supplier->id}")->assertOk()
        ->viewData('page')['props']['documents']['kinds'])->firstWhere('value', 'nid');

    expect($kind)->toMatchArray(['count' => 1, 'may_open' => false, 'files' => []]);
});

it('gives each identity permission to the Owner and to no other role', function () {
    foreach (['sales.customers.identity.view', 'purchasing.suppliers.identity.view'] as $permission) {
        $holders = Role::where('business_id', $this->business->id)->get()
            ->filter(fn (Role $role) => $role->hasPermissionTo($permission))->pluck('name')->all();

        expect($holders)->toBe(['Owner']);
    }
});

it('keeps a customer\'s scans out of the file manager for someone who reads files but not identities', function () {
    ($this->upload)($this->owner, 'nid', [($this->scan)('karim-nid.jpg')])->assertSessionHasNoErrors();

    Permission::firstOrCreate(['name' => 'files.view', 'guard_name' => 'web']);
    Role::where('business_id', $this->business->id)->where('name', 'Manager')->sole()->givePermissionTo('files.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $listed = collect(($this->as)($this->manager)->get('/files')->assertOk()->viewData('page')['props']['files']['data'])->pluck('filename')->all();

    expect($listed)->not->toContain('karim-nid.jpg');
});
