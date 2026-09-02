<?php

namespace App\Modules\Crm\Console\Commands;

use App\Modules\Crm\Support\SweepDormantCustomers;
use Illuminate\Console\Command;

class SweepDormantCustomersCommand extends Command
{
    protected $signature = 'crm:sweep-dormant-customers';

    protected $description = 'Create one follow-up task per dormant customer, per active cadence';

    public function handle(SweepDormantCustomers $sweep): int
    {
        $created = $sweep->handle();
        $this->info("Dormancy sweep: {$created} task(s) created.");

        return self::SUCCESS;
    }
}
