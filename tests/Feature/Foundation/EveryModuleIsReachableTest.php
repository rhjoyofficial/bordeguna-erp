<?php

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

/**
 * ⚠⚠ WHY THIS FILE EXISTS.
 *
 * The entire Sales module - the counter, customers, selling prices, invoices and
 * deliveries - was deployed to production and could not be reached. It was not in
 * the sidebar and not linked from any other screen, so the only way to open the
 * revenue side of the ERP was to type a URL. CRM shipped the same way hours after
 * it was built.
 *
 * Nothing caught it. Every route was registered, every controller was tested,
 * every screen rendered - and a user opening the application could not find any
 * of it. This is the `products.barcode` failure at MODULE scale: the thing works
 * perfectly and is unreachable.
 *
 * ⚠ This guard is deliberately FILE-BASED rather than clever. The navigation is a
 * React component, so the honest check is that each module's landing page appears
 * in the sidebar source. A test that parsed TSX would be a second implementation
 * of the bundler and would rot; a test that reads the file fails loudly the day
 * somebody ships a module without a way in.
 */
function sidebarSource(): string
{
    return file_get_contents(resource_path('js/components/app-sidebar.tsx'));
}

/**
 * Whether a route answers with something that is not a PAGE.
 *
 * ⚠ Decided by the controller's declared RETURN TYPE rather than by a list of
 * route names, so a new lookup endpoint or file download does not have to be
 * remembered here. A JSON body is not a page; neither is a CSV download, and
 * putting "Download blank template" in the sidebar would be absurd.
 */
function returnsJson($route): bool
{
    $action = $route->getActionName();

    if (! str_contains($action, '@')) {
        return false;
    }

    [$class, $method] = explode('@', $action);

    if (! class_exists($class) || ! method_exists($class, $method)) {
        return false;
    }

    $type = (string) ((new ReflectionMethod($class, $method))->getReturnType() ?? '');

    return str_contains($type, 'JsonResponse')
        || str_contains($type, 'StreamedResponse')
        || str_contains($type, 'BinaryFileResponse')
        // ⚠ A GET whose ONLY possible answer is a redirect is not a page either -
        // a payment gateway's return address, for one (SSLCommerz slice P2c).
        // EXACT type only: a page that sometimes redirects (`View|RedirectResponse`)
        // is still a page and still has to be reachable.
        || $type === RedirectResponse::class;
}

it('offers a way into every module that has user-facing pages', function (string $landingPage) {
    expect(sidebarSource())->toContain($landingPage);
})->with([
    // Every module's primary landing page. Adding a module means adding a line
    // here AND a sidebar entry - which is the point.
    'inventory' => ['/inventory/products'],
    'purchasing suppliers' => ['/purchasing/suppliers'],
    'purchasing orders' => ['/purchasing/purchase-orders'],
    'purchasing expenses' => ['/purchasing/expenses'],
    'sales counter' => ['/sales/counter'],
    'sales customers' => ['/sales/customers'],
    'sales prices' => ['/sales/prices'],
    'sales invoices' => ['/sales/invoices'],
    'sales deliveries' => ['/sales/deliveries'],
    'courier settings' => ['/settings/couriers'],
    'crm leads' => ['/crm/leads'],
    'crm follow-up queue' => ['/crm/queue'],
    'crm reports' => ['/crm/reports'],
    'crm settings' => ['/crm/settings'],
    'assets register' => ['/assets'],
    'assets depreciation' => ['/assets/depreciation'],
    'accounting' => ['/accounting/trial-balance'],
    'hrm employees' => ['/hrm/employees'],
]);

/**
 * ⚠ The broader sweep: any top-level GET page that is neither in the sidebar nor
 * linked from another screen is unreachable, and this names it.
 *
 * ⚠⚠ Sub-pages linked from a parent screen are legitimately absent from the
 * sidebar - `purchasing/payments/new` is reached from the payments list, and
 * putting every such page in the navigation would make the sidebar useless. So
 * this checks the sidebar OR any page component, which is exactly what "a user
 * can get there" means.
 */
