<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Couriers\Actions\SaveCourierAccount;
use App\Modules\Sales\Couriers\Actions\TestCourierConnection;
use App\Modules\Sales\Couriers\Drivers\PathaoDriver;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use App\Modules\Sales\Couriers\Models\CourierApiCall;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * The read-only connection test against the real API shapes (carrybee-api-notes
 * and pathao-api-notes, verified 11 Oct 2026), the api-call log, the Pathao token
 * cache, and the credentials import command.
 */
const PATHAO_PASSWORD = 'merchant-PASSWORD-77aa';
const PATHAO_SECRET = 'pathao-client-SECRET-55bb';

beforeEach(function () {
    $this->business = Business::create(['name' => 'Bionic', 'slug' => 'bionic']);
    app(BusinessContext::class)->set($this->business->id);
    $this->owner = User::factory()->create();

    Http::preventStrayRequests();
});

function pathaoAccount(string $label = 'Bionic', string $environment = 'live'): CourierAccount
{
    return app(SaveCourierAccount::class)->handle(null, [
        'provider' => 'pathao',
        'label' => $label,
        'environment' => $environment,
        'base_url' => $environment === 'live' ? 'https://api-hermes.pathao.com' : 'https://courier-api-sandbox.pathao.com',
        'credentials' => ['client_id' => 'pathao-client-id', 'client_secret' => PATHAO_SECRET, 'username' => 'ops@bionic.garden', 'password' => PATHAO_PASSWORD],
    ]);
}

function cbAccount(): CourierAccount
{
    return app(SaveCourierAccount::class)->handle(null, [
        'provider' => 'carrybee',
        'label' => 'LaLaDia',
        'environment' => 'live',
        'base_url' => 'https://developers.carrybee.com',
        'credentials' => ['client_id' => 'cb-id', 'client_secret' => 'cb-SECRET-1234', 'client_context' => 'cb-CONTEXT-5678'],
    ]);
}

function fakePathao(string $base = 'https://api-hermes.pathao.com', int $tokenStatus = 200): void
{
    Http::fake([
        "{$base}/aladdin/api/v1/issue-token" => $tokenStatus === 200
            ? Http::response(['token_type' => 'Bearer', 'expires_in' => 432000, 'access_token' => 'TOKEN-abc', 'refresh_token' => 'R'])
            : Http::response(['message' => 'The user credentials were incorrect.'], $tokenStatus),
        "{$base}/aladdin/api/v1/stores" => Http::response(['code' => 200, 'type' => 'success', 'data' => ['data' => [
            ['store_id' => 393838, 'store_name' => 'BIONIC (Bor de Guna Office)', 'is_active' => 1],
        ], 'total' => 1]]),
        "{$base}/aladdin/api/v1/city-list" => Http::response(['code' => 200, 'data' => ['data' => [
            ['city_id' => 1, 'city_name' => 'Dhaka'], ['city_id' => 2, 'city_name' => 'Chittagong'],
        ]]]),
    ]);
}

it('tests a CarryBee account with the three headers and reads stores and cities', function () {
    Http::fake([
        'https://developers.carrybee.com/api/v2/stores' => Http::response(['error' => false, 'message' => 'ok', 'data' => ['stores' => [
            ['id' => '24869', 'name' => 'Adabor 2', 'is_active' => true],
        ]]]),
        'https://developers.carrybee.com/api/v2/cities' => Http::response(['error' => false, 'data' => ['cities' => [['id' => 14, 'name' => 'Dhaka']]]]),
    ]);

    $account = cbAccount();
    $result = app(TestCourierConnection::class)->handle($account);

    expect($result->ok)->toBeTrue()
        ->and($result->stores)->toBe([['id' => '24869', 'name' => 'Adabor 2', 'active' => true]])
        ->and($result->cityCount)->toBe(1);

    Http::assertSent(fn (Request $r) => $r->method() === 'GET'
        && $r->url() === 'https://developers.carrybee.com/api/v2/stores'
        && $r->header('Client-ID') === ['cb-id']
        && $r->header('Client-Secret') === ['cb-SECRET-1234']
        && $r->header('Client-Context') === ['cb-CONTEXT-5678']);
    Http::assertSentCount(2);

    expect($account->fresh()->testIsCurrent())->toBeTrue();
});

