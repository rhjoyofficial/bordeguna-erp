<?php

use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Support\SkuProposal;

/**
 * ⚠⚠ WHAT THIS GUARDS. The live catalogue's category segments do not follow
 * from the category names - Nuts is MIX, Salts is SLT, Seeds is SED - so a
 * generator that guessed would propose BNC-SEE-CHI-500G alongside the existing
 * BNC-SED-CHI-500G and split the catalogue into two families of one product.
 *
 * Every expectation below is a SKU that exists in production today, or a
 * refusal that is safer than a guess.
 */
function proposal(array $taken = [], array $known = []): SkuProposal
{
    return new SkuProposal(fn (string $sku) => in_array($sku, $taken, true), $known);
}

/**
 * The live catalogue, as the generator sees it: what this business already
 * calls each word. Beetroot is BET, Black is BLK, Beef is BEF - none of which
 * follow from any rule, which is the whole reason the generator learns.
 */
function liveCatalogue(): array
{
    return SkuProposal::learnFrom([
        ['name' => 'Beetroot Powder (বিটরুট পাউডার) - 200 g', 'sku' => 'BNC-POW-BET-200G'],
        ['name' => 'Black Seed Oil (কালোজিরার তেল) - 200 ml', 'sku' => 'BNC-OIL-BLK-200ML'],
        ['name' => 'Royal Beef Pickle (রয়্যাল বিফ আচার) - 400 g', 'sku' => 'LLD-PIC-BEF-400G'],
        ['name' => 'Ajwa Date (আজওয়া খেজুর) - 1 kg (Large)', 'sku' => 'BNC-DAT-AJW-1KG-LRG'],
        ['name' => 'Chia Seeds (চিয়া সীডস) - 500 g', 'sku' => 'BNC-SED-CHI-500G'],
    ]);
}

it('rebuilds the SKUs the live catalogue already uses', function (string $name, ProductType $type, ?string $brand, string $category, string $expected) {
    expect(proposal([], liveCatalogue())->propose($name, $type, $brand, $category)['sku'])->toBe($expected);
})->with([
    // Finished goods, verbatim from production.
    'ajwa large' => ['Ajwa Date (আজওয়া খেজুর) - 1 kg (Large)', ProductType::FinishedGood, 'BNC', 'DAT', 'BNC-DAT-AJW-1KG-LRG'],
    'ajwa jambo' => ['Ajwa Date (আজওয়া খেজুর) - 5 kg (Jambo)', ProductType::FinishedGood, 'BNC', 'DAT', 'BNC-DAT-AJW-5KG-JAM'],
    'beetroot powder' => ['Beetroot Powder (বিটরুট পাউডার) - 200 g', ProductType::FinishedGood, 'BNC', 'POW', 'BNC-POW-BET-200G'],
    'chia seeds' => ['Chia Seeds (চিয়া সীডস) - 500 g', ProductType::FinishedGood, 'BNC', 'SED', 'BNC-SED-CHI-500G'],
    'churi shutki' => ['Churi Shutki (ছুরি শুটকি) - 125 g', ProductType::FinishedGood, 'LLD', 'DRY', 'LLD-DRY-CHU-125G'],
    'black seed oil' => ['Black Seed Oil (কালোজিরার তেল) - 200 ml', ProductType::FinishedGood, 'BNC', 'OIL', 'BNC-OIL-BLK-200ML'],
    'beef pickle' => ['Royal Beef Pickle (রয়্যাল বিফ আচার) - 400 g', ProductType::FinishedGood, 'LLD', 'PIC', 'LLD-PIC-BEF-400G'],

    // Raw materials: no brand, no pack size, because bulk is measured not packed.
    'bulk honey' => ['Mangrove Gold Honey (ম্যানগ্রোভ গোল্ড হানি) - bulk', ProductType::RawMaterial, null, 'HON', 'RM-HON-MAN'],
    'bulk mustard oil' => ['Mustard Oil (খাঁটি সরিষার তেল) - bulk', ProductType::RawMaterial, null, 'OIL', 'RM-OIL-MUS'],
    'bulk beef pickle' => ['Royal Beef Pickle (রয়্যাল বিফ আচার) - bulk', ProductType::RawMaterial, null, 'PIC', 'RM-PIC-BEF'],

    // Packaging: the category code carries the family, so no RM prefix and no
    // item segment, and the size is what tells one jar from another.
    'jar' => ['Jar (কাচের বয়াম) - 500 g fill', ProductType::RawMaterial, null, 'PK-JAR', 'PK-JAR-500G'],
    'bottle' => ['Bottle (বোতল) - 200 ml', ProductType::RawMaterial, null, 'PK-BTL', 'PK-BTL-200ML'],
    'pouch' => ['Pouch (পাউচ) - 1 kg fill', ProductType::RawMaterial, null, 'PK-POU', 'PK-POU-1KG'],
]);

it('ignores the Bangla in a name entirely', function () {
    // The Bangla says "honey" too. If it contributed even one letter the segment
    // would not be MAN, and the SKU would drift away from the live convention.
    $withBangla = proposal()->propose('Mangrove Gold Honey (ম্যানগ্রোভ গোল্ড হানি) - 500 g', ProductType::FinishedGood, 'LLD', 'HON');
    $without = proposal()->propose('Mangrove Gold Honey - 500 g', ProductType::FinishedGood, 'LLD', 'HON');

    expect($withBangla['sku'])->toBe('LLD-HON-MAN-500G')
        ->and($without['sku'])->toBe($withBangla['sku']);
});

