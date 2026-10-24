<?php

namespace App\Support\Documents;

use App\Models\User;
use App\Modules\Accounting\Support\CustomerStatement;
use App\Modules\Accounting\Support\CustomerStatementLine;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\PartyStatement;
use App\Modules\Accounting\Support\SupplierStatement;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use App\Support\Lifetime\LifetimeCsv;
use App\Support\Lifetime\LifetimeRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ⭐ B23 LIFETIME OF ONE PARTY (25 Oct 2026; R75, R77).
 *
 * Joy: "my supplier name will get all the transaction like purchased, sells,
 * advance, dues, etc. everything will come and put under this supplier".
 *
 * Built from what already exists, never a new reading of the books:
 *
 *   documents  PartyDocuments for this party AND, when the firm is also our
 *              customer (or supplier), the other side's documents too, each
 *              row marked with its side
 *   totals     per kind of document: how many, and their amount (a reversed
 *              or cancelled document is counted but not added)
 *   timeline   one running NET from our side (positive = they owe us):
 *              linked firm -> PartyStatement (both accounts + our advance)
 *              supplier    -> SupplierStatement + our advance with them (1160)
 *              customer    -> CustomerStatement
 *
 * ⚠ The timeline is shown to whoever may read the page's own side; a linked
 * firm's combined timeline only to a reader of both sides (PartyCard's rule).
 * A reader of one side never sees the other side's documents either.
 *
 * ⚠ The window defaults to all time; a statement is asked from 1900 so its
 * opening is zero and every line since the beginning is in it.
 */
final class PartyLifetime
{
    /** Statuses whose amount is not added into a type's total. */
    private const NOT_ADDED = ['reversed', 'cancelled', 'void', 'withdrawn', 'draft', 'rejected'];

    private function __construct(
        private readonly ?Supplier $supplier,
        private readonly ?Customer $customer,
        private readonly string $side,
        private readonly ?User $viewer,
        private readonly LifetimeRange $range,
    ) {}

    public static function forSupplier(Supplier $supplier, ?User $viewer, LifetimeRange $range): self
    {
        $customer = Customer::query()->where('supplier_id', $supplier->id)->first();

        return new self($supplier, $customer, 'supplier', $viewer, $range);
    }

    public static function forCustomer(Customer $customer, ?User $viewer, LifetimeRange $range): self
    {
        $supplier = $customer->supplier_id === null ? null : Supplier::query()->find($customer->supplier_id);

        return new self($supplier, $customer, 'customer', $viewer, $range);
    }

    /** Both sides are one firm, and this reader may read both. */
    public function combined(): bool
    {
        return $this->supplier !== null && $this->customer !== null
            && $this->sees('purchasing.reports.view') && $this->sees('sales.reports.view');
    }

    /**
     * Every document, both sides when combined, in the window, newest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function documents(): Collection
    {
        $own = $this->side === 'supplier'
            ? PartyDocuments::forSupplier($this->supplier, $this->viewer)->map(fn (array $row) => $row + ['side' => 'supplier'])
            : PartyDocuments::forCustomer($this->customer, $this->viewer)->map(fn (array $row) => $row + ['side' => 'customer']);

        if ($this->combined()) {
            $other = $this->side === 'supplier'
                ? PartyDocuments::forCustomer($this->customer, $this->viewer)->map(fn (array $row) => $row + ['side' => 'customer'])
                : PartyDocuments::forSupplier($this->supplier, $this->viewer)->map(fn (array $row) => $row + ['side' => 'supplier']);

            // A set-off and a voucher can name both sides: keep one row.
            $own = $own->concat($other)->unique('key');
        }

        return $own
            ->filter(fn (array $row) => $this->range->contains($row['date'] ?? null))
            ->sortBy([
                fn (array $a, array $b) => strcmp((string) $b['date'], (string) $a['date']),
                fn (array $a, array $b) => $b['id'] <=> $a['id'],
            ])
            ->values();
    }

    /**
     * Per kind of document: how many, and the sum of their amounts.
     *
     * @param  Collection<int, array<string, mixed>>  $documents
     * @return list<array{type: string, label: string, side: string, count: int, amount: ?string}>
     */
    public static function totals(Collection $documents): array
    {
        return $documents
            ->groupBy(fn (array $row) => $row['side'].'|'.$row['type'])
            ->map(function (Collection $rows) {
                $first = $rows->first();
                $added = $rows->filter(fn (array $row) => $row['amount'] !== null && ! in_array($row['status'], self::NOT_ADDED, true));

                return [
                    'type' => $first['type'],
                    'label' => DocumentLinks::labelFor($first['type']),
                    'side' => $first['side'],
                    'count' => $rows->count(),
                    'amount' => $added->isEmpty() ? null : Money::sum($added->map(fn (array $row) => Money::ofTaka((string) $row['amount'])))->absolute()->format(),
                ];
            })
            ->sortBy(fn (array $row) => [$row['side'], array_search($row['type'], [...DocumentLinks::types(), 'quotation'], true)])
            ->values()
            ->all();
    }

