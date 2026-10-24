<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Assets\Actions\ApplyAssetItemImport;
use App\Modules\Assets\Actions\MoveAssets;
use App\Modules\Assets\Actions\PlanAssetItemImport;
use App\Modules\Assets\Actions\SaveAssetItem;
use App\Modules\Assets\database\seeders\AssetCategorySeeder;
use App\Modules\Assets\database\seeders\AssetsPermissionsSeeder;
use App\Modules\Assets\Enums\AssetMovementKind;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\AssetImportBatch;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\AssetMovement;
use App\Modules\Assets\Models\AssetParty;
use App\Modules\Foundation\Models\Office;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Inventory\Models\Warehouse;

/**
 * ⭐ Corrections wave 3, B11 (24 Oct 2026): the things register on top of the
 * corrections world (CorrectionWorld: owner, finance, manager, sales, the
 * reason lists, the Tk 20,000 limit), with the asset roles, an office, a
 * warehouse, two employees, a repair shop and an IT Custodian.
 *
 *   custodian   IT Custodian: manages things, disposes, no money
 *   keeper      Asset Keeper: manages things, may NOT dispose
 */
final class ThingsWorld
{
    public CorrectionWorld $w;

    public User $custodian;

    public User $keeper;

    public Office $office;

    public Warehouse $warehouse;

    public Employee $rakib;

    public Employee $nazmul;

    public AssetParty $shop;

    public static function make(string $now = '2026-10-20 10:00:00'): self
    {
        return self::on(CorrectionWorld::make($now));
    }

    /** The things register inside a corrections world that already exists (the policy kit). */
    public static function on(CorrectionWorld $correctionWorld): self
    {
        $world = new self;
        $world->w = $correctionWorld;
        $business = $world->w->business;

        (new AssetsPermissionsSeeder)->run();
        (new AssetCategorySeeder)->run();
        $world->w->enter($business);

        // Owner and Manager get the register's permissions from the seeder by role name.
        $world->custodian = $world->w->member('IT Custodian', 'Moin');
        $world->keeper = $world->w->member('Asset Keeper', 'Keeper');

        $world->office = Office::create(['business_id' => $business->id, 'name' => 'Head office']);
        $world->warehouse = Warehouse::create(['business_id' => $business->id, 'name' => 'Savar Warehouse', 'code' => 'SAVAR']);
        $world->rakib = $world->employee('Rakib');
        $world->nazmul = $world->employee('Nazmul');
        $world->shop = AssetParty::create(['business_id' => $business->id, 'name' => 'Rahim', 'organisation' => 'Rahim Computer Care', 'kind' => 'repair_shop']);

        return $world;
    }

    public function employee(string $first): Employee
    {
        $employee = Employee::create([
            'employee_code' => 'BDG-'.str_pad((string) (Employee::query()->withoutGlobalScopes()->count() + 1), 6, '0', STR_PAD_LEFT),
            'first_name' => $first, 'last_name' => 'Test', 'employment_type' => 'permanent', 'status' => 'active',
            'joining_date' => '2026-01-01',
        ]);
        EmployeeAssignment::create([
            'business_id' => $this->w->business->id, 'employee_id' => $employee->id, 'office_id' => $this->office->id,
            'effective_from' => '2026-01-01', 'is_primary' => true,
        ]);

        return $employee;
    }

    public function thing(string $name = 'ThinkPad T470s', string $code = 'IT', array $overrides = []): AssetItem
    {
        return app(SaveAssetItem::class)->register(
            AssetCategory::query()->where('code', $code)->sole(),
            array_replace(['name' => $name, 'office_id' => $this->office->id, 'status' => 'in_store', 'condition' => 'good'], $overrides),
            $this->w->owner,
        )->first();
    }

    public function group(string $name = 'Steel scissors', int $pieces = 4): AssetItem
    {
        return $this->thing($name, 'SML', ['tracking' => 'quantity', 'quantity' => $pieces]);
    }

    public function move(AssetMovementKind $kind, array $lines, array $to, ?string $on = null): AssetMovement
    {
        return app(MoveAssets::class)->handle($kind, $lines, $to, $this->custodian, $on);
    }

    /**
     * Applies a things sheet through the real planner and applier.
     *
     * @param  list<array<string, string>>  $rows
     */
    public function import(array $rows): AssetImportBatch
    {
        $lines = [];

        foreach (array_values($rows) as $i => $values) {
            $lines[] = ['line' => $i + 2, 'values' => $values];
        }

        $plan = app(PlanAssetItemImport::class)->handle($lines, $this->w->owner);

        if ($plan->hasErrors()) {
            throw new \RuntimeException(json_encode($plan->errors));
        }

        $result = app(ApplyAssetItemImport::class)->handle($plan, $this->custodian);

        return AssetImportBatch::query()->where('number', $result['batch'])->sole();
    }
}
