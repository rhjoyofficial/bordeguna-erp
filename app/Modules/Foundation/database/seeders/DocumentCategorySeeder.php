<?php

namespace App\Modules\Foundation\database\seeders;

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\DocumentCategory;
use Illuminate\Database\Seeder;

/**
 * A starting set of document kinds, so the archive is usable the first time
 * somebody opens it.
 *
 * ⚠ A taxonomy, not business data. The rule on this project is that Joy enters
 * his own data, and this does not break it: these are the same sort of
 * configuration as the chart of accounts, they are all renameable, and none of
 * them asserts a fact about his business. An archive that opens empty is one
 * where the first document gets filed under nothing and the habit never starts.
 *
 * ⚠⚠ EVERY RETENTION IS DELIBERATELY LEFT UNSET, which DocumentCategory reads
 * as "keep indefinitely". Statutory retention in Bangladesh is a question for
 * Joy and his accountant, and a number I invented here would look authoritative
 * while being made up - and the direction it fails in is documents being thrown
 * away. Silence in an archive must mean keep.
 */
class DocumentCategorySeeder extends Seeder
{
    /**
     * @var list<array{name:string, description:string}>
     */
    private const CATEGORIES = [
        ['name' => 'Licences and registrations', 'description' => 'Trade licence, TIN, BIN, factory and other permissions.'],
        ['name' => 'Contracts and agreements', 'description' => 'Signed agreements with customers, suppliers, landlords and partners.'],
        ['name' => 'Certificates', 'description' => 'Test reports, quality and compliance certificates.'],
        ['name' => 'Bills and receipts', 'description' => 'Supplier bills, utility bills and payment receipts kept as evidence.'],
        ['name' => 'Bank and financial', 'description' => 'Bank statements, cheque records and financial correspondence.'],
        ['name' => 'Insurance', 'description' => 'Policies and claim papers.'],
        ['name' => 'Company records', 'description' => 'Incorporation papers, resolutions and ownership documents.'],
        ['name' => 'Policies and circulars', 'description' => 'Internal policy documents and office circulars.'],
        ['name' => 'Product and packaging', 'description' => 'Label artwork, packaging designs and product specifications.'],
        ['name' => 'Photos', 'description' => 'Premises, products, events and anything else photographed.'],
        ['name' => 'Video and audio', 'description' => 'Recordings of premises, processes, training or events.'],
        ['name' => 'Correspondence', 'description' => 'Letters received and sent that are worth keeping.'],
    ];

    public function run(): void
    {
        // ⚠ Every business gets the same starting set. They are independent
        // rows, so renaming one for Bor de Guna does not touch LaLaDia's.
        foreach (Business::withoutGlobalScopes()->get() as $business) {
            foreach (self::CATEGORIES as $category) {
                $slug = DocumentCategory::slugFor($category['name']);

                // ⚠⚠ firstOrCreate on the UNIQUE KEY, never updateOrCreate. If
                // somebody has renamed a category or set a retention on it, a
                // later run of this seeder must leave their decision alone - a
                // seeder that overwrites choices is one nobody dares run twice.
                DocumentCategory::withoutGlobalScopes()->firstOrCreate(
                    ['business_id' => $business->id, 'slug' => $slug],
                    [
                        'name' => $category['name'],
                        'description' => $category['description'],
                        'retention_months' => null,
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}
