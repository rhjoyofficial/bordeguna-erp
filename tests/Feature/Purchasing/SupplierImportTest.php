<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Support\SupplierCsv;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Suppliers in and out of a spreadsheet.
 *
 * ⚠⚠ The importer's primary product is the PLAN, not the write. Joy's rule for
 * the whole onboarding exercise is that a file is reviewed before anything is
 * generated, so most of what is tested here is what the tool REFUSES and what
 * it reports, not what it creates.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Import Co', 'slug' => 'import-co', 'fiscal_year_start_month' => 7]);
    $this->other = Business::create(['name' => 'Rival Co', 'slug' => 'rival-import-co', 'fiscal_year_start_month' => 7]);

    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new PurchasingPermissionsSeeder)->run();

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user);
    };

    $this->csv = function (string $body): string {
        $path = tempnam(sys_get_temp_dir(), 'sup').'.csv';
        file_put_contents($path, "code,name,contact_name,phone,is_active\n".$body);

        return $path;
    };
});

// ── The real file, which is the point of the exercise ───────────────────────

it('imports the three real Bor De Guna suppliers', function () {
    $path = tempnam(sys_get_temp_dir(), 'sup').'.csv';
    file_put_contents($path, <<<'CSV'
    code,name,contact_name,phone,email,address,payment_terms,bin,tin,bank_name,bank_branch,bank_account_name,bank_account_number,mfs_number,notes,is_active
    AYESHA,Ayesha Siddika,,01778838393,,,,,,,,,,,,active
    ADDEEN,Ad-Deen Food,Mosharof Jamil,01971836736,,,,,,,,,,,,active
    PREMI,Premi Farm,Md Samsuzzaman,01742001747,,,,,,,,,,,,active
    CSV);

    $this->artisan('suppliers:import', ['file' => $path, '--business' => 'import-co', '--apply' => true])
        ->expectsOutputToContain('3 to create')
        ->expectsOutputToContain('Imported: 3 created, 0 updated.')
        ->assertSuccessful();

    TenantScope::enter($this->business->id);

    expect(Supplier::count())->toBe(3);

    // ⚠ Ayesha Siddika is an INDIVIDUAL: her own name is the supplier name and
    // contact_name is deliberately blank. A file that demanded a contact could
    // not express her at all.
    $ayesha = Supplier::where('code', 'AYESHA')->sole();
    expect($ayesha->name)->toBe('Ayesha Siddika')
        ->and($ayesha->contact_name)->toBeNull()
        ->and($ayesha->phone)->toBe('01778838393')
        ->and($ayesha->is_active)->toBeTrue();

    expect(Supplier::where('code', 'ADDEEN')->sole()->contact_name)->toBe('Mosharof Jamil');
});

// ── Review is the default ───────────────────────────────────────────────────

it('writes nothing without --apply', function () {
    $path = ($this->csv)("ADDEEN,Ad-Deen Food,Mosharof Jamil,01971836736,active\n");

    $this->artisan('suppliers:import', ['file' => $path, '--business' => 'import-co'])
        ->expectsOutputToContain('1 to create')
        ->expectsOutputToContain('Review only. Nothing has been written.')
        ->assertSuccessful();

    TenantScope::enter($this->business->id);
    expect(Supplier::count())->toBe(0);
});

it('prints the database name before doing anything', function () {
    // ⚠⚠ The production container has a cached config, so `docker exec
    // -e DB_DATABASE=` is SILENTLY IGNORED and a command believed to be running
    // on a scratch copy runs against the live database. This line is the check,
    // so it is tested rather than trusted.
    $path = ($this->csv)("ADDEEN,Ad-Deen Food,,01971836736,active\n");

    $this->artisan('suppliers:import', ['file' => $path, '--business' => 'import-co'])
        ->expectsOutputToContain('Database:')
        ->assertSuccessful();
});

// ── What it refuses ─────────────────────────────────────────────────────────