it('reports a CarryBee refusal and logs it without the secrets', function () {
    Http::fake([
        'https://developers.carrybee.com/api/v2/stores' => Http::response(['error' => true, 'message' => 'Invalid client cb-SECRET-1234'], 401),
    ]);

    $account = cbAccount();
    $result = app(TestCourierConnection::class)->handle($account);

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('Refused the credentials')
        ->and($result->message)->not->toContain('cb-SECRET-1234');

    $call = CourierApiCall::sole();
    expect($call->endpoint)->toBe('/api/v2/stores')
        ->and($call->http_status)->toBe(401)
        ->and($call->ok)->toBeFalse()
        ->and($call->error)->toContain('[hidden]');

    expect($account->fresh()->last_test_ok)->toBeFalse()->and($account->fresh()->canActivate())->toBeFalse();
});

it('treats a CarryBee 200 with error true as a failure', function () {
    Http::fake([
        'https://developers.carrybee.com/api/v2/stores' => Http::response(['error' => true, 'message' => 'Client context mismatch']),
    ]);

    expect(app(TestCourierConnection::class)->handle(cbAccount())->ok)->toBeFalse();
});

it('reports an unreachable courier as a failure', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

    $result = app(TestCourierConnection::class)->handle(cbAccount());

    expect($result->ok)->toBeFalse()->and($result->message)->toContain('Could not reach CarryBee');
    expect(CourierApiCall::sole()->http_status)->toBeNull();
});

it('tests a Pathao account: password grant, then stores and cities with the bearer token', function () {
    fakePathao();
    $account = pathaoAccount();

    $result = app(TestCourierConnection::class)->handle($account);

    expect($result->ok)->toBeTrue()
        ->and($result->stores)->toBe([['id' => '393838', 'name' => 'BIONIC (Bor de Guna Office)', 'active' => true]])
        ->and($result->cityCount)->toBe(2);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api-hermes.pathao.com/aladdin/api/v1/issue-token'
        && $r['grant_type'] === 'password' && $r['username'] === 'ops@bionic.garden' && $r['password'] === PATHAO_PASSWORD);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/aladdin/api/v1/stores') && $r->header('Authorization') === ['Bearer TOKEN-abc']);
});

it('caches the Pathao token per account, encrypted, and reuses it', function () {
    fakePathao();
    $account = pathaoAccount();

    app(TestCourierConnection::class)->handle($account);
    app(TestCourierConnection::class)->handle($account->fresh());

    $tokenCalls = collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/issue-token'));
    expect($tokenCalls)->toHaveCount(1);

    $cached = Cache::get(PathaoDriver::tokenCacheKey($account->fresh()));
    expect($cached)->toBeString()->not->toContain('TOKEN-abc');

    // A second account signs in for itself: no token is shared between accounts.
    $other = pathaoAccount('Second');
    app(TestCourierConnection::class)->handle($other);

    $tokenCalls = collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/issue-token'));
    expect($tokenCalls)->toHaveCount(2)
        ->and(PathaoDriver::tokenCacheKey($other))->not->toBe(PathaoDriver::tokenCacheKey($account->fresh()));
});

it('signs in again after the credentials change', function () {
    fakePathao();
    $account = pathaoAccount();
    app(TestCourierConnection::class)->handle($account);

    app(SaveCourierAccount::class)->handle($account->fresh(), [
        'label' => 'Bionic', 'environment' => 'live', 'base_url' => 'https://api-hermes.pathao.com',
        'credentials' => ['password' => 'a-new-password-1'],
    ]);
    app(TestCourierConnection::class)->handle($account->fresh());

    $tokenCalls = collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/issue-token'));
    expect($tokenCalls)->toHaveCount(2);
});

it('uses the sandbox URL for a sandbox account', function () {
    fakePathao('https://courier-api-sandbox.pathao.com');

    expect(app(TestCourierConnection::class)->handle(pathaoAccount('Sandbox', 'sandbox'))->ok)->toBeTrue();
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api-hermes'));
});

it('reports a Pathao sign-in refusal without the password', function () {
    fakePathao(tokenStatus: 401);

    $result = app(TestCourierConnection::class)->handle(pathaoAccount());

    expect($result->ok)->toBeFalse()->and($result->message)->toContain('Pathao refused to sign in');
    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/stores'));
});

it('never sends credentials to a URL outside the allow-list, even one written straight to the row', function () {
    $account = pathaoAccount();
    DB::table('courier_accounts')->where('id', $account->id)->update(['base_url' => 'https://evil.example.com']);

    $result = app(TestCourierConnection::class)->handle($account->fresh());

    expect($result->ok)->toBeFalse()->and($result->message)->toContain('not an official');
    Http::assertNothingSent();
});