it('treats a decomposed name and a precomposed one as the same', function () {
    // ⚠⚠ Production stores decomposed য + nukta; a file may carry the
    // precomposed form. Identical on screen, never equal as strings. The two
    // must not produce different SKUs.
    $decomposed = "Modhu Faisa Chapa (\u{09AF}\u{09BC}) - 1 kg";
    $precomposed = "Modhu Faisa Chapa (\u{09DF}) - 1 kg";

    expect(proposal()->propose($decomposed, ProductType::FinishedGood, 'LLD', 'DRY')['sku'])
        ->toBe(proposal()->propose($precomposed, ProductType::FinishedGood, 'LLD', 'DRY')['sku']);
});

it('skips words that carry no identity', function () {
    // "Bulk" and "Royal" describe, they do not identify. BUL and ROY would be
    // the same segment for every bulk item and every royal one.
    expect(proposal([], liveCatalogue())->propose('Bulk Honey - 1 kg', ProductType::FinishedGood, 'LLD', 'HON')['sku'])->toBe('LLD-HON-HON-1KG')
        ->and(proposal([], liveCatalogue())->propose('Royal Beef Pickle - 400 g', ProductType::FinishedGood, 'LLD', 'PIC')['sku'])->toBe('LLD-PIC-BEF-400G');
});

it('learns an abbreviation no rule would produce', function () {
    // ⚠⚠ THE POINT OF THE WHOLE CLASS. Without learning, "Black Seed Oil" in a
    // new size proposes BLA and sits next to the live BLK as a second family of
    // the same product. Every report would still balance.
    $blind = proposal()->propose('Black Seed Oil (কালোজিরার তেল) - 500 ml', ProductType::FinishedGood, 'BNC', 'OIL');
    $learned = proposal([], liveCatalogue())->propose('Black Seed Oil (কালোজিরার তেল) - 500 ml', ProductType::FinishedGood, 'BNC', 'OIL');

    expect($blind['sku'])->toBe('BNC-OIL-BLA-500ML')
        ->and($learned['sku'])->toBe('BNC-OIL-BLK-500ML');
});

it('refuses to learn a word the catalogue spells two ways', function () {
    // A catalogue that already disagrees with itself must not have one of its
    // spellings blessed silently. Fall back to the rule and let a human look.
    $known = SkuProposal::learnFrom([
        ['name' => 'Black Seed Oil - 200 ml', 'sku' => 'BNC-OIL-BLK-200ML'],
        ['name' => 'Black Seed Oil - 360 ml', 'sku' => 'BNC-OIL-BLA-360ML'],
    ]);

    expect($known)->not->toHaveKey('black');
});

it('collapses a repeated letter so two different words do not share one segment', function () {
    // Beetroot and Beef both begin BEE. Collapsing the doubled letter gives BET
    // and BEF, which is what the catalogue itself does.
    expect(proposal()->propose('Beetroot Powder - 200 g', ProductType::FinishedGood, 'BNC', 'POW')['sku'])->toBe('BNC-POW-BET-200G')
        ->and(proposal()->propose('Beef Pickle - 400 g', ProductType::FinishedGood, 'LLD', 'PIC')['sku'])->toBe('LLD-PIC-BEF-400G');
});

it('numbers a collision instead of handing back a SKU that is taken', function () {
    $result = proposal(['BNC-SED-CHI-500G'])->propose('Chia Seeds - 500 g', ProductType::FinishedGood, 'BNC', 'SED');

    expect($result['sku'])->toBe('BNC-SED-CHI-500G-2')
        ->and($result['note'])->toContain('already used');
});

it('refuses rather than inventing a category segment', function () {
    // ⚠ The whole reason categories carry a stored code. With none, the honest
    // answer is to say so, not to take the first three letters of the name.
    $result = proposal()->propose('Chia Seeds - 500 g', ProductType::FinishedGood, 'BNC', null);

    expect($result['sku'])->toBeNull()
        ->and($result['reason'])->toContain('categories screen');
});

it('refuses a finished good with no brand', function () {
    $result = proposal()->propose('Chia Seeds - 500 g', ProductType::FinishedGood, null, 'SED');

    expect($result['sku'])->toBeNull()
        ->and($result['reason'])->toContain('brand');
});

it('refuses packaging whose name carries no size', function () {
    // PK-CAP on its own identifies nothing, and there are three closures. Better
    // typed by hand than proposed as a name-collision waiting to happen.
    $result = proposal()->propose('Jar lid (বয়ামের ঢাকনা)', ProductType::RawMaterial, null, 'PK-CAP');

    expect($result['sku'])->toBeNull()
        ->and($result['reason'])->toContain('size');
});

it('refuses a category code that is not a usable segment', function (string $code) {
    expect(proposal()->propose('Chia Seeds - 500 g', ProductType::FinishedGood, 'BNC', $code)['sku'])->toBeNull();
})->with([
    'empty segment from a stray hyphen' => ['PK-'],
    'leading hyphen' => ['-JAR'],
    'one letter' => ['J'],
    'punctuation' => ['P*K'],
]);

it('never proposes anything but a well formed SKU', function () {
    // A belt-and-braces sweep: whatever comes out must be usable as an identity
    // in every importer, all of which match on exact string.
    $names = [
        'Ajwa Date (আজওয়া খেজুর) - 1 kg (Large)',
        '   spaced   out   -   200 ml  ',
        'Egyptian Medjool (মেডজুল খেজুর) - 5 kg (Jambo)',
        'Himalayan Pink Salt - 1 kg',
    ];

    foreach ($names as $name) {
        $sku = proposal()->propose($name, ProductType::FinishedGood, 'BNC', 'DAT')['sku'];

        if ($sku !== null) {
            expect($sku)->toMatch('/^[A-Z0-9]+(-[A-Z0-9]+)+$/');
        }
    }
});
