<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use Illuminate\Console\Command;

/**
 * Issues a Sanctum token for the HQ integration user, scoped to ONE business with an explicit
 * ability list. The plain token is printed exactly once so it can be pasted into HQ's encrypted
 * credential store; it is never stored in clear here.
 *
 *   php artisan hq:api:token hq@bordeguna.com 4 --abilities=sales.reports.view,inventory.reports.view
 *   php artisan hq:api:token hq@bordeguna.com 4 --revoke        # revoke every token this user holds for business 4
 */
final class HqApiToken extends Command
{
    protected $signature = 'hq:api:token {email} {business} {--abilities= : comma-separated ERP permission names the token may exercise} {--name=hq} {--revoke}';

    protected $description = 'Issue (or revoke) an HQ API token scoped to one business.';

    public const READ_ABILITIES = [
        'inventory.view', 'inventory.reports.view', 'sales.view', 'sales.reports.view', 'sales.customers.view',
        'purchasing.view', 'purchasing.reports.view', 'accounting.view', 'hrm.employees.view', 'hrm.attendance.view',
        'crm.tickets.view', 'crm.tickets.manage', 'crm.contacts.view',
    ];

    public function handle(): int
    {
        $user = User::where('email', strtolower(trim($this->argument('email'))))->first();
        $business = Business::find((int) $this->argument('business'));
        if (! $user || ! $business) {
            $this->error('Unknown user or business.');

            return self::FAILURE;
        }

        $name = "{$this->option('name')}:business:{$business->id}";

        if ($this->option('revoke')) {
            $n = $user->tokens()->where('name', $name)->delete();
            $this->info("Revoked {$n} token(s) named {$name}.");

            return self::SUCCESS;
        }

        $abilities = collect(explode(',', (string) $this->option('abilities')))->map(fn ($a) => trim($a))->filter()->values();
        $unknown = $abilities->reject(fn ($a) => in_array($a, self::READ_ABILITIES, true));
        if ($unknown->isNotEmpty()) {
            $this->error('Refusing abilities outside the HQ read allow-list: '.$unknown->implode(', '));

            return self::FAILURE;
        }
        $abilities->push("hq:business:{$business->id}");

        $token = $user->createToken($name, $abilities->all());
        $this->info("Token for {$user->email} on business #{$business->id} ({$business->name}) with abilities: ".$abilities->implode(', '));
        $this->warn('Plain token (shown once, paste into HQ credentials now):');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
