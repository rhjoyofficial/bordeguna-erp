<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Sales\Actions\ImportCourierStatement;
use App\Modules\Sales\Actions\PostCourierStatement;
use App\Modules\Sales\Models\CourierStatement;
use App\Modules\Sales\Models\CourierStatementLine;
use App\Modules\Sales\Support\CourierStatements\CarryBeeStatementFormat;
use App\Modules\Sales\Support\CourierStatements\CourierBalances;
use App\Modules\Sales\Support\CourierStatements\CourierStatementFormats;
use App\Modules\Sales\Support\CourierStatements\CourierStatementReview;
use App\Modules\Sales\Support\CourierStatements\CourierStatementWorkbook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Courier payout statements: import the courier's file, decide what the ERP
 * cannot place, enter the money that arrived, post (courier slice C2, v2 11 Oct
 * 2026: CarryBee, row errors, the original file, the exception workbook).
 */
class CourierStatementController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('sales/courier-statements/index', [
            'statements' => CourierStatement::query()
                ->with('courier:id,name')
                ->withCount('lines')
                ->orderByDesc('statement_date')
                ->orderByDesc('id')
                ->paginate(25)
                ->through(fn (CourierStatement $statement) => [
                    'id' => $statement->id,
                    'courier' => $statement->courier?->name,
                    'statement_reference' => $statement->statement_reference,
                    'statement_date' => $statement->statement_date->toDateString(),
                    'status' => $statement->status,
                    'lines_count' => $statement->lines_count,
                    'errors_count' => count($statement->rowErrors()),
                    'share_amount' => $statement->share_amount === null ? null : number_format((float) $statement->share_amount, 2, '.', ''),
                ]),
            'couriers' => MoneyAccount::query()->active()->where('type', MoneyAccountType::Courier->value)
                ->orderBy('name')->get(['id', 'name', 'provider', 'business_id'])
                ->map(function (MoneyAccount $courier) {
                    $format = CourierStatementFormats::for($courier);

                    return [
                        'id' => $courier->id,
                        'name' => $courier->name,
                        'provider' => CourierStatementFormats::provider($courier),
                        'format' => $format?->name(),
                        // CarryBee's file carries its own invoice id; Pathao's does not.
                        'reference_in_file' => $format instanceof CarryBeeStatementFormat,
                    ];
                })->values(),
        ]);
    }

    public function store(Request $request, ImportCourierStatement $action): RedirectResponse
    {
        $this->authorize('sales.receipt.record');

        $data = $request->validate([
            'money_account_id' => ['required', 'integer', 'exists:money_accounts,id'],
            'statement_reference' => ['nullable', 'string', 'max:100'],
            'statement_date' => ['required', 'date', 'before_or_equal:today'],
            'file' => ['required', 'file', 'max:2048', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel'],
        ]);

        $file = $request->file('file');

        $statement = $action->handle(
            courier: MoneyAccount::query()->findOrFail($data['money_account_id']),
            reference: $data['statement_reference'] ?? null,
            statementDate: $data['statement_date'],
            contents: (string) file_get_contents($file->getRealPath()),
            filename: $file->getClientOriginalName(),
            createdBy: $request->user(),
        );

        $errors = count($statement->rowErrors());

        return redirect()->route('sales.courier-statements.show', $statement)
            ->with($errors === 0 ? 'success' : 'warning', "Statement {$statement->statement_reference} read: {$statement->lines->count()} parcels"
                .($errors === 0 ? '.' : ", {$errors} ".($errors === 1 ? 'row' : 'rows').' could not be read.'));
    }

    public function show(Request $request, CourierStatement $courierStatement): Response
    {
        $review = CourierStatementReview::of($courierStatement->load('courier:id,name,provider,account_id,business_id,type', 'receivedInto:id,name'));

        $journal = $courierStatement->isDraft() ? null : Journal::query()
            ->where('source_type', $courierStatement->getMorphClass())
            ->where('source_id', $courierStatement->id)
            ->whereNull('reverses_journal_id')
            ->with('lines.account:id,code,name', 'lines.moneyAccount:id,name')
            ->first();

        $proof = $courierStatement->attachmentInCollection(CourierStatement::PAYOUT_PROOF);
        $openPayable = $courierStatement->courier ? CourierBalances::payable($courierStatement->courier) : Money::zero();
        $money = fn (?string $value) => $value === null ? null : number_format((float) $value, 2, '.', '');

        return Inertia::render('sales/courier-statements/show', [
            'statement' => [
                'id' => $courierStatement->id,
                'courier' => $courierStatement->courier?->name,
                'provider' => $review->provider,
                'format' => CourierStatementFormats::forProvider($review->provider)?->name(),
                'statement_reference' => $courierStatement->statement_reference,
                'statement_date' => $courierStatement->statement_date->toDateString(),
                'status' => $courierStatement->status,
                'source_filename' => $courierStatement->source_filename,
                'has_source' => $courierStatement->source_path !== null,
                'received_into' => $courierStatement->receivedInto?->name,
                'deposit_amount' => $money($courierStatement->deposit_amount),
                'received_on' => $courierStatement->received_on?->toDateString(),
                'bank_reference' => $courierStatement->bank_reference,
                'deposit_difference_reason' => $courierStatement->deposit_difference_reason,
                'payable_settled_amount' => $money($courierStatement->payable_settled_amount),
                'proof' => $proof === null ? null : ['name' => $proof->original_name, 'url' => $proof->url()],
            ],
            'lines' => $review->rows->map(fn (array $row) => [
                'id' => $row['line']->id,
                'row_number' => $row['line']->row_number,
                'consignment_number' => $row['line']->consignment_number,
                'merchant_reference' => $row['line']->merchant_reference,
                'state' => $row['state'],
                'resolution' => $row['line']->resolution,
                'included' => $row['included'],
                'problem' => $row['problem'],
                'note' => $row['note'],
                'suggestion' => $row['suggestion'],
                'match_method' => $row['match_method'],
                'erp_consignment' => $row['consignment']?->consignment_number,
                'consignment_status' => $row['consignment']?->status->label(),
                'courier_status' => $row['line']->delivery_status,
                'hub_weight_grams' => $row['line']->hub_weight_grams,
                'collected' => $row['line']->collected()->toTaka(),
                'erp_collected' => $row['consignment']?->collectedAmount()?->toTaka(),
                'delivery_fee' => Money::ofTaka((string) $row['line']->delivery_fee)->toTaka(),
                'discount' => Money::ofTaka((string) $row['line']->discount)->toTaka(),
                'charge' => CourierStatementReview::carriage($row['line'])->toTaka(),
                'cod_fee' => $row['line']->codFee()->toTaka(),
                'additional' => $row['line']->additionalCharge()->toTaka(),
                'compensation' => $row['line']->compensationAmount()->toTaka(),
                'adjustment' => $row['line']->adjustmentAmount()->toTaka(),
                'payout' => $row['line']->payoutAmount()->toTaka(),
            ])->values(),
            'rowErrors' => $courierStatement->rowErrors(),
            'summary' => [
                'file_total' => $review->fileTotal->toTaka(),
                'share' => $review->share->toTaka(),
                'collected' => $review->collected->toTaka(),
                'delivery_fees' => $review->deliveryFees->toTaka(),
                'return_fees' => $review->returnFees->toTaka(),
                'additional_charges' => $review->additionalCharges->toTaka(),
                'cod_fees' => $review->codFees->toTaka(),
                'compensation' => $review->compensation->toTaka(),
                'adjustment' => $review->adjustment->toTaka(),
                'open_payable' => $openPayable->toTaka(),
                'deposit_expected' => $review->fileTotal->minor > 0,
            ],
            'problems' => $review->problems,
            'can_post' => $courierStatement->isDraft() && $review->canPost() && $request->user()->can('sales.receipt.record'),
            'can_download_source' => $courierStatement->source_path !== null && $request->user()->can('sales.receipt.record'),
            'moneyAccounts' => MoneyAccount::query()->handled()->active()->whereNotNull('account_id')
                ->orderBy('name')->get(['id', 'name']),
            'journal' => $journal,
        ]);
    }

    public function resolve(Request $request, CourierStatement $courierStatement, CourierStatementLine $line): RedirectResponse
    {
        $this->authorize('sales.receipt.record');

        $data = $request->validate([
            'resolution' => ['nullable', Rule::in([CourierStatementLine::MINE, CourierStatementLine::NOT_MINE])],
        ]);

        if (! $courierStatement->isDraft() || $line->courier_statement_id !== $courierStatement->id) {
            throw ValidationException::withMessages(['resolution' => 'Only a line on a draft statement can be decided.']);
        }

        $line->forceFill(['resolution' => $data['resolution'] ?? null])->save();

        return back();
    }

    public function post(Request $request, CourierStatement $courierStatement, PostCourierStatement $action): RedirectResponse
    {
        $this->authorize('sales.receipt.record');

        $data = $request->validate([
            'received_money_account_id' => ['nullable', 'integer', 'exists:money_accounts,id'],
            'deposit_amount' => ['nullable', 'numeric'],
            'received_on' => ['nullable', 'date'],
            'settle_payable' => ['nullable', 'numeric', 'min:0'],
            'deposit_difference_reason' => ['nullable', 'string', 'max:500'],
            'bank_reference' => ['nullable', 'string', 'max:100'],
            'proof' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,webp'],
        ]);

        try {
            $action->handle(
                statement: $courierStatement,
                receivedInto: isset($data['received_money_account_id']) ? MoneyAccount::query()->findOrFail($data['received_money_account_id']) : null,
                depositAmount: isset($data['deposit_amount']) ? (string) $data['deposit_amount'] : null,
                receivedOn: $data['received_on'] ?? null,
                postedBy: $request->user(),
                settlePayable: isset($data['settle_payable']) ? (string) $data['settle_payable'] : null,
                differenceReason: $data['deposit_difference_reason'] ?? null,
                bankReference: $data['bank_reference'] ?? null,
                proof: $request->file('proof'),
            );
        } catch (PeriodClosedException|MappingNotFound $e) {
            throw ValidationException::withMessages(['statement' => $e->getMessage()]);
        }

        return back()->with('success', "Statement {$courierStatement->statement_reference} posted.");
    }

    /** The rows the file could not be read on, as a CSV. No customer data. */
    public function errors(CourierStatement $courierStatement): StreamedResponse
    {
        $errors = $courierStatement->rowErrors();

        return response()->streamDownload(function () use ($errors) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Row', 'Consignment', 'Problem']);

            foreach ($errors as $error) {
                // ⚠ A leading = + - @ would be a formula in a spreadsheet.
                fputcsv($out, array_map(fn ($value) => preg_match('/^[=+\-@]/', (string) $value) ? "'".$value : $value, [$error['row'], $error['consignment'] ?? '', $error['message']]));
            }

            fclose($out);
        }, 'courier-statement-'.preg_replace('/[^A-Za-z0-9_-]/', '-', $courierStatement->statement_reference).'-errors.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Summary, Matched, Differences, Unmatched in file, Missing from file, Errors. */
    public function workbook(CourierStatement $courierStatement): BinaryFileResponse
    {
        return response()->download(
            CourierStatementWorkbook::write($courierStatement->load('lines', 'courier')),
            CourierStatementWorkbook::filename($courierStatement),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend();
    }

    /**
     * The courier's original file, exactly as uploaded.
     *
     * ⚠ It carries customer names and phones, so it needs the right to import
     * and post (sales.receipt.record), not only to view.
     */
    public function source(CourierStatement $courierStatement): StreamedResponse
    {
        $this->authorize('sales.receipt.record');

        $disk = Storage::disk(CourierStatement::SOURCE_DISK);
        abort_if($courierStatement->source_path === null || ! $disk->exists($courierStatement->source_path), 404);

        $response = $disk->download($courierStatement->source_path, $courierStatement->source_filename ?: 'courier-statement.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    public function destroy(CourierStatement $courierStatement): RedirectResponse
    {
        $this->authorize('sales.receipt.record');

        if (! $courierStatement->isDraft()) {
            throw ValidationException::withMessages(['statement' => 'A posted statement stays; it is the record of money that moved.']);
        }

        $path = $courierStatement->source_path;
        $courierStatement->delete();

        // A discarded draft is not evidence of anything; its file goes with it.
        if ($path !== null) {
            Storage::disk(CourierStatement::SOURCE_DISK)->delete($path);
        }

        return redirect()->route('sales.courier-statements.index')->with('success', 'Draft statement discarded.');
    }
}
