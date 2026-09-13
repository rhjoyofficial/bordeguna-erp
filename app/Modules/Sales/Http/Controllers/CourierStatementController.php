<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Sales\Actions\ImportCourierStatement;
use App\Modules\Sales\Actions\PostCourierStatement;
use App\Modules\Sales\Models\CourierStatement;
use App\Modules\Sales\Models\CourierStatementLine;
use App\Modules\Sales\Support\CourierStatements\CourierStatementReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Courier payout statements: import the courier's file, decide what the ERP
 * cannot place, enter the money that arrived, post (courier slice C2).
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
                    'share_amount' => $statement->share_amount === null ? null : number_format((float) $statement->share_amount, 2, '.', ''),
                ]),
            'couriers' => MoneyAccount::query()->active()->where('type', MoneyAccountType::Courier->value)
                ->orderBy('name')->get(['id', 'name', 'provider']),
        ]);
    }

    public function store(Request $request, ImportCourierStatement $action): RedirectResponse
    {
        $this->authorize('sales.receipt.record');

        $data = $request->validate([
            'money_account_id' => ['required', 'integer', 'exists:money_accounts,id'],
            'statement_reference' => ['required', 'string', 'max:100'],
            'statement_date' => ['required', 'date', 'before_or_equal:today'],
            'file' => ['required', 'file', 'max:2048', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel'],
        ]);

        $file = $request->file('file');

        $statement = $action->handle(
            courier: MoneyAccount::query()->findOrFail($data['money_account_id']),
            reference: $data['statement_reference'],
            statementDate: $data['statement_date'],
            contents: (string) file_get_contents($file->getRealPath()),
            filename: $file->getClientOriginalName(),
            createdBy: $request->user(),
        );

        return redirect()->route('sales.courier-statements.show', $statement)
            ->with('success', "Statement {$statement->statement_reference} read: {$statement->lines->count()} parcels.");
    }

    public function show(Request $request, CourierStatement $courierStatement): Response
    {
        $review = CourierStatementReview::of($courierStatement->load('courier:id,name,provider,account_id', 'receivedInto:id,name'));

        $journal = $courierStatement->isDraft() ? null : Journal::query()
            ->where('source_type', $courierStatement->getMorphClass())
            ->where('source_id', $courierStatement->id)
            ->whereNull('reverses_journal_id')
            ->with('lines.account:id,code,name')
            ->first();

        return Inertia::render('sales/courier-statements/show', [
            'statement' => [
                'id' => $courierStatement->id,
                'courier' => $courierStatement->courier?->name,
                'statement_reference' => $courierStatement->statement_reference,
                'statement_date' => $courierStatement->statement_date->toDateString(),
                'status' => $courierStatement->status,
                'source_filename' => $courierStatement->source_filename,
                'received_into' => $courierStatement->receivedInto?->name,
                'deposit_amount' => $courierStatement->deposit_amount === null ? null : number_format((float) $courierStatement->deposit_amount, 2, '.', ''),
                'received_on' => $courierStatement->received_on?->toDateString(),
            ],
            'lines' => $review->rows->map(fn (array $row) => [
                'id' => $row['line']->id,
                'consignment_number' => $row['line']->consignment_number,
                'merchant_reference' => $row['line']->merchant_reference,
                'state' => $row['state'],
                'resolution' => $row['line']->resolution,
                'included' => $row['included'],
                'problem' => $row['problem'],
                'consignment_status' => $row['consignment']?->status->label(),
                'collected' => $row['line']->collected()->toTaka(),
                'charge' => CourierStatementReview::carriage($row['line'])->toTaka(),
                'cod_fee' => $row['line']->codFee()->toTaka(),
                'payout' => $row['line']->payoutAmount()->toTaka(),
            ])->values(),
            'summary' => [
                'file_total' => $review->fileTotal->toTaka(),
                'share' => $review->share->toTaka(),
                'collected' => $review->collected->toTaka(),
                'delivery_fees' => $review->deliveryFees->toTaka(),
                'return_fees' => $review->returnFees->toTaka(),
                'additional_charges' => $review->additionalCharges->toTaka(),
                'cod_fees' => $review->codFees->toTaka(),
                'deposit_expected' => $review->fileTotal->minor > 0,
            ],
            'problems' => $review->problems,
            'can_post' => $courierStatement->isDraft() && $review->canPost() && $request->user()->can('sales.receipt.record'),
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
        ]);

        try {
            $action->handle(
                statement: $courierStatement,
                receivedInto: isset($data['received_money_account_id']) ? MoneyAccount::query()->findOrFail($data['received_money_account_id']) : null,
                depositAmount: isset($data['deposit_amount']) ? (string) $data['deposit_amount'] : null,
                receivedOn: $data['received_on'] ?? null,
                postedBy: $request->user(),
            );
        } catch (PeriodClosedException|MappingNotFound $e) {
            throw ValidationException::withMessages(['statement' => $e->getMessage()]);
        }

        return back()->with('success', "Statement {$courierStatement->statement_reference} posted.");
    }

    public function destroy(CourierStatement $courierStatement): RedirectResponse
    {
        $this->authorize('sales.receipt.record');

        if (! $courierStatement->isDraft()) {
            throw ValidationException::withMessages(['statement' => 'A posted statement stays; it is the record of money that moved.']);
        }

        $courierStatement->delete();

        return redirect()->route('sales.courier-statements.index')->with('success', 'Draft statement discarded.');
    }
}