it('logs every call with path, status and time but never a secret, header or body', function () {
    fakePathao();
    $account = pathaoAccount();
    app(TestCourierConnection::class)->handle($account);

    $calls = CourierApiCall::orderBy('id')->get();
    expect($calls->pluck('endpoint')->all())->toBe(['/aladdin/api/v1/issue-token', '/aladdin/api/v1/stores', '/aladdin/api/v1/city-list'])
        ->and($calls->pluck('http_status')->unique()->all())->toBe([200])
        ->and($calls->every(fn ($c) => $c->ok && $c->duration_ms >= 0))->toBeTrue();

    $dump = json_encode(DB::table('courier_api_calls')->get());
    expect($dump)->not->toContain(PATHAO_PASSWORD)->not->toContain(PATHAO_SECRET)->not->toContain('TOKEN-abc')->not->toContain('ops@bionic.garden');
});

it('imports credentials from a KEY=VALUE file as an inactive account, printing only masked values', function () {
    $path = tempnam(sys_get_temp_dir(), 'cb');
    file_put_contents($path, implode("\n", [
        '# CarryBee LaLaDia live',
        'CARRYBEE_LALADIA_LIVE_CLIENT_ID=cb-live-id-0001',
        'CARRYBEE_LALADIA_LIVE_CLIENT_SECRET="cb-live-SECRET-0002"',
        'CARRYBEE_LALADIA_LIVE_CLIENT_CONTEXT=cb-live-CONTEXT-0003',
        'CARRYBEE_LALADIA_SANDBOX_CLIENT_ID=sandbox-id',
    ]));

    $this->artisan('couriers:import-credentials', ['path' => $path, '--provider' => 'carrybee', '--environment' => 'live'])
        ->expectsOutputToContain('Created CarryBee account "Laladia"')
        ->expectsOutputToContain('******0002')
        ->doesntExpectOutputToContain('cb-live-SECRET-0002')
        ->doesntExpectOutputToContain('cb-live-CONTEXT-0003')
        ->assertSuccessful();

    $account = CourierAccount::sole();
    expect($account->is_active)->toBeFalse()
        ->and($account->environment)->toBe('live')
        ->and($account->base_url)->toBe('https://developers.carrybee.com')
        ->and($account->credential('client_secret'))->toBe('cb-live-SECRET-0002')
        ->and($account->credential('client_context'))->toBe('cb-live-CONTEXT-0003');

    // Run again with a changed secret: the same account is updated, still inactive.
    file_put_contents($path, "CARRYBEE_LALADIA_LIVE_CLIENT_ID=cb-live-id-0001\nCARRYBEE_LALADIA_LIVE_CLIENT_SECRET=rotated-SECRET-9999\nCARRYBEE_LALADIA_LIVE_CLIENT_CONTEXT=cb-live-CONTEXT-0003\n");
    $this->artisan('couriers:import-credentials', ['path' => $path, '--provider' => 'carrybee', '--label' => 'Laladia'])
        ->expectsOutputToContain('Updated')
        ->assertSuccessful();

    expect(CourierAccount::count())->toBe(1)->and(CourierAccount::sole()->credential('client_secret'))->toBe('rotated-SECRET-9999');

    @unlink($path);
});

it('imports Pathao credentials with an explicit prefix and email alias', function () {
    $path = tempnam(sys_get_temp_dir(), 'pa');
    file_put_contents($path, "PATHAO_BIONIC_CLIENT_ID=pid\nPATHAO_BIONIC_CLIENT_SECRET=psecret-1234\nPATHAO_BIONIC_EMAIL=ops@bionic.garden\nPATHAO_BIONIC_PASSWORD=ppass-5678\n");

    $this->artisan('couriers:import-credentials', ['path' => $path, '--provider' => 'pathao', '--prefix' => 'PATHAO_BIONIC_', '--label' => 'Bionic'])
        ->doesntExpectOutputToContain('ppass-5678')
        ->assertSuccessful();

    $account = CourierAccount::sole();
    expect($account->credential('username'))->toBe('ops@bionic.garden')
        ->and($account->hasAllRequiredCredentials())->toBeTrue()
        ->and($account->is_active)->toBeFalse();

    @unlink($path);
});

it('refuses an import whose file holds several key sets without a prefix', function () {
    $path = tempnam(sys_get_temp_dir(), 'cb');
    file_put_contents($path, "A_LIVE_CLIENT_ID=1\nB_LIVE_CLIENT_ID=2\n");

    $this->artisan('couriers:import-credentials', ['path' => $path, '--provider' => 'carrybee'])->assertFailed();
    expect(CourierAccount::count())->toBe(0);

    @unlink($path);
});
