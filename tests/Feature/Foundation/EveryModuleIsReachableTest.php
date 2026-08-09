<?php

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
        || str_contains($type, 'BinaryFileResponse');
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
    'crm leads' => ['/crm/leads'],
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
        ->map(fn ($route) => $route->uri())
        ->reject(fn (string $uri) => str_contains($uri, '{')
            || str_starts_with($uri, '_')
            || in_array($uri, ['/', 'dashboard', 'up'], true))
        ->filter(fn (string $uri) => str_contains($uri, '/'))
        ->unique()
        ->values();

    expect($pages)->not->toBeEmpty();

    $sidebarSource = sidebarSource();
    $pageSources = collect(glob(resource_path('js/pages/**/*.tsx'), GLOB_BRACE))
        ->merge(glob(resource_path('js/pages/**/**/*.tsx')))
        ->merge(glob(resource_path('js/pages/**/**/**/*.tsx')))
        ->map(fn (string $file) => file_get_contents($file))
        ->implode("\n");

    $haystack = $sidebarSource."\n".$pageSources;

    $unreachable = $pages
        ->reject(fn (string $uri) => str_contains($haystack, '/'.$uri))
        // Auth and settings pages are reached from the user menu, not the sidebar.
        ->reject(fn (string $uri) => str_starts_with($uri, 'settings/')
            || str_starts_with($uri, 'login')
            || str_starts_with($uri, 'register')
            || str_starts_with($uri, 'forgot-password')
            || str_starts_with($uri, 'reset-password')
            || str_starts_with($uri, 'verify-email')
            || str_starts_with($uri, 'confirm-password')
            || str_starts_with($uri, 'businesses'))
        ->values()
        ->all();

    expect($unreachable)->toBe([], 'these pages exist but nothing links to them: '.implode(', ', $unreachable));
});
