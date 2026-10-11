<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Support\Attention\NeedsAttention;
use App\Support\FailedJobs;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Settings > Failed jobs (Owner, system.jobs.manage): read, retry, forget and
 * retry all, gated; nothing that looks like a secret ever reaches the browser.
 */
beforeEach(function () {
    $this->withoutVite();

    $this->business = Business::create(['name' => 'Jobs Co', 'slug' => 'jobs-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::findOrCreate('system.jobs.manage', 'web');
    Role::create(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])->givePermissionTo('system.jobs.manage');
    Role::create(['name' => 'Manager', 'business_id' => $this->business->id, 'guard_name' => 'web']);

    $this->owner = fjMember($this->business, 'Owner');
    $this->manager = fjMember($this->business, 'Manager');
});

function fjMember(Business $business, string $role): User
{
    $user = User::factory()->create();
    $user->businesses()->syncWithoutDetaching([$business->id]);
    setPermissionsTeamId($business->id);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function fjFail(string $exception = "RuntimeException: boom\n#0 /app/x.php(1)", string $job = 'App\\Jobs\\SyncCourierParcels'): string
{
    $uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid, 'connection' => 'database', 'queue' => 'default',
        'payload' => json_encode(['uuid' => $uuid, 'displayName' => $job, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => ['commandName' => $job]]),
        'exception' => $exception, 'failed_at' => now(),
    ]);

    return $uuid;
}

function fjAs(object $t, User $user)
{
    return $t->actingAs($user->fresh())->withSession(['current_business_id' => $t->business->id]);
}

it('opens only for whoever holds system.jobs.manage', function () {
    $uuid = fjFail();

    fjAs($this, $this->manager)->get('/settings/failed-jobs')->assertForbidden();
    fjAs($this, $this->manager)->post("/settings/failed-jobs/{$uuid}/retry")->assertForbidden();
    fjAs($this, $this->manager)->delete("/settings/failed-jobs/{$uuid}")->assertForbidden();
    fjAs($this, $this->manager)->post('/settings/failed-jobs/retry-all')->assertForbidden();
    expect(DB::table('failed_jobs')->count())->toBe(1);

    fjAs($this, $this->owner)->get('/settings/failed-jobs')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/failed-jobs')
            ->where('total', 1)
            ->where('jobs.0.job', 'App\\Jobs\\SyncCourierParcels')
            ->where('jobs.0.exception_class', 'RuntimeException'));
});

it('never sends a payload or anything shaped like a secret to the browser', function () {
    fjFail(implode("\n", [
        'Illuminate\\Http\\Client\\RequestException: HTTP request returned status code 401: {"access_token":"abc123SECRETtoken","error":"bad"}',
        'Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJlLXZhbHVl',
        'url https://api.example.com/v1?api_key=sk_live_51HxQ2eKz9&store=7 password=hunter2',
        'dsn mysql://erp:S3cretPass@db:3306/erp',
        'client secret 9f86d081884c7d659a2feaa0c55ad015a3bf4f1b',
    ]));

    $response = fjAs($this, $this->owner)->get('/settings/failed-jobs')->assertOk();
    $body = $response->getContent();

    foreach (['abc123SECRETtoken', 'eyJhbGciOiJIUzI1NiJ9', 'sk_live_51HxQ2eKz9', 'hunter2', 'S3cretPass', '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b', 'CallQueuedHandler'] as $secret) {
        expect($body)->not->toContain($secret);
    }

    expect($body)->toContain('[hidden]')
        // What a person needs survives: the class and where it went wrong.
        ->and($body)->toContain('RequestException')
        ->and(FailedJobs::scrub('App\\Modules\\Sales\\Couriers\\Drivers\\CarryBeeDriver::book failed on courier_settlement_alert_days'))
        ->toBe('App\\Modules\\Sales\\Couriers\\Drivers\\CarryBeeDriver::book failed on courier_settlement_alert_days');
});

it('retries one job onto its queue, forgets one, and retries all', function () {
    $first = fjFail();
    $second = fjFail();
    $third = fjFail();

    fjAs($this, $this->owner)->post("/settings/failed-jobs/{$first}/retry")->assertRedirect();
    expect(DB::table('failed_jobs')->where('uuid', $first)->exists())->toBeFalse()
        ->and(DB::table('jobs')->count())->toBe(1);

    fjAs($this, $this->owner)->delete("/settings/failed-jobs/{$second}")->assertRedirect();
    expect(DB::table('failed_jobs')->where('uuid', $second)->exists())->toBeFalse()
        ->and(DB::table('jobs')->count())->toBe(1);

    fjAs($this, $this->owner)->post('/settings/failed-jobs/retry-all')->assertRedirect();
    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(2)
        ->and(DB::table('activity_log')->where('log_name', 'system')->count())->toBe(3);

    fjAs($this, $this->owner)->post("/settings/failed-jobs/{$third}/retry")->assertNotFound();
});

it('puts failed jobs at the top of Needs attention for the Owner only, with Retry and Forget', function () {
    $uuid = fjFail();

    $owner = app(NeedsAttention::class)->for($this->owner, $this->business->id)['items'];
    $manager = app(NeedsAttention::class)->for($this->manager, $this->business->id)['items'];

    expect($owner)->toHaveCount(1)
        ->and($owner[0]->severity)->toBe('critical')
        ->and($owner[0]->params)->toMatchArray(['count' => 1, 'job' => 'SyncCourierParcels', 'error' => 'RuntimeException'])
        ->and(array_column($owner[0]->actions, 'url'))->toBe(["/settings/failed-jobs/{$uuid}/retry", "/settings/failed-jobs/{$uuid}"])
        ->and($manager)->toBe([]);
});
