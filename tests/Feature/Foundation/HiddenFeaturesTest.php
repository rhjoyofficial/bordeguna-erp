<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\Ai\AssistantTools;
use App\Modules\Foundation\Support\Ai\GuideBook;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Reporting\ReportRegistry;
use App\Support\Features;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Joy's decision (24 Oct 2026): nine features are REMOVED from sight - Chat,
 * CRM Tickets, Recruitment, Budgets, Cash forecast, Payment reminders, Notes,
 * Calendar and Org chart. Hidden, never deleted: one switch each in
 * config/features.php brings a feature back.
 *
 * Proven here, for every feature: off, each of its routes answers 404 to
 * somebody who holds its permission, and every menu flag, search group, guide
 * article, assistant tool and report that leads to it is gone; on, its page
 * opens again. A route added later under a hidden address without the gate
 * fails the registry test below.
 */
const HIDDEN_PERMISSIONS = [
    'calendar.view', 'calendar.manage', 'chat.use', 'notes.view', 'notes.manage', 'recruitment.view',
    'recruitment.manage', 'crm.leads.view', 'crm.leads.manage', 'accounting.view',
    'foundation.ai.use', 'foundation.ai.notes',
];

/** feature => [route name prefixes, the page that opens when it is on] */
const HIDDEN_FEATURES = [
    Features::CHAT => [['chat.'], '/chat'],
    Features::CRM_TICKETS => [['crm.tickets'], '/crm/tickets'],
    Features::RECRUITMENT => [['hrm.recruitment.'], '/hrm/recruitment'],
    Features::BUDGETS => [['accounting.budgets'], '/accounting/budgets'],
    Features::CASH_FORECAST => [['accounting.cash-forecast'], '/accounting/cash-forecast'],
    Features::DUNNING => [['accounting.dunning'], '/accounting/dunning'],
    Features::NOTES => [['notes.'], '/notes'],
    Features::CALENDAR => [['calendar.'], '/calendar'],
    Features::ORG_CHART => [['hrm.org-chart'], '/hrm/org-chart'],
];

beforeEach(function () {
    $this->business = Business::create(['name' => 'Hidden Co', 'slug' => 'hidden-co', 'fiscal_year_start_month' => 7]);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    foreach (HIDDEN_PERMISSIONS as $name) {
        Permission::findOrCreate($name, 'web');
    }
    $role = Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $role->givePermissionTo(HIDDEN_PERMISSIONS);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole($role);

    $this->as = fn () => $this->actingAs($this->owner)->withSession(['current_business_id' => $this->business->id]);
});

/** @return list<RoutingRoute> every route of one feature, by name */
function hiddenRoutesOf(string $feature): array
{
    [$prefixes] = HIDDEN_FEATURES[$feature];

    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route) => collect($prefixes)->contains(fn ($prefix) => str_starts_with((string) $route->getName(), $prefix)))
        ->values()
        ->all();
}

it('keeps all nine switches, every one off unless config says otherwise', function () {
    expect(Features::ALL)->toHaveCount(9)
        ->and(array_keys(HIDDEN_FEATURES))->toEqualCanonicalizing(Features::ALL);

    foreach (Features::ALL as $feature) {
        expect(config('features.'.$feature))->toBeFalse("{$feature} should ship switched off");
    }
});

it('puts the feature gate on every route of a hidden feature', function (string $feature) {
    $routes = hiddenRoutesOf($feature);

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        expect(in_array('feature:'.$feature, $route->gatherMiddleware(), true))->toBeTrue("{$route->uri()} has no feature gate");
    }
})->with(array_keys(HIDDEN_FEATURES));

it('answers 404 on every route of a hidden feature, to somebody holding its permission', function (string $feature) {
    config(['features.'.$feature => false]);

    foreach (hiddenRoutesOf($feature) as $route) {
        $uri = '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            ($this->as)()->call($method, $uri)->assertNotFound();
        }
    }
})->with(array_keys(HIDDEN_FEATURES));

it('opens the page again once its switch is on', function (string $feature) {
    config(['features.'.$feature => true]);
    [, $page] = HIDDEN_FEATURES[$feature];

    ($this->as)()->get($page)->assertOk();
})->with(array_keys(HIDDEN_FEATURES));

it('shares no menu flag for a hidden feature, and every one once it is on', function () {
    $flags = ['canUseChat', 'canViewCalendar', 'canViewNotes', 'canViewRecruitment', 'canViewOrgChart', 'canViewCrmTickets', 'canViewBudgets', 'canViewCashForecast', 'canViewDunning'];

    $props = fn () => ($this->as)()->get('/work')->assertOk()->viewData('page')['props'];

    $off = $props();
    foreach ($flags as $flag) {
        expect($off[$flag] ?? false)->toBeFalse("{$flag} with the feature off");
    }
    expect($off['crmTicketsOverdueCount'] ?? null)->toBeNull();

    config(collect(Features::ALL)->mapWithKeys(fn ($feature) => ['features.'.$feature => true])->all());
    $on = $props();
    foreach ($flags as $flag) {
        expect($on[$flag])->toBeTrue("{$flag} with the feature on");
    }
});

it('leaves the My work to-do card without a link to the hidden notes page', function () {
    ($this->as)()->get('/work')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('my-work')->where('canViewNotes', false));
});

it('finds no ticket in the header search while Tickets are hidden', function () {
    DB::table('crm_tickets')->insert([
        'business_id' => $this->business->id, 'ticket_number' => 'TK-HIDE-1', 'caller_name' => 'Karim',
        'subject' => 'Hidden complaint', 'category' => 'quality', 'priority' => 'high', 'status' => 'open',
        'resolve_by' => now()->addDay(), 'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $groups = fn () => collect(($this->as)()->getJson('/search?q=TK-HIDE')->assertOk()->json('groups'))->pluck('title')->all();

    expect($groups())->not->toContain('Tickets');

    config(['features.crm_tickets' => true]);
    expect($groups())->toContain('Tickets');
});

it('drops the guide articles, the note tools and the budget report of hidden features', function () {
    $keys = fn () => collect(app(GuideBook::class)->articles())->pluck('key')->all();
    $tools = fn () => collect(app(AssistantTools::class)->definitions($this->owner))->pluck('name')->all();
    $runnable = fn () => app(ReportRegistry::class)->availableTo($this->owner)->keys()->all();

    expect($keys())->not->toContain('crm.tickets', 'self.orgchart', 'accounting.cash_forecast', 'accounting.dunning', 'accounting.budgets')
        ->and($tools())->not->toContain('list_notes', 'create_note')
        ->and($runnable())->not->toContain('accounting_budget_vs_actual');

    $refused = json_decode(app(AssistantTools::class)->run($this->owner, 'list_notes', []), true);
    expect($refused)->toHaveKey('refused');

    config(collect(Features::ALL)->mapWithKeys(fn ($feature) => ['features.'.$feature => true])->all());

    expect($keys())->toContain('crm.tickets', 'self.orgchart', 'accounting.cash_forecast', 'accounting.dunning', 'accounting.budgets')
        ->and($tools())->toContain('list_notes', 'create_note')
        ->and($runnable())->toContain('accounting_budget_vs_actual');
});
