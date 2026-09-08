<?php

namespace App\Modules\Foundation\Support;

/**
 * One document, described once, so the three ways of putting it on paper cannot
 * drift apart.
 *
 * ⭐⭐ THE PROBLEM THIS SOLVES. An invoice is printed on A4, handed over on an
 * 80mm slip and emailed as a PDF. Written three times, the three copies start
 * the same and end up disagreeing - and the one that disagrees is discovered by
 * a customer holding two papers with different totals. So the document is
 * described HERE, in fields, and each renderer decides only how to draw it.
 *
 * ⚠ NOTHING IS COMPUTED IN A RENDERER. Every figure arrives already worked out
 * by the module that owns the rule. A renderer that adds up a column is a
 * second opinion about money, and this codebase has exactly one.
 *
 * ⚠ Amounts are passed as already-formatted strings for the same reason: the
 * business's own currency and rounding decisions belong to the module, not to a
 * component that happens to be drawing a line.
 */
final class PrintableDocument
{
    /**
     * @param  list<array{label:string, value:string}>  $meta
     * @param  array{heading:string, name:string, lines:list<string>}|null  $party
     * @param  list<array{description:string, detail:?string, quantity:string, unit_price:string, discount:?string, net:string}>  $lines
     * @param  list<array{label:string, value:string, emphasis:bool}>  $totals
     * @param  list<array{label:string, value:string}>  $payments
     * @param  list<string>  $footerLines
     */
    public function __construct(
        public readonly string $title,
        public readonly string $number,
        public readonly array $meta = [],
        public readonly ?array $party = null,
        public readonly array $lines = [],
        public readonly array $totals = [],
        public readonly array $payments = [],
        public readonly ?string $barcodeValue = null,
        public readonly array $footerLines = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'number' => $this->number,
            'meta' => $this->meta,
            'party' => $this->party,
            'lines' => $this->lines,
            'totals' => $this->totals,
            'payments' => $this->payments,
            'barcode_value' => $this->barcodeValue,
            'footer_lines' => $this->footerLines,
        ];
    }
}
