<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The supplier screen, through the real HTTP layer.
 *
 * ⚠⚠ THE RULE THIS FILE EXISTS TO ENFORCE (Joy, 2026-08-05): the UI and the
 * import must produce the SAME business object. A supplier created by hand must
 * never be structurally different from an imported one - otherwise the import's
 * idempotency key is missing on exactly the records somebody typed in a hurry,
 * and the next import run duplicates them instead of updating them.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Screen Co', 'slug' => 'supplier-screen-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new PurchasingPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user);
    };

    $this->full = [
        'code' => 'ACME',
        'name' => 'Acme Traders',
        'contact_name' => 'Rahim Uddin',
        'phone' => '01711000000',
        'email' => 'rahim@acme.test',
        'address' => '12 Adabor Road, Dhaka',
        'payment_terms' => 'Net 30',
        'bin' => '004123456-0101',
        'tin' => '123456789012',
        'bank_name' => 'BRAC Bank',
        'bank_branch' => 'Mohammadpur',
        'bank_account_name' => 'Acme Trading Company',
        'bank_account_number' => '1501203456789',
        'mfs_number' => '01711000000',
        'notes' => 'Delivers Tuesdays only. Ask for Rahim at the gate.',
    ];
});

it('creates a supplier through the screen with every field', function () {
    ($this->asRole)('Manager')
        ->post('/purchasing/suppliers', $this->full)
        ->assertRedirect();

    $supplier = Supplier::where('code', 'ACME')->firstOrFail();

    foreach ($this->full as $field => $value) {
        expect($supplier->{$field})->toBe($value, "field {$field} did not persist");
    }

    expect($supplier->is_active)->toBeTrue();
});

/**
 * ⚠⚠ THE STRUCTURAL GUARD, driven by the model rather than a hand-copied list.
 * Add a fillable field to Supplier and forget the controller, and this fails.
 * That is the whole point: the list cannot drift silently.
 */
it('lets the screen set every field the model can hold', function () {
    ($this->asRole)('Manager')->post('/purchasing/suppliers', $this->full);

    $supplier = Supplier::where('code', 'ACME')->firstOrFail();

    // business_id is set by the tenant scope, is_active by deactivation.
    $expected = collect((new Supplier)->getFillable())
        ->reject(fn ($f) => in_array($f, ['business_id', 'is_active'], true))
        ->values();

    expect($expected)->not->toBeEmpty();

    $unsettable = $expected->reject(fn ($f) => filled($supplier->{$f}))->values()->all();

    expect($unsettable)->toBe([], 'these fields cannot be set from the screen: '.implode(', ', $unsettable));
});

it('updates every field through the screen', function () {
    ($this->asRole)('Manager')->post('/purchasing/suppliers', $this->full);
    $supplier = Supplier::where('code', 'ACME')->firstOrFail();

    $changed = array_merge($this->full, [
        'name' => 'Acme Trading Co',
        'bank_account_number' => '9999999999999',
        'notes' => 'Now delivers Thursdays.',
    ]);

    ($this->asRole)('Manager')
        ->put("/purchasing/suppliers/{$supplier->id}", $changed)
        ->assertRedirect();

    $supplier->refresh();

    expect($supplier->name)->toBe('Acme Trading Co')
        ->and($supplier->bank_account_number)->toBe('9999999999999')
        ->and($supplier->notes)->toBe('Now delivers Thursdays.')
        // ⚠ The code is permanent: correcting a trading name must not create a second supplier.
        ->and($supplier->code)->toBe('ACME')
        ->and(Supplier::count())->toBe(1);
});

// ⚠ The `ignore` branch of the unique rule. Without it, saving a supplier
// without changing its code would report the supplier as a duplicate of itself.
it('lets a supplier keep its own code when edited', function () {
    ($this->asRole)('Manager')->post('/purchasing/suppliers', $this->full);
    $supplier = Supplier::where('code', 'ACME')->firstOrFail();

    ($this->asRole)('Manager')
        ->put("/purchasing/suppliers/{$supplier->id}", array_merge($this->full, ['phone' => '01822000000']))
        ->assertSessionHasNoErrors();

    expect($supplier->fresh()->phone)->toBe('01822000000');
});

describe('what the screen refuses', function () {
    it('requires a code', function () {
        ($this->asRole)('Manager')
            ->post('/purchasing/suppliers', array_merge($this->full, ['code' => '']))
            ->assertSessionHasErrors('code');

        expect(Supplier::count())->toBe(0);
    });

    it('requires a name', function () {
        ($this->asRole)('Manager')
            ->post('/purchasing/suppliers', array_merge($this->full, ['name' => '']))
            ->assertSessionHasErrors('name');
    });

    // ⚠⚠ Caught as a VALIDATION error, not a database exception. The person
    // typing gets told which field is wrong instead of a 500.
    it('refuses a duplicate code as a validation error', function () {
        ($this->asRole)('Manager')->post('/purchasing/suppliers', $this->full);

        ($this->asRole)('Manager')
            ->post('/purchasing/suppliers', array_merge($this->full, ['name' => 'Someone Else']))
            ->assertSessionHasErrors('code');

        expect(Supplier::count())->toBe(1);
    });

    it('refuses a malformed email', function () {
        ($this->asRole)('Manager')
            ->post('/purchasing/suppliers', array_merge($this->full, ['email' => 'not-an-email']))
            ->assertSessionHasErrors('email');
    });
});

describe('who may do what', function () {
    // ⚠ The supplier master carries bank details and tax identity, so editing
    // it stays with Owner and Manager - the same split Purchasing already uses.
    it('stops Staff from creating a supplier', function () {
        ($this->asRole)('Staff')
            ->post('/purchasing/suppliers', $this->full)
            ->assertForbidden();

        expect(Supplier::count())->toBe(0);
    });

    it('stops Staff from editing a supplier', function () {
        ($this->asRole)('Manager')->post('/purchasing/suppliers', $this->full);
        $supplier = Supplier::where('code', 'ACME')->firstOrFail();

        ($this->asRole)('Staff')
            ->put("/purchasing/suppliers/{$supplier->id}", array_merge($this->full, ['name' => 'Hijacked']))
            ->assertForbidden();

        expect($supplier->fresh()->name)->toBe('Acme Traders');
    });

    it('lets a Manager see the supplier list', function () {
        ($this->asRole)('Manager')->get('/purchasing/suppliers')->assertOk();
    });

    // ⚠⚠ Viewing the list is open to the whole business, but an account number
    // is not something everyone needs sitting in their page source.
    it('hides bank details and tax identity from someone who may not manage suppliers', function () {
        ($this->asRole)('Manager')->post('/purchasing/suppliers', $this->full);

        $response = ($this->asRole)('Staff')->get('/purchasing/suppliers')->assertOk();

        $row = $response->viewData('page')['props']['suppliers']['data'][0];

        expect($row)->toHaveKey('name')
            ->and($row)->toHaveKey('code')
            ->and($row)->not->toHaveKey('bank_account_number')
            ->and($row)->not->toHaveKey('bin')
            ->and($row)->not->toHaveKey('tin')
            ->and($row)->not->toHaveKey('notes');
    });

    it('sends the full record to someone who may manage suppliers', function () {
        ($this->asRole)('Manager')->post('/purchasing/suppliers', $this->full);

        $response = ($this->asRole)('Manager')->get('/purchasing/suppliers')->assertOk();

        $row = $response->viewData('page')['props']['suppliers']['data'][0];

        expect($row['bank_account_number'])->toBe('1501203456789')
            ->and($row['bin'])->toBe('004123456-0101');
    });
});
