<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Actions\RecordSslSettlement;
use App\Modules\Accounting\Corrections\SslSettlementActionPolicy;
use App\Modules\Accounting\Corrections\SslSettlementDuplicateRule;
use App\Modules\Accounting\Models\SslSettlement;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\SslClearing;
use App\Modules\Accounting\Support\SslCommerzReport;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Foundation\Corrections\DuplicateGuard;
use App\Modules\Foundation\Corrections\Exceptions\PossibleDuplicateException;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Support\CorrectionScreen;
use App\Support\PerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ⭐ Joy's answer 9 (25 Oct 2026): Accounts > Bank & cash > SSL settlements
 * (record SSL's payout in a popup: date first, gross, SSL charge, the bank,
 * the net worked out; Reverse through the corrections engine) and Accounts >
 * Reports > SSL Commerz (gross received, fee taken, net to bank, still in
 * clearing, by period, Excel).
 *
 * ⚠ Every rule lives in RecordSslSettlement; this validates shape, asks the
 * duplicate question and hands over.
 */
class SslSettlementController extends Controller
{
    public function index(Request $request): Response
    {
        $showCorrections = $request->boolean('show_corrections');
        $policy = app(SslSettlementActionPolicy::class);
        $user = $request->user();
        $businessId = (int) app(BusinessContext::class)->id();
        $clearing = SslClearing::for($businessId);

        $settlements = SslSettlement::query()
            ->with(['bank:id,name', 'feeAccount:id,code,name', 'reversedBy:id,name', 'attachments'])
            ->when(! $showCorrections, fn ($query) => $query->live())
            ->orderByDesc('settled_on')->orderByDesc('id')
            ->paginate(PerPage::from())
            ->withQueryString()
            ->through(fn (SslSettlement $s) => [
                'id' => $s->id,
                'number' => $s->number,
                'settled_on' => $s->settled_on->toDateString(),
                'bank' => $s->bank?->name,
                'gross' => $s->gross,
                'fee' => $s->fee,
                'net' => $s->net,
                'fee_account' => $s->feeAccount?->label(),
                'reference' => $s->reference,
                'note' => $s->note,
                'evidence' => $s->attachments->map(fn (Attachment $file) => [
                    'id' => $file->id,
                    'name' => $file->original_name ?? $file->file_name,
                    'url' => route('attachments.show', $file->id),
                ])->values(),
                'reversal' => $s->reversalSummary(),
                'allow_today' => ! AccountingPeriod::isClosedOn($s->settled_on->copy()->startOfDay()),
                'corrections' => CorrectionScreen::for($s, $policy, $user),
            ]);

        return Inertia::render('accounting/ssl-settlements/index', [
            'settlements' => $settlements,
            'showCorrections' => $showCorrections,
            'today' => now()->toDateString(),
            'clearing' => $clearing === null ? null : [
                'id' => $clearing->id,
                'name' => $clearing->name,
                'balance' => Money::ofMinor((int) DB::table('journal_lines')->where('business_id', $businessId)->where('money_account_id', $clearing->id)->sum('amount_minor'))->toTaka(),
            ],
            // Where SSL's money lands: our own bank or wallet accounts with a ledger.
            'banks' => MoneyAccount::query()->handled()->withLedger()->where('is_active', true)
                ->whereIn('type', ['bank', 'mfs'])
                ->orderBy('name')->get(['id', 'name', 'type']),
        ]);
    }

    public function store(Request $request, RecordSslSettlement $action, StoreAttachment $attach): RedirectResponse
    {
        $data = $request->validate([
            'settled_on' => ['required', 'date_format:Y-m-d'],
            'bank_money_account_id' => ['required', 'integer'],
            'gross' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'fee' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
            'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp,csv,xls,xlsx', 'max:5120'],
            'duplicate_ack' => ['nullable', 'string', 'max:2000'],
        ], [
            'gross.regex' => 'Type the gross as a plain number, like 10000 or 10000.50.',
            'fee.regex' => 'Type the SSL charge as a plain number, like 250 or 250.50.',
        ]);

        $guard = app(DuplicateGuard::class);
        $fingerprint = collect($data)->except(['file', 'duplicate_ack'])->all();

        try {
            $settlement = DB::transaction(function () use ($data, $request, $action, $attach, $guard, $fingerprint) {
                $ack = $guard->check(app(SslSettlementDuplicateRule::class), $fingerprint, $request->user(), (int) app(BusinessContext::class)->id(), $data['duplicate_ack'] ?? null);

                $settlement = $action->handle(
                    bank: MoneyAccount::query()->findOrFail($data['bank_money_account_id']),
                    gross: (string) $data['gross'],
                    fee: (string) ($data['fee'] ?? '0'),
                    settledOn: $data['settled_on'],
                    createdBy: $request->user(),
                    reference: $data['reference'] ?? null,
                    note: $data['note'] ?? null,
                );

                if ($request->hasFile('file')) {
                    $attach->handle($settlement, $request->file('file'), SslSettlement::EVIDENCE_COLLECTION, $request->user());
                }

                if ($ack !== null) {
                    $guard->record($ack, $settlement);
                }

                return $settlement;
            });
        } catch (PossibleDuplicateException $e) {
            $request->session()->flash('duplicateWarning', $e->warning->toArray());

            throw ValidationException::withMessages(['_duplicate' => 'Possible duplicate. Is this a different SSL payout?']);
        }

        return back()->with('success', "Recorded SSL settlement {$settlement->number}: ".Money::ofTaka((string) $settlement->net)->format()." into {$settlement->bank?->name}.");
    }

    public function report(Request $request): Response
    {
        [$from, $to] = $this->period($request);

        return Inertia::render('accounting/ssl-commerz-report', [
            'report' => SslCommerzReport::for((int) app(BusinessContext::class)->id(), $from, $to),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        [$from, $to] = $this->period($request);
        $report = SslCommerzReport::for((int) app(BusinessContext::class)->id(), $from, $to);
        $taka = fn (int $minor) => (float) Money::ofMinor($minor)->toTaka();

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('SSL Commerz');
        $sheet->fromArray([
            ['SSL Commerz', $report['from'].' to '.$report['to']],
            [],
            ['In clearing at the start', $taka($report['opening'])],
            ['Gross received (paid online)', $taka($report['received'])],
            ['Settled by SSL (gross)', $taka($report['settled_gross'])],
            ['SSL charge (fee taken)', $taka($report['fee'])],
            ['Net to bank', $taka($report['net'])],
            ['Still in clearing at the end', $taka($report['closing'])],
            [],
            ['Settlement', 'Date', 'Bank', 'Reference', 'Gross', 'SSL charge', 'Net'],
        ]);

        $row = 11;

        foreach ($report['settlements'] as $s) {
            $sheet->fromArray([[$s['number'], $s['settled_on'], $s['bank'], $s['reference'], (float) $s['gross'], (float) $s['fee'], (float) $s['net']]], null, "A{$row}");
            $row++;
        }

        $name = "ssl-commerz-{$report['from']}-to-{$report['to']}.xlsx";

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** @return array{0: Carbon, 1: Carbon} the period, this month by default */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return [
            isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : Carbon::today()->startOfMonth(),
            isset($data['to']) ? Carbon::parse($data['to'])->startOfDay() : Carbon::today(),
        ];
    }
}
