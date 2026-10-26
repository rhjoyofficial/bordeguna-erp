<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\PartyBalances;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ⭐ Who owes what (Joy, 26 Oct 2026): every customer, supplier, firm that is
 * both, and courier on ONE page, each with what they owe us, what we owe them
 * and the net. See PartyBalances for the arithmetic.
 *
 * ⚠ Read by whoever reads both suppliers and customers (the route gate), the
 * same gate as the combined party statement.
 */
class WhoOwesWhatController extends Controller
{
    private const SIDES = ['all', 'owe_us', 'we_owe'];

    private const KINDS = ['all', 'customer', 'supplier', 'both', 'courier'];

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $all = PartyBalances::rows($request->user());
        $totals = PartyBalances::totals($all);
        $rows = $this->filtered($all, $filters);
        $taka = fn (int $paisa) => Money::ofMinor($paisa)->format();

        return Inertia::render('accounting/who-owes-what/index', [
            'filters' => $filters,
            'totals' => [
                'they_owe' => $taka($totals['they_owe']),
                'we_owe' => $taka($totals['we_owe']),
                'net' => Money::ofMinor(abs($totals['net']))->format(),
                'net_side' => $totals['net'] > 0 ? 'us' : ($totals['net'] < 0 ? 'them' : 'even'),
                'owe_us_count' => $totals['owe_us_count'],
                'we_owe_count' => $totals['we_owe_count'],
            ],
            'rows' => $rows->map(fn (array $row) => [
                'key' => $row['key'],
                'kind' => $row['kind'],
                'name' => $row['name'],
                'phone' => $row['phone'],
                'customer_id' => $row['customer_id'],
                'supplier_id' => $row['supplier_id'],
                'they_owe' => $row['they_owe'] === 0 ? null : $taka($row['they_owe']),
                'our_advance' => $row['our_advance'] === 0 ? null : $taka($row['our_advance']),
                'we_owe' => $row['we_owe'] === 0 ? null : $taka($row['we_owe']),
                'their_advance' => $row['their_advance'] === 0 ? null : $taka($row['their_advance']),
                'net' => Money::ofMinor(abs($row['net']))->format(),
                'net_side' => $row['net'] > 0 ? 'us' : ($row['net'] < 0 ? 'them' : 'even'),
            ])->values(),
            'shown' => $rows->count(),
            'total' => $all->count(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $user = $request->user();

        return response()->streamDownload(function () use ($filters, $user) {
            // ⚠ Read INSIDE the closure, while the request's tenant scope holds.
            $rows = $this->filtered(PartyBalances::rows($user), $filters);
            $out = fopen('php://output', 'w');
            // Excel reads a UTF-8 CSV as ANSI without the byte order mark.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Name', 'Kind', 'Phone', 'They owe us (invoices)', 'Our advance with them', 'We owe them (bills)', 'Their advance with us', 'Net', 'Net means']);
            $plain = fn (int $paisa) => number_format($paisa / 100, 2, '.', '');

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['name'], ucfirst($row['kind']), $row['phone'],
                    $plain($row['they_owe']), $plain($row['our_advance']), $plain($row['we_owe']), $plain($row['their_advance']),
                    $plain(abs($row['net'])),
                    $row['net'] > 0 ? 'They owe us' : ($row['net'] < 0 ? 'We owe them' : 'Even'),
                ]);
            }

            fclose($out);
        }, 'who-owes-what-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{side: string, kind: string, q: string} */
    private function filters(Request $request): array
    {
        $side = $request->string('side')->toString();
        $kind = $request->string('kind')->toString();

        return [
            'side' => in_array($side, self::SIDES, true) ? $side : 'all',
            'kind' => in_array($kind, self::KINDS, true) ? $kind : 'all',
            'q' => trim($request->string('q')->toString()),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function filtered(Collection $rows, array $filters): Collection
    {
        $needle = mb_strtolower($filters['q']);

        return $rows
            ->when($filters['side'] === 'owe_us', fn ($c) => $c->where('net', '>', 0))
            ->when($filters['side'] === 'we_owe', fn ($c) => $c->where('net', '<', 0))
            ->when($filters['kind'] !== 'all', fn ($c) => $c->where('kind', $filters['kind']))
            ->when($needle !== '', fn ($c) => $c->filter(fn (array $row) => str_contains(mb_strtolower($row['name']), $needle)
                || str_contains((string) $row['phone'], $needle)))
            ->values();
    }
}
