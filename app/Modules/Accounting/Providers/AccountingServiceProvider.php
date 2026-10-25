<?php

namespace App\Modules\Accounting\Providers;

use App\Modules\Accounting\Console\Commands\BackfillEntityLedgers;
use App\Modules\Accounting\Console\Commands\CorrectionsPreflight;
use App\Modules\Accounting\Console\Commands\ReconcileSubledgers;
use App\Modules\Accounting\Console\Commands\ReleaseDueAmortizations;
use App\Modules\Accounting\Console\Commands\VerifyAccountingLedger;
use App\Modules\Accounting\Corrections\IncomeVoucherActionPolicy;
use App\Modules\Accounting\Corrections\IncomeVoucherCorrector;
use App\Modules\Accounting\Corrections\IncomeVoucherDuplicateRule;
use App\Modules\Accounting\Corrections\InterBusinessTransferActionPolicy;
use App\Modules\Accounting\Corrections\InterBusinessTransferCorrector;
use App\Modules\Accounting\Corrections\InterBusinessTransferDuplicateRule;
use App\Modules\Accounting\Corrections\ManualJournalActionPolicy;
use App\Modules\Accounting\Corrections\ManualJournalCorrector;
use App\Modules\Accounting\Corrections\MoneyTransferActionPolicy;
use App\Modules\Accounting\Corrections\MoneyTransferCorrector;
use App\Modules\Accounting\Corrections\MoneyTransferDuplicateRule;
use App\Modules\Accounting\Corrections\OwnerWithdrawalActionPolicy;
use App\Modules\Accounting\Corrections\OwnerWithdrawalCorrector;
use App\Modules\Accounting\Corrections\PartyAdjustmentActionPolicy;
use App\Modules\Accounting\Corrections\PartyAdjustmentCorrector;
use App\Modules\Accounting\Corrections\SetOffActionPolicy;
use App\Modules\Accounting\Corrections\SetOffCorrector;
use App\Modules\Accounting\Corrections\SslSettlementActionPolicy;
use App\Modules\Accounting\Corrections\SslSettlementCorrector;
use App\Modules\Accounting\Corrections\SslSettlementDuplicateRule;
use App\Modules\Accounting\Corrections\VoucherActionPolicy;
use App\Modules\Accounting\Corrections\VoucherCorrector;
use App\Modules\Accounting\Corrections\VoucherDuplicateRule;
use App\Modules\Accounting\Observers\EntityLedgerObserver;
use App\Modules\Accounting\Reporting\Definitions\BudgetVsActualReport;
use App\Modules\Accounting\Reporting\Definitions\CashPositionReport;
use App\Modules\Accounting\Support\ChartPermissions;
use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Reporting\ReportRegistry;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AccountingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([VerifyAccountingLedger::class, ReconcileSubledgers::class, ReleaseDueAmortizations::class, BackfillEntityLedgers::class, CorrectionsPreflight::class]);
        }

        // The old all-in-one chart key keeps every split power (ChartPermissions).
        ChartPermissions::register();

        // ⭐ 27 Sep 2026 (Joy): every customer, supplier and employee carries its
        // own ledger head, created the moment the entity is (EntityLedgers).
        Customer::observe(EntityLedgerObserver::class);
        Supplier::observe(EntityLedgerObserver::class);
        Employee::observe(EntityLedgerObserver::class);

        // ⭐ 26 Sep 2026 (Q19): the three operating reports open for whoever
        // holds the narrow key (the Manager) OR reads the whole ledger. Spatie's
        // before-hook answers true for the key itself and falls through here
        // when the user lacks it, so this closure only has to know the wide one.
        Gate::define('accounting.operations.view', fn ($user) => $user->can('accounting.view'));

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // ⚠ Resolved in boot(), never register() - the registry singleton is
        // built by HrmServiceProvider::register, and every register() runs
        // before any boot(). Same pattern, same reason, as Purchasing.
        $registry = $this->app->make(ReportRegistry::class);
        $registry->register($this->app->make(BudgetVsActualReport::class));
        // Reports pack (11 Oct 2026): cash, kept apart from money that is not cash.
        $registry->register($this->app->make(CashPositionReport::class));

        // ⭐ Edit, delete and correct, Phase 0 (17 Oct 2026): Other income is the
        // first document type with Reverse and Correct (the vertical slice).
        $this->app->make(DocumentPolicyRegistry::class)->register(
            $this->app->make(IncomeVoucherActionPolicy::class),
            $this->app->make(IncomeVoucherCorrector::class),
            $this->app->make(IncomeVoucherDuplicateRule::class),
        );

        // ⭐ Set-offs (decision D-F, 18 Oct 2026): reversed only through the
        // corrections process, which reopens the bills and invoices they cleared.
        $this->app->make(DocumentPolicyRegistry::class)->register(
            $this->app->make(SetOffActionPolicy::class),
            $this->app->make(SetOffCorrector::class),
        );

        // ⭐ Corrections wave 2, accounts (B7, 24 Oct 2026): vouchers, money
        // transfers, inter-business transfers, manual journals and party
        // adjustments go through the same corrections process.
        $corrections = $this->app->make(DocumentPolicyRegistry::class);
        $corrections->register($this->app->make(VoucherActionPolicy::class), $this->app->make(VoucherCorrector::class), $this->app->make(VoucherDuplicateRule::class));
        $corrections->register($this->app->make(MoneyTransferActionPolicy::class), $this->app->make(MoneyTransferCorrector::class), $this->app->make(MoneyTransferDuplicateRule::class));
        // ⭐ Joy's answer 9 (25 Oct 2026): Reverse of an SSL settlement.
        $corrections->register($this->app->make(SslSettlementActionPolicy::class), $this->app->make(SslSettlementCorrector::class), $this->app->make(SslSettlementDuplicateRule::class));
        $corrections->register($this->app->make(InterBusinessTransferActionPolicy::class), $this->app->make(InterBusinessTransferCorrector::class), $this->app->make(InterBusinessTransferDuplicateRule::class));
        $corrections->register($this->app->make(ManualJournalActionPolicy::class), $this->app->make(ManualJournalCorrector::class));
        $corrections->register($this->app->make(PartyAdjustmentActionPolicy::class), $this->app->make(PartyAdjustmentCorrector::class));
        // ⭐ Initial plan B24 (25 Oct 2026): Reverse of a posted owner withdrawal.
        $corrections->register($this->app->make(OwnerWithdrawalActionPolicy::class), $this->app->make(OwnerWithdrawalCorrector::class));
    }
}