it('refuses a file whose columns it does not understand', function (string $header, string $needle) {
    $path = tempnam(sys_get_temp_dir(), 'sup').'.csv';
    file_put_contents($path, $header."\nX,Y\n");

    $this->artisan('suppliers:import', ['file' => $path, '--business' => 'import-co'])
        ->expectsOutputToContain($needle)
        ->assertFailed();
})->with([
    // ⚠⚠ An unknown column is an ERROR, not a warning. A file headed
    // `phone_number` imports perfectly and silently drops every phone number,
    // and nothing downstream can ever tell that it did.
    'unknown column' => ['code,name,phone_number', 'unrecognised column'],
    'missing mandatory' => ['name,phone', 'missing mandatory column'],
    'repeated column' => ['code,name,code', 'repeats column'],
]);

it('refuses rows that cannot identify a supplier', function (string $row, string $needle) {
    $path = ($this->csv)($row);

    $this->artisan('suppliers:import', ['file' => $path, '--business' => 'import-co', '--apply' => true])
        ->expectsOutputToContain($needle)
        ->assertFailed();

    TenantScope::enter($this->business->id);
    expect(Supplier::count())->toBe(0);
})->with([
    'no code' => [",Ad-Deen Food,,01971836736,active\n", 'needs a code'],
    'no name' => ["ADDEEN,,,01971836736,active\n", 'needs a name'],
    'bad code' => ["AD DEEN,Ad-Deen Food,,01971836736,active\n", 'only letters, digits and hyphens'],
    'unreadable is_active' => ["ADDEEN,Ad-Deen Food,,01971836736,maybe\n", 'must be "active" or "inactive"'],
]);

it('refuses the same code twice in one file', function () {
    // ⚠ A code identifies exactly one supplier. Two rows sharing one would
    // otherwise create the first and silently update it with the second.
    $path = ($this->csv)("ADDEEN,Ad-Deen Food,,01971836736,active\nADDEEN,Ad-Deen Trading,,01971836737,active\n");

    $this->artisan('suppliers:import', ['file' => $path, '--business' => 'import-co', '--apply' => true])
        ->expectsOutputToContain('appears on line 2 as well')
        ->assertFailed();

    TenantScope::enter($this->business->id);
    expect(Supplier::count())->toBe(0);
});

it('treats a code as the same supplier whatever its case', function () {
    // ⚠⚠ The STORED code is lowercase here, deliberately. An earlier version of
    // this test stored `ADDEEN` and imported `addeen`, which passed even when
    // the lookup key was left un-uppercased - because the stored side was
    // already uppercase, so the guard was never actually exercised. Found by
    // deleting the strtoupper and watching nothing fail.
    Supplier::create(['business_id' => $this->business->id, 'code' => 'addeen', 'name' => 'Ad-Deen Food']);

    // ⚠ Creating a second row for `ADDEEN` would split one company's purchase
    // history in two, and nothing downstream would ever report it.
    $path = ($this->csv)("ADDEEN,Ad-Deen Food Ltd,,01971836736,active\n");

    $this->artisan('suppliers:import', ['file' => $path, '--business' => 'import-co', '--apply' => true])
        ->expectsOutputToContain('1 to update')
        ->assertSuccessful();

    TenantScope::enter($this->business->id);
    expect(Supplier::count())->toBe(1)
        ->and(Supplier::sole()->name)->toBe('Ad-Deen Food Ltd')
        // ⚠ The stored code is never rewritten by an import: it is the identity
        // that matched, and every purchase order behind it refers to that row.
        ->and(Supplier::sole()->code)->toBe('addeen');
});

// ── What it warns about but does not change ─────────────────────────────────

it('warns about a phone missing its leading zero and imports it verbatim', function () {
    // ⚠⚠ WARNS, NEVER CORRECTS. Joy decided on 9 August that no phone
    // normalisation would be built and the wrong numbers would be fixed by
    // hand. Silently rewriting the file would be that feature arriving through
    // the back door.
    $path = ($this->csv)("AYESHA,Ayesha Siddika,,1778838393,active\n");

    $this->artisan('suppliers:import', ['file' => $path, '--business' => 'import-co', '--apply' => true])
        ->expectsOutputToContain('missing its leading zero')
        ->assertSuccessful();

    TenantScope::enter($this->business->id);
    expect(Supplier::sole()->phone)->toBe('1778838393');
});

