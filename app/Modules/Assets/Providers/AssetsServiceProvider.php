<?php

namespace App\Modules\Assets\Providers;

use App\Modules\Assets\Console\Commands\RebuildAssetHoldings;
use App\Modules\Assets\Console\Commands\RemindAccountRenewals;
use App\Modules\Assets\Console\Commands\RemindAssetMaintenance;
use App\Modules\Assets\Corrections\AssetCountActionPolicy;
use App\Modules\Assets\Corrections\AssetImportActionPolicy;
use App\Modules\Assets\Corrections\AssetItemActionPolicy;
use App\Modules\Assets\Corrections\AssetMaintenanceActionPolicy;
use App\Modules\Assets\Corrections\AssetMovementActionPolicy;
use App\Modules\Assets\Listeners\FlagCredentialsForReview;
use App\Modules\Assets\Models\AssetCredential;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\AssetMovementLine;
use App\Modules\Assets\Models\CompanyAccount;
use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Fixed assets: what the business owns and uses rather than sells.
 *
 * ⚠ Its own module rather than a corner of Purchasing or Accounting. Buying an
 * asset is not purchasing stock, and an asset register is an operational record
 * that happens to have an accounting consequence - putting it in Accounting
 * would make the ledger own a document, which is the boundary every other
 * integration has been careful to keep.
 */
class AssetsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            // Slice A2: prove the holdings of counted groups against their movements.
            // Slice A4: the daily maintenance digest. Slice 4 (13 Oct 2026):
            // account renewals at 30 days, 7 days and overdue.
            $this->commands([RebuildAssetHoldings::class, RemindAssetMaintenance::class, RemindAccountRenewals::class]);
        }

        // ⭐ Review of 13 October 2026 (rulings 3 and 5): a return or a
        // reassignment flags the thing's logins for review. On the movement
        // LINE, because MoveAssets writes the lines after the movement.
        AssetMovementLine::created(new FlagCredentialsForReview);

        // ⚠ NO ORPHANS (ruling 1): a login whose owner is gone is a login
        // nobody is responsible for. The morph has no database-level cascade,
        // so the owner's own `deleting` removes its logins first, inside
        // whatever transaction deletes the owner. The reveal 404s on a null
        // owner besides, for a row that slipped through.
        $dropCredentials = function (Model $owner) {
            // ⭐ Wave 3 (24 Oct 2026): a thing moved to the Recycle bin keeps
            // its (already binned) logins for a restore; only an owner that
            // is really deleted takes them with it, for good. A table delete,
            // because AssetCredential soft-deletes now.
            if (method_exists($owner, 'isForceDeleting') && ! $owner->isForceDeleting()) {
                return;
            }

            DB::table('asset_credentials')
                ->where('credentialable_type', $owner->getMorphClass())
                ->where('credentialable_id', $owner->getKey())
                ->delete();
        };
        CompanyAccount::deleting($dropCredentials);
        AssetItem::deleting($dropCredentials);

        // ⚠ The reveal's SECOND throttle, by address (ruling 2): `throttle:10,1`
        // keys by the signed-in user, so one address cycling through accounts
        // would never hit it. Thirty a minute from one address is generous for
        // a shared office line and far too few for a script.
        RateLimiter::for('credential-reveal-ip', fn (Request $request) => Limit::perMinute(30)->by('reveal-ip:'.$request->ip()));

        // ⭐ The Assets overview (module redesign, 15 Oct 2026) opens for anyone
        // who may read ONE of the three registers; each card then shows only
        // with its own permission. A derived ability, not a seeded permission:
        // nobody is granted "the overview", it follows from what they may see.
        Gate::define('assets.overview', fn ($user) => $user->can('assets.items.view') || $user->can('assets.accounts.view') || $user->can('assets.view'));

        // ⚠⚠ Added 9 August. Until this line, the module loaded migrations ONLY:
        // the tables existed, the actions were tested, and there was no way for
        // a person to reach any of it. A delivery van's only reachable path was
        // Purchasing, which turns it into stock and expenses it as cost of goods
        // sold - straight through one month's P&L and off the balance sheet.
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // ⭐ Corrections wave 3, B11 (24 Oct 2026): the things register in the
        // corrections process (FINAL-PLAN 3.5). Policies only: every action
        // here is the module's own (no journal), so there is no corrector.
        $registry = $this->app->make(DocumentPolicyRegistry::class);
        $registry->register($this->app->make(AssetItemActionPolicy::class));
        $registry->register($this->app->make(AssetMovementActionPolicy::class));
        $registry->register($this->app->make(AssetCountActionPolicy::class));
        $registry->register($this->app->make(AssetMaintenanceActionPolicy::class));
        $registry->register($this->app->make(AssetImportActionPolicy::class));
    }
}