    /**
     * The running net from our side, null for a reader who may not see it.
     *
     * @return array{opening: array<string, string>, closing: array<string, string>, lines: list<array<string, mixed>>, combined: bool}|null
     */
    public function timeline(): ?array
    {
        if (! $this->sees($this->side === 'supplier' ? 'purchasing.reports.view' : 'sales.reports.view')) {
            return null;
        }

        $from = $this->range->statementFrom();
        $to = $this->range->statementTo();

        if ($this->combined()) {
            $statement = PartyStatement::for($this->supplier, $this->customer, $from, $to);

            return $this->shape($statement->openingNet(), $statement->lines->map(fn (array $line) => [
                'date' => $line['date'],
                'side' => $line['side'],
                'kind' => $line['kind'],
                'label' => $line['label'],
                'reference' => $line['reference'],
                'effect' => $line['effect'],
            ]), true);
        }

        if ($this->side === 'supplier') {
            $statement = SupplierStatement::for($this->supplier, $from, $to);
            [$advanceOpening, $advanceLines] = PartyStatement::advances($this->supplier, $from, $to);

            // We owe them = minus; our advance with them = plus (PartyStatement's sign).
            $lines = collect()
                ->concat($statement->lines->values()->map(fn (CustomerStatementLine $line, int $i) => [
                    'date' => $line->date, 'rank' => 1, 'order' => $i, 'side' => 'supplier', 'kind' => $line->kind,
                    'label' => $line->label, 'reference' => $line->reference, 'effect' => $line->amount->negated(),
                ]))
                ->concat($advanceLines->values()->map(fn (array $line, int $i) => [
                    'date' => $line['date'], 'rank' => 2, 'order' => $i, 'side' => 'advance', 'kind' => $line['kind'],
                    'label' => $line['label'], 'reference' => $line['reference'], 'effect' => $line['effect'],
                ]));

            return $this->shape(Money::ofMinor($advanceOpening->minor - $statement->opening->minor), self::ordered($lines), false);
        }

        $statement = CustomerStatement::for($this->customer, $from, $to);

        return $this->shape($statement->opening, $statement->lines->values()->map(fn (CustomerStatementLine $line) => [
            'date' => $line->date, 'side' => 'customer', 'kind' => $line->kind,
            'label' => $line->label, 'reference' => $line->reference, 'effect' => $line->amount,
        ]), false);
    }

    /**
     * @param  Collection<int, array<string, mixed>>|null  $documents  already read for the documents panel
     * @return array<string, mixed> the props a 360 page receives
     */
    public function props(?Collection $documents = null): array
    {
        $documents ??= $this->documents();

        return [
            'range' => $this->range->toArray(),
            'combined' => $this->combined(),
            'other' => $this->combined()
                ? ($this->side === 'supplier'
                    ? ['side' => 'customer', 'id' => $this->customer->id, 'name' => $this->customer->name]
                    : ['side' => 'supplier', 'id' => $this->supplier->id, 'name' => $this->supplier->name])
                : null,
            'totals' => self::totals($documents),
            'timeline' => $this->timeline(),
        ];
    }

    /**
     * The spreadsheet: the running net first (when the reader may see it),
     * then every document. One file, two blocks, each with its own heading row.
     */
    public function export(string $name): StreamedResponse
    {
        $rows = [];
        $timeline = $this->timeline();

        if ($timeline !== null) {
            $rows[] = ['Running net', '', '', '', '', '', ''];
            $rows[] = ['Date', 'Side', 'What', 'Reference', 'Change (+ they owe us more)', 'Net', 'Net side'];
            $rows[] = ['', '', 'Opening', '', '', $timeline['opening']['net'], self::sideWords($timeline['opening']['net_side'])];

            foreach ($timeline['lines'] as $line) {
                $rows[] = [$line['date'], $line['side'], $line['label'], $line['reference'], $line['change'], $line['net'], self::sideWords($line['net_side'])];
            }

            $rows[] = ['', '', '', '', '', '', ''];
        }

        $rows[] = ['Documents', '', '', '', '', '', ''];
        $rows[] = ['Date', 'Side', 'Document', 'Number', 'Amount', 'Status', 'Note'];

        foreach ($this->documents() as $document) {
            $rows[] = [$document['date'], $document['side'], $document['label'], $document['number'], $document['amount'], $document['status'], $document['note']];
        }

        $header = array_shift($rows);

        return LifetimeCsv::download($name, $header, $rows);
    }

    private static function sideWords(string $side): string
    {
        return match ($side) {
            'us' => 'they owe us',
            'them' => 'we owe them',
            default => 'even',
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function shape(Money $opening, Collection $lines, bool $combined): array
    {
        $net = $opening;

        $rows = $lines->map(function (array $line) use (&$net) {
            $net = $net->plus($line['effect']);

            return [
                'date' => $line['date'] instanceof Carbon ? $line['date']->toDateString() : (string) $line['date'],
                'side' => $line['side'],
                'kind' => $line['kind'],
                'label' => $line['label'],
                'reference' => $line['reference'],
                'change' => $line['effect']->format(),
                ...self::signed($net),
            ];
        })->values()->all();

        return [
            'opening' => self::signed($opening),
            'closing' => self::signed($net),
            'lines' => $rows,
            'combined' => $combined,
        ];
    }

    /** @return array{net: string, net_side: string} "us" = they owe us, "them" = we owe them */
    private static function signed(Money $net): array
    {
        return ['net' => $net->absolute()->format(), 'net_side' => $net->minor > 0 ? 'us' : ($net->minor < 0 ? 'them' : 'even')];
    }

    /**
     * Same day: supplier lines, then advance lines, each in its own order
     * (PartyStatement's rule, so two readers always see the same running net).
     *
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return Collection<int, array<string, mixed>>
     */
    private static function ordered(Collection $lines): Collection
    {
        return $lines
            ->sortBy([
                fn (array $a, array $b) => strcmp($a['date']->toDateString(), $b['date']->toDateString()),
                ['rank', 'asc'],
                ['order', 'asc'],
            ])
            ->values();
    }

    private function sees(string $ability): bool
    {
        return $this->viewer === null || $this->viewer->can($ability);
    }
}
