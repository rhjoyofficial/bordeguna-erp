<?php

use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use Spatie\Permission\PermissionRegistrar;

/**
 * Entering a tenant means two things at once, and the whole point of
 * `TenantScope` is that nothing can do half of it.
 *
 * Being "in" a business is `BusinessContext` (row scoping) AND spatie's
 * permission team id (role resolution). Queued jobs used to set only the first,
 * which was a live ACL-02 bypass: with no team id `hasRole('Manager')` is
 * false, so `ReportScope::for()` returned `unrestricted()` and a Department
 * Manager's queued report rendered with no department scoping at all.
 */
it('sets both halves of the tenant on enter', function () {
    TenantScope::enter(7);

    expect(app(BusinessContext::class)->id())->toBe(7)
        ->and(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBe(7);
});

it('clears both halves on leave', function () {
    TenantScope::enter(7);
    TenantScope::leave();

    expect(app(BusinessContext::class)->id())->toBeNull()
        ->and(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBeNull();
});

/**
 * ⚠⚠ THE GUARD THAT ACTUALLY PREVENTS THE REGRESSION.
 *
 * The two tests above pass whether or not anybody uses `TenantScope`. This one
 * fails the moment a new job sets the business directly, which is exactly how
 * the original defect arrived: three jobs each restoring the tenant by hand,
 * each remembering one half of it.
 *
 * A comment asking people to remember is not a mechanism. This is.
 */
it('lets no queued job set the business without also setting the permission team', function () {
    $offenders = [];

    foreach (glob(app_path('Modules/*/Jobs/*.php')) as $file) {
        $source = (string) file_get_contents($file);

        $setsBusinessDirectly = preg_match('/\$context->set\(|BusinessContext::class\)->set\(/', $source) === 1;

        if ($setsBusinessDirectly && ! str_contains($source, 'TenantScope::enter')) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([], sprintf(
        'These jobs enter a business without its permission team id, which silently '
        .'unscopes every role check inside them: %s. Use TenantScope::enter().',
        implode(', ', $offenders),
    ));
});
