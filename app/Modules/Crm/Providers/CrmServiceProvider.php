<?php

namespace App\Modules\Crm\Providers;

use App\Modules\Crm\Console\Commands\NotifyFollowUpsDue;
use App\Modules\Crm\Console\Commands\SweepDormantCustomersCommand;
use App\Modules\Crm\Corrections\ContactActionPolicy;
use App\Modules\Crm\Corrections\ContactCorrector;
use App\Modules\Crm\Corrections\CrmActivityActionPolicy;
use App\Modules\Crm\Corrections\CrmActivityCorrector;
use App\Modules\Crm\Corrections\LeadActionPolicy;
use App\Modules\Crm\Corrections\LeadCorrector;
use App\Modules\Crm\Support\LeadVisibility;
use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use Illuminate\Support\ServiceProvider;

class CrmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ⚠ Scoped, not a plain singleton: the team it memoises belongs to ONE
        // request or job. A long-running worker holding a singleton would keep
        // yesterday's org chart after somebody changed supervisors in HRM.
        $this->app->scoped(LeadVisibility::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // ⭐ Corrections wave 3 (24 Oct 2026): leads, calls/visits/notes and
        // phone book contacts in the registry, which gives each the History
        // panel and the generic actions endpoint. Nothing in CRM posts, so the
        // correctors only find records (CrmRecordCorrector); the actions are
        // the module's own routes (CrmCorrectionsController).
        $registry = $this->app->make(DocumentPolicyRegistry::class);
        $registry->register($this->app->make(LeadActionPolicy::class), $this->app->make(LeadCorrector::class));
        $registry->register($this->app->make(CrmActivityActionPolicy::class), $this->app->make(CrmActivityCorrector::class));
        $registry->register($this->app->make(ContactActionPolicy::class), $this->app->make(ContactCorrector::class));

        // ⚠ Registered here, not only scheduled. A command referenced by the
        // scheduler but never registered exists on the production container and
        // nowhere else - including the test suite, which then proves nothing
        // about it.
        if ($this->app->runningInConsole()) {
            $this->commands([NotifyFollowUpsDue::class, SweepDormantCustomersCommand::class]);
        }
    }
}