it('leaves no user-facing page with no way to reach it', function () {
    $pages = collect(Route::getRoutes())
        ->filter(fn ($route) => in_array('GET', $route->methods(), true))
        // ⚠ A JSON endpoint is not a page and has no business in a sidebar.
        // Decided by the controller's RETURN TYPE rather than by a list of
        // names, so a new lookup endpoint does not have to be remembered here.
        ->reject(fn ($route) => returnsJson($route))
        // ⚠ `*.preview.back` is a redirect, not a page: the GET answer for an
        // import preview URL that only a POST renders (29 Sep 2026).
        ->reject(fn ($route) => str_ends_with((string) $route->getName(), '.preview.back'))
        ->reject(fn ($route) => str_contains($route->uri(), '{')
            || str_starts_with($route->uri(), '_')
            || in_array($route->uri(), ['/', 'dashboard', 'up'], true))
        ->filter(fn ($route) => str_contains($route->uri(), '/'))
        // ⚠ Keep the route NAME alongside the URI. Screens link with
        // `route('sales.invoices.create')` far more often than with a literal
        // path, and a guard that only knows about literals reports four
        // perfectly reachable pages as orphans - which is worse than useless,
        // because the next person turns the guard off.
        ->groupBy(fn ($route) => $route->uri())
        ->map(fn ($routes) => $routes->map(fn ($r) => $r->getName())->filter()->unique()->values()->all());

    expect($pages)->not->toBeEmpty();

    $sidebarSource = sidebarSource();

    // ⚠⚠ 2026-08-19. THIS GUARD USED TO PASS FOR A PAGE NOTHING LINKED TO.
    // Every Inertia page here declares a breadcrumb pointing at its own URL, so
    // lumping all page sources into one haystack let a page vouch for itself.
    // `inventory/opening-stock` - the cutover's one irreversible document, and
    // from 602d600 the home of the printable count sheet - sat outside the
    // sidebar for weeks with this test green, reachable only by typing the URL.
    //
    // The question is therefore not "is this URL mentioned anywhere" but "is it
    // mentioned by something OTHER than the page that serves it". The page
    // serving `a/b` lives under `js/pages/a/b/`, this codebase's own convention,
    // so that prefix is what gets excluded.
    // ⚠ Shared components count as "elsewhere" too, since 23 Aug 2026: the
    // self-service and shift/schedule reorganisations moved real navigation
    // out of the sidebar into tab components (SelfServiceTabs,
    // ShiftScheduleTabs), and a link rendered on every page of a section is
    // the opposite of unreachable. A component can never be the page that
    // serves a URL, so none of them needs the own-prefix exclusion below.
    $pageFiles = collect(glob(resource_path('js/pages/**/*.tsx'), GLOB_BRACE))
        ->merge(glob(resource_path('js/pages/**/**/*.tsx')))
        ->merge(glob(resource_path('js/pages/**/**/**/*.tsx')))
        ->merge(glob(resource_path('js/components/*.tsx')))
        ->unique()
        ->values();

    $linkedFromElsewhere = function (array $names, string $uri) use ($pageFiles, $sidebarSource): bool {
        $needles = collect($names)->map(fn (string $n) => "'".$n."'")->push('/'.$uri);

        if ($needles->contains(fn (string $needle) => str_contains($sidebarSource, $needle))) {
            return true;
        }

        $ownPrefix = str_replace(DIRECTORY_SEPARATOR, '/', resource_path('js/pages/'.$uri));

        return $pageFiles
            ->reject(fn (string $file) => str_starts_with(str_replace(DIRECTORY_SEPARATOR, '/', $file), $ownPrefix))
            ->contains(function (string $file) use ($needles) {
                $source = file_get_contents($file);

                return $needles->contains(fn (string $needle) => str_contains($source, $needle));
            });
    };

    $unreachable = $pages
        ->reject($linkedFromElsewhere)
        ->keys()
        // Auth and settings pages are reached from the user menu, not the sidebar.
        ->reject(fn (string $uri) => str_starts_with($uri, 'settings/')
            || str_starts_with($uri, 'login')
            || str_starts_with($uri, 'register')
            || str_starts_with($uri, 'forgot-password')
            || str_starts_with($uri, 'reset-password')
            || str_starts_with($uri, 'verify-email')
            || str_starts_with($uri, 'confirm-password')
            || str_starts_with($uri, 'businesses')
            // The socket's private-channel handshake (Laravel registers it for
            // GET and POST): Echo calls it, no person ever opens it.
            || $uri === 'broadcasting/auth')
        ->values()
        ->all();

    expect($unreachable)->toBe([], 'these pages exist but nothing links to them: '.implode(', ', $unreachable));
});
