<?php

use App\Modules\Sales\Support\AmountInWords;

/**
 * The customer's invoice as a DOCUMENT.
 *
 * ⛔⛔ WHY THE DATE TEST EXISTS. The print page was handed the Eloquent model,
 * so Inertia serialised `invoice_date` as a Carbon ISO-8601 string and the
 * printed invoice read "2026-09-04T18:00:00.000000Z" for an invoice dated the
 * 5th. Two faults in one line: unreadable to a customer, and a DAY EARLY,
 * because the cast renders in UTC while the invoice date is a Dhaka date.
 *
 * A commercial document showing the wrong date is not a styling problem, and
 * nothing in the test suite would have noticed - the page rendered, the totals
 * were right, and every assertion passed.
 */
it('⛔ never lets a raw timestamp reach a printed document', function () {
    $source = file_get_contents(resource_path('js/pages/sales/invoices/print.tsx'));

    // The dates the page prints must be strings the SERVER formatted. If this
    // page ever starts formatting a date itself, or is handed a model again,
    // the timezone question comes back with it.
    expect($source)->not->toContain('new Date(')
        ->and($source)->not->toContain('toLocaleDateString');

    $controller = file_get_contents(app_path('Modules/Sales/Http/Controllers/SalesInvoiceController.php'));

    // Shaped, not handed over whole.
    expect($controller)->toContain("'invoice_date' => \$invoice->invoice_date?->format(")
        ->and($controller)->toContain("'due_date' => \$invoice->due_date?->format(");
});

it('spells the total the way a commercial invoice spells it', function () {
    // ⭐ South Asian numbering deliberately: the customer reading this is in
    // Bangladesh and will check "lakh" against the figure, not "hundred
    // thousand".
    expect(AmountInWords::taka('39470.00'))->toBe('Thirty Nine Thousand Four Hundred Seventy Taka Only')
        ->and(AmountInWords::taka('100000'))->toBe('One Lakh Taka Only')
        ->and(AmountInWords::taka('12500000'))->toBe('One Crore Twenty Five Lakh Taka Only')
        ->and(AmountInWords::taka('0'))->toBe('Zero Taka Only');
});

it('carries the poisha into the words, rounded the way the total is', function () {
    // ⚠ Rounded, not truncated. If the figure says 40.99 and the words say
    // 98 Poisha, somebody has to work out which half of the document is right.
    expect(AmountInWords::taka('40.985'))->toBe('Forty and Ninety Nine Poisha Taka Only')
        ->and(AmountInWords::taka('547.50'))->toBe('Five Hundred Forty Seven and Fifty Poisha Taka Only');
});

it('rolls a rounded-up poisha into the taka rather than saying one hundred poisha', function () {
    expect(AmountInWords::taka('9.999'))->toBe('Ten Taka Only');
});

it('says so when an amount is negative rather than printing it silently', function () {
    expect(AmountInWords::taka('-250'))->toBe('Minus Two Hundred Fifty Taka Only');
});

it('puts on the invoice everything a customer accounts department would otherwise telephone about', function () {
    $source = file_get_contents(resource_path('js/pages/sales/invoices/print.tsx'));

    // Each of these is a real question somebody rings up to ask when it is
    // missing from an invoice.
    foreach (['Amount in words', 'Currency', 'Payment due', 'Billed to', 'Balance due', 'Received by'] as $needed) {
        expect($source)->toContain($needed);
    }
});