// ── What it leaves alone ────────────────────────────────────────────────────

it('never touches a supplier the file does not mention', function () {
    Supplier::create(['business_id' => $this->business->id, 'code' => 'OLDSUP', 'name' => 'Long-standing Supplier']);

    $path = ($this->csv)("ADDEEN,Ad-Deen Food,,01971836736,active\n");

    $this->artisan('suppliers:import', ['file' => $path, '--business' => 'import-co', '--apply' => true])
        ->expectsOutputToContain('left untouched: OLDSUP')
        ->assertSuccessful();

    TenantScope::enter($this->business->id);

    // ⚠⚠ A supplier with purchase orders behind it must never disappear or be
    // deactivated because somebody imported a shorter spreadsheet.
    $old = Supplier::where('code', 'OLDSUP')->sole();
    expect($old->is_active)->toBeTrue()
        ->and($old->name)->toBe('Long-standing Supplier');
});

it('treats a blank cell as nothing to say, not as an instruction to erase', function () {
    Supplier::create([
        'business_id' => $this->business->id, 'code' => 'ADDEEN', 'name' => 'Ad-Deen Food',
        'bank_account_number' => '1234567890', 'contact_name' => 'Mosharof Jamil',
    ]);

    // ⚠⚠ Otherwise exporting, clearing a column by accident in a spreadsheet,
    // and re-importing would silently wipe every bank account in the business.
    $path = ($this->csv)("ADDEEN,Ad-Deen Food,,01971836736,active\n");

    $this->artisan('suppliers:import', ['file' => $path, '--business' => 'import-co', '--apply' => true])
        ->assertSuccessful();

    TenantScope::enter($this->business->id);
    $supplier = Supplier::sole();

    expect($supplier->bank_account_number)->toBe('1234567890')
        ->and($supplier->contact_name)->toBe('Mosharof Jamil')
        ->and($supplier->phone)->toBe('01971836736');
});

it('imports into the named business and no other', function () {
    $path = ($this->csv)("ADDEEN,Ad-Deen Food,,01971836736,active\n");

    $this->artisan('suppliers:import', ['file' => $path, '--business' => 'import-co', '--apply' => true])
        ->assertSuccessful();

    // ⚠⚠ A console command carries no BusinessContext. Without entering the
    // tenant, BusinessScope compiles `1 = 0`: the import would read an empty
    // list and write into no business at all.
    TenantScope::enter($this->other->id);
    expect(Supplier::count())->toBe(0);

    TenantScope::enter($this->business->id);
    expect(Supplier::count())->toBe(1);
});

it('is idempotent', function () {
    $path = ($this->csv)("ADDEEN,Ad-Deen Food,Mosharof Jamil,01971836736,active\n");

    $this->artisan('suppliers:import', ['file' => $path, '--business' => 'import-co', '--apply' => true])->assertSuccessful();
    $this->artisan('suppliers:import', ['file' => $path, '--business' => 'import-co', '--apply' => true])
        ->expectsOutputToContain('already matches the file')
        ->assertSuccessful();

    TenantScope::enter($this->business->id);
    expect(Supplier::count())->toBe(1);
});

// ── Export and template ─────────────────────────────────────────────────────

it('exports a file the importer accepts back unchanged', function () {
    Supplier::create([
        'business_id' => $this->business->id, 'code' => 'ADDEEN', 'name' => 'Ad-Deen Food',
        'contact_name' => 'Mosharof Jamil', 'phone' => '01971836736', 'is_active' => false,
    ]);

    $path = tempnam(sys_get_temp_dir(), 'exp').'.csv';
    $this->artisan('suppliers:export', ['file' => $path, '--business' => 'import-co'])->assertSuccessful();

    // ⚠⚠ THE ROUND TRIP IS THE POINT. Export, edit in a spreadsheet, import
    // back. An export the importer rejects is not an export - and the BOM the
    // export writes for Excel is exactly the kind of thing that breaks it.
    $this->artisan('suppliers:import', ['file' => $path, '--business' => 'import-co', '--apply' => true])
        ->expectsOutputToContain('already matches the file')
        ->assertSuccessful();

    TenantScope::enter($this->business->id);
    expect(Supplier::count())->toBe(1)
        ->and(Supplier::sole()->is_active)->toBeFalse();
});

