<?php

use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\Contracts\Removable;
use App\Modules\Foundation\Corrections\Recycle\Recyclable;
use App\Modules\Foundation\Corrections\Recycle\RecycleBin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\CorrectionWorld;

/**
 * The Recycle bin (PHASE-0-CONTRACT v2, 1.5; FINAL-PLAN section 9, answer 1):
 * hidden forever, restored by the Owner only, never purged. No real model is
 * on the allow-list until Phase 4, so the test brings its own (a TEMPORARY
 * table: no implicit commit on MySQL inside the test's transaction).
 */
class BinThing extends Model implements Removable
{
    use Recyclable;

    protected $table = 'bin_things';

    protected $guarded = [];

    public function whyNotRemovable(): ?string
    {
        return $this->used ? 'Employee assignments use this department. Deactivate it instead.' : null;
    }

    public function whyNotDeactivatable(): ?string
    {
        return null;
    }

    public function binLabel(): string
    {
        return "Department {$this->name}";
    }
}

beforeEach(function () {
    $this->w = CorrectionWorld::make();

    Schema::create('bin_things', function (Blueprint $table) {
        $table->temporary();
        $table->id();
        $table->string('name');
        $table->boolean('used')->default(false);
        $table->unsignedBigInteger('deleted_by')->nullable();
        $table->text('delete_reason')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });

    config(['recycle_bin.models' => ['department' => BinThing::class]]);
});

afterEach(fn () => Carbon::setTestNow());

it('a never-used record goes to the bin with who and why; a used one is refused with its sentence', function () {
    $fresh = BinThing::create(['name' => 'Packing']);
    $used = BinThing::create(['name' => 'Sales', 'used' => true]);

    $fresh->recycle($this->w->financeB, new ActionReason(null, 'Created by mistake'));

    expect(BinThing::count())->toBe(1)
        ->and(BinThing::onlyTrashed()->first()->deleted_by)->toBe($this->w->financeB->id)
        ->and(BinThing::onlyTrashed()->first()->delete_reason)->toBe('Created by mistake');

    expect(fn () => $used->recycle($this->w->financeB, new ActionReason(null, 'Not needed')))
        ->toThrow(ValidationException::class);
});

it('only the Owner sees the bin and restores from it; there is no purge route', function () {
    $thing = BinThing::create(['name' => 'Packing']);
    $thing->recycle($this->w->financeB, new ActionReason(null, 'Created by mistake'));

    expect(app(RecycleBin::class)->entries($this->w->financeB))->toBeEmpty()
        ->and(app(RecycleBin::class)->entries($this->w->owner)->first()['label'])->toBe('Department Packing');

    $this->actingAs($this->w->financeB)->post("/settings/recycle-bin/department/{$thing->id}/restore", ['reason_text' => 'Needed again'])->assertForbidden();

    // Finance handed the permission by hand is still not the Owner.
    $this->w->financeB->givePermissionTo('recycle_bin.manage');
    $this->actingAs($this->w->financeB)->post("/settings/recycle-bin/department/{$thing->id}/restore", ['reason_text' => 'Needed again'])->assertForbidden();

    $this->actingAs($this->w->owner)->post("/settings/recycle-bin/department/{$thing->id}/restore", [])->assertSessionHasErrors('reason_text');
    $this->actingAs($this->w->owner)->post("/settings/recycle-bin/department/{$thing->id}/restore", ['reason_text' => 'Needed again'])->assertSessionHas('success');

    expect(BinThing::count())->toBe(1)->and(BinThing::first()->deleted_by)->toBeNull();

    $routes = collect(app('router')->getRoutes())->map(fn ($r) => $r->uri())->filter(fn ($uri) => str_contains($uri, 'recycle-bin'))->values()->all();
    expect($routes)->toBe(['settings/recycle-bin', 'settings/recycle-bin/{type}/{id}/restore']);
});
