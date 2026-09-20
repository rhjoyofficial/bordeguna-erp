<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Actions\FileVatReturn;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\VatReturn;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\VatReturnBuilder;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Accounting > VAT return: one month at a time. The sales register, the
 * purchase register, what the ledger says, what is owed, and the button that
 * files it. Reading is for anyone who may see the books; filing is the Owner's.
 */
class VatReturnController extends Controller
{
    public function index(Request $request, VatReturnBuilder $builder): Response
    {
        [$start, $end] = $this->period($request->query('period'));
        $business = $this->business();

        $filed = VatReturn::query()->whereDate('period_start', $start)->whereDate('period_end', $end)->with('filedBy:id,name')->first();
        $figures = $builder->build($business, $start, $end);

        return Inertia::render('accounting/vat-return/index', [
            'period' => $start->format('Y-m'),
            'period_label' => $start->format('F Y'),
            'figures' => $this->present($figures),
            'filed' => $filed === null ? null : [
                'return_number' => $filed->return_number,
                'filed_at' => $filed->filed_at?->format('j M Y'),
                'filed_by' => $filed->filedBy?->name,
                'output_tax' => Money::ofMinor((int) $filed->output_tax_minor)->toTaka(),
                'input_tax' => Money::ofMinor((int) $filed->input_tax_minor)->toTaka(),
                'net_payable' => Money::ofMinor((int) $filed->net_payable_minor)->toTaka(),
                'credit_carried_forward' => Money::ofMinor((int) $filed->credit_carried_forward_minor)->toTaka(),
                'journal_id' => $filed->journal_id,
            ],
            'can_file' => $request->user()->can('accounting.chart.manage')
                && $filed === null
                && $figures['regime'] !== 'not_registered'
                && $end->lessThan(Carbon::today()),
            'history' => VatReturn::query()->orderByDesc('period_start')->limit(24)->get()->map(fn (VatReturn $return) => [
                'return_number' => $return->return_number,
                'period' => $return->period_start->format('Y-m'),
                'period_label' => $return->period_start->format('F Y'),
                'net_payable' => Money::ofMinor((int) $return->net_payable_minor)->toTaka(),
                'credit_carried_forward' => Money::ofMinor((int) $return->credit_carried_forward_minor)->toTaka(),
            ])->all(),
        ]);
    }

    public function store(Request $request, FileVatReturn $action): RedirectResponse
    {
        $data = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        [$start, $end] = $this->period($data['period']);

        try {
            $return = $action->handle($this->business(), $start, $end, $request->user(), $data['note'] ?? null);
        } catch (PeriodClosedException|MappingNotFound $e) {
            throw ValidationException::withMessages(['period' => $e->getMessage()]);
        }

        $net = $return->netPayable();

        return redirect()->route('accounting.vat-return.index', ['period' => $data['period']])->with('success', $net->isZero()
            ? "{$return->return_number} filed. Nothing is payable for this period."
            : "{$return->return_number} filed. {$net->format()} is owed; pay it with a payment voucher against VAT Settlement.");
    }

    public function export(Request $request, VatReturnBuilder $builder): StreamedResponse
    {
        [$start, $end] = $this->period($request->query('period'));
        $figures = $builder->build($this->business(), $start, $end);
        $taka = fn (int $minor) => Money::ofMinor($minor)->toTaka();

        return response()->streamDownload(function () use ($figures, $taka) {
            $out = fopen('php://output', 'w');
            // A BOM so Excel reads Bengali party names correctly.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['Sales register', $figures['period_start'], $figures['period_end']]);
            fputcsv($out, ['Date', 'Document', 'Number', 'Customer', 'Value excluding VAT', 'VAT', 'Total']);

            foreach ($figures['sales'] as $row) {
                fputcsv($out, [$row['date'], $row['kind'], $row['number'], $row['party'], $taka($row['value_minor']), $taka($row['tax_minor']), $taka($row['total_minor'])]);
            }

            fputcsv($out, []);
            fputcsv($out, ['Purchase register', $figures['period_start'], $figures['period_end']]);
            fputcsv($out, ['Date', 'Document', 'Number', 'Supplier', 'Value excluding VAT', 'VAT', 'VAT claimed', 'Total']);

            foreach ($figures['purchases'] as $row) {
                fputcsv($out, [$row['date'], $row['kind'], $row['number'], $row['party'], $taka($row['value_minor']), $taka($row['tax_minor']), $taka($row['claimed_minor']), $taka($row['total_minor'])]);
            }

            fputcsv($out, []);
            fputcsv($out, ['Output VAT', $taka($figures['output_tax_minor'])]);
            fputcsv($out, ['Input VAT claimed', $taka($figures['input_tax_minor'])]);
            fputcsv($out, ['Turnover', $taka($figures['turnover_minor'])]);
            fputcsv($out, ['Turnover tax', $taka($figures['turnover_tax_minor'])]);
            fputcsv($out, ['Credit brought forward', $taka($figures['credit_brought_forward_minor'])]);
            fputcsv($out, ['Net payable', $taka($figures['net_payable_minor'])]);
            fputcsv($out, ['Credit carried forward', $taka($figures['credit_carried_forward_minor'])]);

            fclose($out);
        }, 'vat-return-'.$start->format('Y-m').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(mixed $period): array
    {
        // The month just ended is the one somebody comes here to file.
        $start = is_string($period) && preg_match('/^\d{4}-\d{2}$/', $period) === 1
            ? Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfDay()
            : Carbon::today()->subMonthNoOverflow()->startOfMonth();

        return [$start, $start->copy()->endOfMonth()->startOfDay()];
    }

    private function business(): Business
    {
        return Business::query()->findOrFail(app(BusinessContext::class)->id());
    }

    /**
     * Paisa to taka strings for the screen, which never does arithmetic.
     *
     * @param  array<string, mixed>  $figures
     * @return array<string, mixed>
     */
    private function present(array $figures): array
    {
        $taka = fn (int $minor) => Money::ofMinor($minor)->toTaka();
        $rows = fn (array $list) => array_map(fn (array $row) => [
            'date' => $row['date'], 'kind' => $row['kind'], 'number' => $row['number'], 'party' => $row['party'],
            'value' => $taka($row['value_minor']), 'tax' => $taka($row['tax_minor']),
            'claimed' => isset($row['claimed_minor']) ? $taka($row['claimed_minor']) : null,
            'total' => $taka($row['total_minor']), 'url' => $row['url'],
        ], $list);

        return [
            'regime' => $figures['regime'],
            'regime_label' => $figures['regime_label'],
            'sales' => $rows($figures['sales']),
            'purchases' => $rows($figures['purchases']),
            'output_tax' => $taka($figures['output_tax_minor']),
            'input_tax' => $taka($figures['input_tax_minor']),
            'document_output' => $taka($figures['document_output_minor']),
            'document_input' => $taka($figures['document_input_minor']),
            'agrees' => $figures['agrees'],
            'turnover' => $taka($figures['turnover_minor']),
            'turnover_tax_rate' => rtrim(rtrim($figures['turnover_tax_rate'], '0'), '.'),
            'turnover_tax' => $taka($figures['turnover_tax_minor']),
            'credit_brought_forward' => $taka($figures['credit_brought_forward_minor']),
            'net_payable' => $taka($figures['net_payable_minor']),
            'credit_carried_forward' => $taka($figures['credit_carried_forward_minor']),
        ];
    }
}
