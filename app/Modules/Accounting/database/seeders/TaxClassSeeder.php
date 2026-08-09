<?php

namespace App\Modules\Accounting\database\seeders;

use App\Modules\Accounting\Models\TaxClass;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Database\Seeder;

/**
 * The three tax classes every VAT regime has, whatever the rates turn out to be.
 *
 * ⚠⚠ CLASSES ONLY, NO RATES. C-VAT-2 is deliberately open - Joy has not
 * confirmed whether Bor De Guna falls under standard 15% VAT or 4% turnover tax,
 * and those are different engines. A class is a statement about the KIND of
 * thing being sold and is true under either regime; a rate is not. Seeding a
 * rate here would be inventing the decision this note exists to defer.
 *
 * ⛔ NOTHING CALCULATES WITH THESE. VAT-READY-ARCHITECTURE.md, C-VAT-11.
 */
class TaxClassSeeder extends Seeder
{
    /** @var array<string, array{name: string, note: string}> */
    private const CLASSES = [
        'STANDARD' => [
            'name' => 'Standard',
            'note' => 'The default. Whatever the ordinary rate turns out to be, it applies here.',
        ],
        'ZERO' => [
            'name' => 'Zero-rated',
            'note' => 'Taxable at 0%. NOT the same as exempt: input VAT on these purchases is still reclaimable.',
        ],
        'EXEMPT' => [
            'name' => 'Exempt',
            'note' => 'Outside VAT entirely. Input VAT on these purchases is NOT reclaimable, which is what makes it different from zero-rated.',
        ],
    ];

    public function run(): void
    {
        $previous = app(BusinessContext::class)->id();

        foreach (Business::withoutGlobalScopes()->orderBy('id')->get() as $business) {
            TenantScope::enter($business->id);

            foreach (self::CLASSES as $code => $details) {
                // ⚠ firstOrCreate on (business, code) so re-running adds nothing
                // and overwrites nothing. The deploy runs this by hand, and a
                // seeder that is not safe to run twice is a seeder somebody is
                // afraid to run at all.
                TaxClass::firstOrCreate(
                    ['business_id' => $business->id, 'code' => $code],
                    ['name' => $details['name'], 'note' => $details['note'], 'is_active' => true],
                );
            }
        }

        $previous === null ? TenantScope::leave() : TenantScope::enter($previous);
    }
}
