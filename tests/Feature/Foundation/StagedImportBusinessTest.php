<?php

use App\Modules\Foundation\Support\StagedImportFile;
use App\Modules\Sales\Support\StagedShopOrderFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * CRM-014 follow-up (audit 14 Oct 2026): every staged-file importer (products,
 * prices, opening stock, shop orders, things/assets, company accounts,
 * expenses) parks its upload under the business that reviewed it and reopens
 * it by the CURRENT business, so a preview made in one business cannot be
 * applied in another. Unlike the session importers fixed alongside, these
 * were already safe by design; this pins the rule so it stays that way. Each
 * of those controllers passes app(BusinessContext::class)->id() on both steps.
 */
beforeEach(fn () => Storage::fake('local'));

it('refuses to reopen a staged import file from another business', function () {
    $staged = StagedImportFile::stage(UploadedFile::fake()->createWithContent('rows.csv', "code,name\nA,Apple\n"), 1);

    expect(StagedImportFile::reopen($staged->token, $staged->hash, 1)->token)->toBe($staged->token);
    expect(fn () => StagedImportFile::reopen($staged->token, $staged->hash, 2))
        ->toThrow(RuntimeException::class, 'cannot be found');
});

it('refuses to reopen a staged shop order sheet from another business', function () {
    $staged = StagedShopOrderFile::stage(UploadedFile::fake()->createWithContent('orders.csv', "shop,item,qty\nA,B,1\n"), 1);

    expect(StagedShopOrderFile::reopen($staged->token, $staged->hash, 1)->token)->toBe($staged->token);
    expect(fn () => StagedShopOrderFile::reopen($staged->token, $staged->hash, 2))
        ->toThrow(RuntimeException::class, 'cannot be found');
});
