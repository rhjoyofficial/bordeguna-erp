<?php

use App\Modules\Foundation\Support\SeedCredentials;

/**
 * ⚠⚠ Five accounts with the password "password" were found live on the public
 * production system on 2026-07-29, including one holding is_super_admin.
 *
 * Nobody had to make a mistake for that to happen: `db:seed` is part of
 * provisioning, and the seeders that create demo logins are the same ones that
 * create roles, permissions and salary components. These tests exist so the
 * mechanism that closed it cannot be quietly undone.
 */
it('allows a known seed password only in local and testing', function () {
    expect(SeedCredentials::allowKnownPassword())->toBeTrue();
});

it('refuses a known seed password in production', function () {
    app()['env'] = 'production';

    expect(SeedCredentials::allowKnownPassword())->toBeFalse();
    expect(SeedCredentials::demoPassword())->not->toBe('password');
    expect(SeedCredentials::demoActive())->toBeFalse();
});

it('refuses a known seed password in any environment it was not told about', function () {
    // ⚠ The allowlist is the point. A new environment name — staging, demo, uat
    // — must be safe by default rather than dangerous by default, so that
    // getting this wrong means "I cannot log in to staging" and never "anyone
    // can log in to staging".
    foreach (['staging', 'demo', 'uat', 'production'] as $environment) {
        app()['env'] = $environment;

        expect(SeedCredentials::allowKnownPassword())->toBeFalse()
            ->and(SeedCredentials::demoActive())->toBeFalse();
    }
});

it('generates a different random password every time outside local', function () {
    app()['env'] = 'production';

    // Generated, hashed and discarded: there is no value to leak because none
    // is retained anywhere.
    expect(SeedCredentials::demoPassword())->not->toBe(SeedCredentials::demoPassword());
    expect(strlen(SeedCredentials::demoPassword()))->toBeGreaterThanOrEqual(32);
});
