<?php

namespace App\Modules\Foundation\Providers;

use App\Models\User;
use App\Modules\Foundation\Console\Commands\CutoverPreflight;
use App\Modules\Foundation\Console\Commands\SendMorningBriefing;
use App\Modules\Foundation\Console\Commands\TeardownSandbox;
use App\Modules\Foundation\Corrections\Contracts\CorrectionDatePolicy;
use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use App\Modules\Foundation\Corrections\OriginalDatePolicy;
use App\Modules\Foundation\Support\Ai\ReadOnlyConnection;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\DatabaseTargetGuard;
use App\Modules\Foundation\Support\HistoryOnlyTypes;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class FoundationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BusinessContext::class);

        // Edit, delete and correct, Phase 0 (17 Oct 2026): one registry of the
        // document types that offer correction actions, and the date rule
        // "the correction date is the original date, everywhere".
        $this->app->singleton(DocumentPolicyRegistry::class);
        // ⭐ 24 Oct 2026 (wave 1 gaps): records with a History tab only.
        $this->app->singleton(HistoryOnlyTypes::class);
        $this->app->bind(CorrectionDatePolicy::class, OriginalDatePolicy::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // ⚠ The data assistant's read-only connection, derived from the app's
        // own so it follows whatever driver is in use, with the SELECT-only
        // credentials swapped in when they are configured.
        ReadOnlyConnection::register();

        if ($this->app->runningInConsole()) {
            $this->commands([TeardownSandbox::class, CutoverPreflight::class, SendMorningBriefing::class]);

            // ⚠⚠ Fires before the first migration in a run. Catches the case
            // where DB_DATABASE was overridden per-command but the container's
            // cached config ignored it, which put a migration on production on
            // 2026-08-23. See DatabaseTargetGuard.
            //
            // ⚠ NOT registered under `testing`, and that exemption is load
            // bearing rather than convenient: `--parallel` gives every process
            // its own database (testing_1, testing_2, ...) while DB_DATABASE
            // still names the base one, so the mismatch this guard exists to
            // catch is the NORMAL state there. Registering it anyway failed
            // dozens of unrelated tests with a QueryException. The assertion
            // itself stays free of environment checks so it remains directly
            // testable, which is how DatabaseTargetGuardTest proves it screams.
            if (! $this->app->environment('testing')) {
                Event::listen(MigrationsStarted::class, function (): void {
                    DatabaseTargetGuard::assertConnectedToIntendedDatabase();
                });
            }
        }
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // A Super Admin bypasses every permission check across every business.
        // Returning null (not false) for non-super-admins lets the normal
        // spatie permission gates evaluate as usual. Without this the seeded
        // Super Admin holds no roles in any business and would fail every
        // authorize() call — the account exists precisely to have god-mode.
        Gate::before(fn ($user) => $user->is_super_admin ? true : null);

        // ⭐ Corrections wave 3, B11 (24 Oct 2026): the first sign-in decides who
        // may fix a login's email (a user admin before it, the Owner after)
        // and whether the login may be deleted at all. Written once, quietly.
        Event::listen(Login::class, function (Login $event): void {
            $user = $event->user;

            if ($user instanceof User && $user->first_signed_in_at === null) {
                $user->forceFill(['first_signed_in_at' => now()])->saveQuietly();
            }
        });
    }
}