it('writes a blank template with no example row in it', function () {
    $path = tempnam(sys_get_temp_dir(), 'tpl').'.csv';
    $this->artisan('suppliers:export', ['file' => $path, '--template' => true])->assertSuccessful();

    $contents = file_get_contents($path);

    // ⚠⚠ A template carrying a sample supplier is a template somebody imports
    // unchanged, and then a fictional company sits in the purchase ledger for
    // ever. The guidance is printed by the command instead.
    expect(substr_count(trim($contents), "\n"))->toBe(0)
        ->and($contents)->toContain('code')
        ->and($contents)->toContain('bank_account_number');

    $this->artisan('suppliers:import', ['file' => $path, '--business' => 'import-co'])
        ->expectsOutputToContain('valid header and no rows')
        ->assertSuccessful();
});

// ── Through the screen ──────────────────────────────────────────────────────

it('reviews an uploaded file without writing, then writes only when confirmed', function () {
    $file = UploadedFile::fake()->createWithContent(
        'suppliers.csv',
        "code,name,contact_name,phone,is_active\nADDEEN,Ad-Deen Food,Mosharof Jamil,01971836736,active\n"
    );

    $this->actingAs($this->owner)
        ->post('/purchasing/suppliers/import/review', ['file' => $file])
        ->assertRedirect()
        ->assertSessionHas('supplierImportPlan');

    // ⚠ Review must write NOTHING. The whole design rests on this.
    TenantScope::enter($this->business->id);
    expect(Supplier::count())->toBe(0);

    $this->actingAs($this->owner)
        ->post('/purchasing/suppliers/import/apply')
        ->assertRedirect()
        ->assertSessionHas('success');

    TenantScope::enter($this->business->id);
    expect(Supplier::count())->toBe(1)
        ->and(Supplier::sole()->code)->toBe('ADDEEN');
});

it('refuses to apply when nothing has been reviewed', function () {
    // ⚠ Otherwise a stray POST - a double submit, a stale tab - would import
    // whatever happened to be left in the session.
    $this->actingAs($this->owner)
        ->post('/purchasing/suppliers/import/apply')
        ->assertSessionHasErrors('file');

    TenantScope::enter($this->business->id);
    expect(Supplier::count())->toBe(0);
});

it('downloads the template and the export only for someone who may manage suppliers', function () {
    // ⚠⚠ An export hands over every bank account number, BIN and TIN in one
    // file. The LIST screen deliberately hides those columns from everyone but
    // a manager, so the file has to follow the same rule or the hiding is
    // theatre.
    $this->actingAs($this->owner)->get('/purchasing/suppliers/template')->assertOk();
    $this->actingAs($this->owner)->get('/purchasing/suppliers/export')->assertOk();

    ($this->asRole)('Staff')->get('/purchasing/suppliers/template')->assertForbidden();
    ($this->asRole)('Staff')->get('/purchasing/suppliers/export')->assertForbidden();
    ($this->asRole)('Staff')->post('/purchasing/suppliers/import/review')->assertForbidden();

    // Staff may still READ the list, as before.
    ($this->asRole)('Staff')->get('/purchasing/suppliers')->assertOk();
});

it('keeps one definition of the columns for template, export and import', function () {
    // ⚠⚠ If the template and the reader disagreed by a single column name, the
    // file this system hands you is a file it then refuses to accept - and the
    // person holding it cannot tell which of the two is wrong.
    $template = SupplierCsv::template();
    $header = str_getcsv(trim(preg_replace('/^\x{FEFF}/u', '', $template)));

    expect($header)->toBe(SupplierCsv::columns())
        ->and(SupplierCsv::columns())->toContain('code', 'name', 'is_active');

    // Every column the file accepts must be a real, writable Supplier field.
    $fillable = (new Supplier)->getFillable();

    foreach (SupplierCsv::columns() as $column) {
        expect($fillable)->toContain($column);
    }
});
