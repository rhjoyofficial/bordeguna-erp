<?php

use App\Modules\Assets\Http\Controllers\AssetCategoryController;
use App\Modules\Assets\Http\Controllers\AssetCorrectionController;
use App\Modules\Assets\Http\Controllers\AssetCountController;
use App\Modules\Assets\Http\Controllers\AssetCredentialController;
use App\Modules\Assets\Http\Controllers\AssetImportController;
use App\Modules\Assets\Http\Controllers\AssetItemController;
use App\Modules\Assets\Http\Controllers\AssetLabelController;
use App\Modules\Assets\Http\Controllers\AssetMaintenanceController;
use App\Modules\Assets\Http\Controllers\AssetMaintenancePlanController;
use App\Modules\Assets\Http\Controllers\AssetMovementController;
use App\Modules\Assets\Http\Controllers\AssetPartyController;
use App\Modules\Assets\Http\Controllers\AssetsOverviewController;
use App\Modules\Assets\Http\Controllers\CompanyAccountController;
use App\Modules\Assets\Http\Controllers\CompanyAccountImportController;
use App\Modules\Assets\Http\Controllers\DepreciationController;
use App\Modules\Assets\Http\Controllers\FixedAssetController;
use App\Modules\Assets\Http\Controllers\LeaverCheckController;
use App\Modules\Assets\Http\Controllers\MyAccountsController;
use App\Modules\Assets\Http\Controllers\MyAssetsController;
use App\Modules\Assets\Http\Middleware\AuditRefusedReveal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'business'])->prefix('assets')->name('assets.')->group(function () {
    // ⭐ The module's front page (redesign, 15 Oct 2026). `assets.overview` is
    // the derived ability in AssetsServiceProvider: any of the three view
    // permissions; the page hides each card by the one its list needs.
    Route::get('overview', [AssetsOverviewController::class, 'index'])
        ->middleware('can:assets.overview')->name('overview');

    // ⚠ Reading the register is a permission of its own rather than open to the
    // whole business, unlike purchase orders. What the business owns and what it
    // paid for it is closer to the payroll register than to a stock list.
    Route::middleware('can:assets.view')->group(function () {
        Route::get('/', [FixedAssetController::class, 'index'])->name('index');
        Route::get('depreciation', [DepreciationController::class, 'index'])->name('depreciation.index');
    });

    Route::post('/', [FixedAssetController::class, 'store'])
        ->middleware('can:assets.manage')->name('store');

    // ⭐ Corrections wave 2 (24 Oct 2026): a fixed asset leaves the business.
    // The policy decides (Owner, `assets.correct`); Reverse goes through the
    // generic /records/assets.fixed_asset/{id}/reverse routes.
    Route::post('fixed/{fixedAsset}/dispose', [FixedAssetController::class, 'dispose'])->name('fixed.dispose');

    // ⚠⚠ Owner only. One run posts cost against EVERY asset at once and cannot
    // be undone by running it again - the idempotence guard makes a second run
    // harmless, not reversible.
    Route::post('depreciation', [DepreciationController::class, 'store'])
        ->middleware('can:assets.depreciation.run')->name('depreciation.store');

    // ⭐ The PHYSICAL register (asset management slice A1, 16 September 2026):
    // what the business keeps, where it is, what shape it is in. Its own
    // permissions, so an Asset Keeper keeps the things without seeing a price.
    //
    // ⚠ Literal segments (create, labels, t/{tag}) register before the
    // `{item}` wildcard.
    Route::middleware('can:assets.items.manage')->group(function () {
        Route::get('items/create', [AssetItemController::class, 'create'])->name('items.create');
        // The things sheet (bulk import, 13 Oct 2026): template, preview, apply.
        // ⚠ A browser that reloads or goes back after a preview lands on the
        // GET of the preview URL: sent to the import page with any message
        // kept, never on a 405 (the catalogue importer's lesson, 29 Sep 2026).
        Route::get('items/import', [AssetImportController::class, 'create'])->name('items.import');
        Route::get('items/import/template', [AssetImportController::class, 'template'])->name('items.import.template');
        Route::get('items/import/preview', fn () => tap(redirect()->route('assets.items.import'), fn () => session()->reflash()))->name('items.import.preview.back');
        Route::post('items/import/preview', [AssetImportController::class, 'preview'])->name('items.import.preview');
        Route::post('items/import', [AssetImportController::class, 'store'])->name('items.import.store');
        Route::post('items', [AssetItemController::class, 'store'])->name('items.store');
        Route::get('items/{item}/edit', [AssetItemController::class, 'edit'])->whereNumber('item')->name('items.edit');
        Route::put('items/{item}', [AssetItemController::class, 'update'])->whereNumber('item')->name('items.update');
        Route::post('items/{item}/photos', [AssetItemController::class, 'storePhotos'])->whereNumber('item')->name('items.photos.store');
        Route::delete('items/{item}/photos/{attachment}', [AssetItemController::class, 'destroyPhoto'])
            ->whereNumber(['item', 'attachment'])->name('items.photos.destroy');
        Route::post('categories', [AssetCategoryController::class, 'store'])->name('categories.store');
        Route::put('categories/{category}', [AssetCategoryController::class, 'update'])->whereNumber('category')->name('categories.update');

        // Hand-overs (slice A2). Never edited: a mistake is another movement.
        Route::get('movements/create', [AssetMovementController::class, 'create'])->name('movements.create');
        Route::post('movements', [AssetMovementController::class, 'store'])->name('movements.store');
        Route::post('movements/{movement}/receive', [AssetMovementController::class, 'receive'])->whereNumber('movement')->name('movements.receive');
        // Stock-takes (slice A3). Confirming a loss is checked inside the action
        // (assets.items.write_off), since the same route settles every gap.
        Route::post('counts', [AssetCountController::class, 'store'])->name('counts.store');
        Route::post('counts/{count}/mark', [AssetCountController::class, 'mark'])->whereNumber('count')->name('counts.mark');
        Route::post('counts/{count}/found', [AssetCountController::class, 'addFound'])->whereNumber('count')->name('counts.found');
        Route::post('counts/{count}/close', [AssetCountController::class, 'close'])->whereNumber('count')->name('counts.close');
        Route::post('discrepancies/{discrepancy}/resolve', [AssetCountController::class, 'resolve'])->whereNumber('discrepancy')->name('discrepancies.resolve');
        // Maintenance (slice A4). Records are never edited once done or
        // cancelled; only their money changes, and that route is below.
        Route::get('maintenance/create', [AssetMaintenanceController::class, 'create'])->name('maintenance.create');
        Route::post('maintenance', [AssetMaintenanceController::class, 'store'])->name('maintenance.store');
        Route::post('maintenance/{record}/complete', [AssetMaintenanceController::class, 'complete'])->whereNumber('record')->name('maintenance.complete');
        Route::post('maintenance/{record}/cancel', [AssetMaintenanceController::class, 'cancel'])->whereNumber('record')->name('maintenance.cancel');
        Route::post('maintenance/{record}/photos', [AssetMaintenanceController::class, 'storePhotos'])->whereNumber('record')->name('maintenance.photos.store');
        Route::get('maintenance/plans/create', [AssetMaintenancePlanController::class, 'create'])->name('maintenance.plans.create');
        Route::post('maintenance/plans', [AssetMaintenancePlanController::class, 'store'])->name('maintenance.plans.store');
        Route::get('maintenance/plans/{plan}/edit', [AssetMaintenancePlanController::class, 'edit'])->whereNumber('plan')->name('maintenance.plans.edit');
        Route::put('maintenance/plans/{plan}', [AssetMaintenancePlanController::class, 'update'])->whereNumber('plan')->name('maintenance.plans.update');
        Route::post('maintenance/plans/{plan}/active', [AssetMaintenancePlanController::class, 'setActive'])->whereNumber('plan')->name('maintenance.plans.active');
        Route::post('parties', [AssetPartyController::class, 'store'])->name('parties.store');
        Route::put('parties/{party}', [AssetPartyController::class, 'update'])->whereNumber('party')->name('parties.update');
    });

    // ⭐ Corrections wave 3, B11 (24 Oct 2026): putting the register right
    // (FINAL-PLAN 3.5). No route middleware on purpose: each action's policy
    // names its own permission (Delete and the hand-over, count, maintenance
    // and import actions: assets.items.manage; Dispose and Undo disposal:
    // assets.items.dispose) and the controller asks it on every request.
    Route::post('items/delete', [AssetCorrectionController::class, 'deleteItems'])->name('items.delete');
    Route::post('items/{item}/dispose', [AssetCorrectionController::class, 'dispose'])->whereNumber('item')->name('items.dispose');
    Route::post('items/{item}/undo-disposal', [AssetCorrectionController::class, 'undoDisposal'])->whereNumber('item')->name('items.undo-disposal');
    Route::post('items/imports/{batch}/reverse', [AssetCorrectionController::class, 'reverseImport'])->whereNumber('batch')->name('items.import.reverse');
    Route::post('movements/{movement}/reverse', [AssetCorrectionController::class, 'reverseMovement'])->whereNumber('movement')->name('movements.reverse');
    Route::post('counts/{count}/cancel', [AssetCorrectionController::class, 'cancelCount'])->whereNumber('count')->name('counts.cancel');
    Route::post('maintenance/{record}/reopen', [AssetCorrectionController::class, 'reopenMaintenance'])->whereNumber('record')->name('maintenance.reopen');

    Route::middleware('can:assets.items.view')->group(function () {
        Route::get('items', [AssetItemController::class, 'index'])->name('items.index');
        Route::get('items/labels', [AssetLabelController::class, 'index'])->name('items.labels');
        Route::get('items/labels/print', [AssetLabelController::class, 'print'])->name('items.labels.print');
        // ⚠ Export is gated with VIEWING, not importing: reading out what you
        // can already see. Prices leave only with `assets.view`.
        Route::get('items/export', [AssetImportController::class, 'export'])->name('items.export');
        // A scanned QR code. It opens the item, and changes nothing.
        Route::get('items/t/{tag}', [AssetItemController::class, 'byTag'])
            ->where('tag', '[A-Za-z0-9\-]+')->name('items.by-tag');
        Route::get('items/{item}', [AssetItemController::class, 'show'])->whereNumber('item')->name('items.show');
        Route::get('categories', [AssetCategoryController::class, 'index'])->name('categories.index');
        Route::get('movements', [AssetMovementController::class, 'index'])->name('movements.index');
        Route::get('movements/{movement}', [AssetMovementController::class, 'show'])->whereNumber('movement')->name('movements.show');
        Route::get('parties', [AssetPartyController::class, 'index'])->name('parties.index');
        Route::get('counts', [AssetCountController::class, 'index'])->name('counts.index');
        Route::get('counts/{count}', [AssetCountController::class, 'show'])->whereNumber('count')->name('counts.show');
        Route::get('maintenance', [AssetMaintenanceController::class, 'index'])->name('maintenance.index');
        Route::get('maintenance/{record}', [AssetMaintenanceController::class, 'show'])->whereNumber('record')->name('maintenance.show');
    });

    // ⚠ What maintenance cost is money: the financial register's readers only
    // (plan §2.14). The action checks again.
    Route::post('maintenance/{record}/money', [AssetMaintenanceController::class, 'money'])
        ->middleware('can:assets.view')->whereNumber('record')->name('maintenance.money');

    // ⭐ Device logins (asset register slice 3, 13 October 2026). Adding,
    // changing and removing a login is one permission; READING one is the
    // policy in CredentialAccess (permission, or the current holder), after
    // the person re-typed their own password, within a throttle.
    Route::middleware('can:assets.secrets.manage')->group(function () {
        Route::post('items/{item}/credentials', [AssetCredentialController::class, 'storeForItem'])->whereNumber('item')->name('items.credentials.store');
        Route::post('accounts/{account}/credentials', [AssetCredentialController::class, 'storeForAccount'])->whereNumber('account')->name('accounts.credentials.store');
        Route::put('credentials/{credential}', [AssetCredentialController::class, 'update'])->whereNumber('credential')->name('credentials.update');
        Route::delete('credentials/{credential}', [AssetCredentialController::class, 'destroy'])->whereNumber('credential')->name('credentials.destroy');
        // Review of 13 Oct 2026 (ruling 5): a new secret in place of the old,
        // and the review a return or hand-over asked for.
        Route::post('credentials/{credential}/replace', [AssetCredentialController::class, 'replace'])->whereNumber('credential')->name('credentials.replace');
        Route::post('credentials/{credential}/reviewed', [AssetCredentialController::class, 'reviewed'])->whereNumber('credential')->name('credentials.reviewed');
    });
    // ⚠⚠ `password.confirm` answers a JSON request with 423 until the session
    // holds a fresh confirmation (config auth.password_timeout, ten minutes);
    // the card then sends the person through `credentials.confirm` and back.
    // Two throttles: ten looks a minute per user is a person, a hundred is a
    // script; thirty a minute per address catches one machine cycling through
    // users. ⚠ The framework's middleware priority runs the throttles FIRST,
    // so an attempt refused for want of a password counts against them too.
    // AuditRefusedReveal wraps the password gate and the controller, and
    // writes the log line for a 403/423.
    Route::post('credentials/{credential}/reveal', [AssetCredentialController::class, 'reveal'])
        ->middleware([AuditRefusedReveal::class, 'password.confirm', 'throttle:10,1', 'throttle:credential-reveal-ip'])
        ->whereNumber('credential')->name('credentials.reveal');
    // Where to come back to after confirming. Only a local path is accepted.
    Route::get('credentials/confirm', function (Request $request) {
        $back = (string) $request->query('back', '');
        $local = str_starts_with($back, '/') && ! str_starts_with($back, '//') && ! str_contains($back, '\\');
        $request->session()->put('url.intended', $local ? url($back) : route('assets.items.index'));

        return redirect()->route('password.confirm');
    })->name('credentials.confirm');

    // ⭐ The ACCOUNTS HUB (asset register slice 4, 13 October 2026): every
    // account the business owns. Owner, Manager and IT Custodian read it;
    // Owner and IT Custodian change it (Joy's answer 2).
    //
    // ⚠ Literal segments (create, export, import) register before `{account}`,
    // which is numeric-only besides.
    Route::middleware('can:assets.accounts.manage')->group(function () {
        Route::get('accounts/create', [CompanyAccountController::class, 'create'])->name('accounts.create');
        // The leaver check (review ruling 10): everything one employee holds,
        // is responsible for, has access to or guards, with the link to act.
        Route::get('accounts/leaver-check', [LeaverCheckController::class, 'index'])->name('accounts.leaver-check');
        Route::post('accounts', [CompanyAccountController::class, 'store'])->name('accounts.store');
        Route::get('accounts/import', [CompanyAccountImportController::class, 'create'])->name('accounts.import');
        Route::get('accounts/import/template', [CompanyAccountImportController::class, 'template'])->name('accounts.import.template');
        // A refreshed preview, or a refused Apply sending the browser back, lands on the import page.
        Route::get('accounts/import/preview', fn () => tap(redirect()->route('assets.accounts.import'), fn () => session()->reflash()))->name('accounts.import.preview.back');
        Route::post('accounts/import/preview', [CompanyAccountImportController::class, 'preview'])->name('accounts.import.preview');
        Route::post('accounts/import', [CompanyAccountImportController::class, 'store'])->name('accounts.import.store');
        Route::get('accounts/{account}/edit', [CompanyAccountController::class, 'edit'])->whereNumber('account')->name('accounts.edit');
        Route::put('accounts/{account}', [CompanyAccountController::class, 'update'])->whereNumber('account')->name('accounts.update');
        Route::post('accounts/{account}/renewed', [CompanyAccountController::class, 'markRenewed'])->whereNumber('account')->name('accounts.renewed');
    });

    // Who USES an account is its own permission (review ruling 6): granting
    // access is not the same as describing the account.
    Route::middleware('can:assets.accounts.access.manage')->group(function () {
        Route::post('accounts/{account}/access', [CompanyAccountController::class, 'grantAccess'])->whereNumber('account')->name('accounts.access.store');
        Route::post('accounts/{account}/access/{access}/revoke', [CompanyAccountController::class, 'revokeAccess'])->whereNumber(['account', 'access'])->name('accounts.access.revoke');
    });

    Route::middleware('can:assets.accounts.view')->group(function () {
        Route::get('accounts', [CompanyAccountController::class, 'index'])->name('accounts.index');
        // ⛔ The export never holds a login.
        Route::get('accounts/export', [CompanyAccountController::class, 'export'])->name('accounts.export');
        // ⚠ Resolved by the controller, not by model binding: an account another
        // business of the group shares with this one is outside the tenant scope
        // and still readable here (never writable: every write route above binds
        // through the scope and 404s).
        Route::get('accounts/{account}', [CompanyAccountController::class, 'show'])->whereNumber('account')->name('accounts.show');
    });
});

// My assets (slice A2): self-scope like My profile, no permission. Under /hrm
// so it sits with the other self-service tabs.
Route::middleware(['web', 'auth', 'business'])->prefix('hrm')->name('hrm.')->group(function () {
    Route::get('my-assets', [MyAssetsController::class, 'index'])->name('my-assets');
    Route::post('my-assets/{item}/acknowledge', [MyAssetsController::class, 'acknowledge'])->whereNumber('item')->name('my-assets.acknowledge');
    // Slice A4: the holder says something is wrong with it.
    Route::post('my-assets/{item}/report', [MyAssetsController::class, 'report'])->whereNumber('item')->name('my-assets.report');
    // Slice 4: the accounts I have access to, and their logins under the reveal policy.
    Route::get('my-accounts', [MyAccountsController::class, 'index'])->name('my-accounts');
});
