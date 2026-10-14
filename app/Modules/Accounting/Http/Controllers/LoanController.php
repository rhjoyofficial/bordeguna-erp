<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Actions\Loans\LoanApprovals;
use App\Modules\Accounting\Actions\Loans\SaveLoanContract;
use App\Modules\Accounting\Enums\LoanDayCount;
use App\Modules\Accounting\Enums\LoanFrequency;
use App\Modules\Accounting\Enums\LoanRepaymentType;
use App\Modules\Accounting\Enums\LoanStatus;
use App\Modules\Accounting\Enums\LoanWaterfall;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Counterparty;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Models\LoanEntry;
use App\Modules\Accounting\Models\LoanWriteOff;
use App\Modules\Accounting\Support\Loans\LoanHeads;
use App\Modules\Accounting\Support\Loans\LoanLedger;
use App\Modules\Accounting\Support\Loans\LoanMath;
use App\Modules\Accounting\Support\Loans\LoanPosition;
use App\Modules\Accounting\Support\Loans\LoanPresenter;
use App\Modules\Accounting\Support\Loans\RepaymentPlan;
use App\Modules\Accounting\Support\Loans\TdsPolicy;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Actions\DeleteAttachment;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessIdentity;
use App\Support\RecordTimeline;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accounting > Loans (loans plan v2, phase 2, 14 Oct 2026): the Loan Register.
 * ROUTE, SCREEN, NAV LINK, PERMISSION AND APPROVAL WORKFLOW ROW in one change
 * (the reachability rule).
 *
 * Reading is `accounting.loans.view` (Owner, Finance, Manager); drafting and
 * every money action is `accounting.loans.manage` (Owner, Finance); approving
 * is the Owner's, by the workflow stage, never by a permission.
 */
class LoanController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'direction' => in_array($request->query('direction'), ['given', 'taken'], true) ? $request->query('direction') : '',
            'status' => (string) $request->query('status', 'open'),
            'counterparty_id' => (string) $request->query('counterparty_id', ''),
            'related' => in_array($request->query('related'), ['yes', 'no'], true) ? $request->query('related') : '',
        ];

        $loans = LoanContract::query()->with('counterparty')
            ->when($filters['direction'] !== '', fn ($q) => $q->where('direction', $filters['direction']))
            ->when($filters['counterparty_id'] !== '', fn ($q) => $q->where('counterparty_id', (int) $filters['counterparty_id']))
            ->when($filters['related'] !== '', fn ($q) => $q->whereHas('counterparty', fn ($c) => $c->where('related_party', $filters['related'] === 'yes')))
            ->when($filters['status'] === 'open', fn ($q) => $q->whereNotIn('status', [LoanStatus::Settled->value, LoanStatus::WrittenOff->value, LoanStatus::Cancelled->value]))
            ->when(LoanStatus::tryFrom($filters['status']) !== null, fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['status'] === 'overdue', fn ($q) => $q->where('status', LoanStatus::Active->value))
            ->orderByDesc('id')
            ->get();

        $rows = $loans->map(fn (LoanContract $loan) => LoanPresenter::summary($loan))
            ->when($filters['status'] === 'overdue', fn ($rows) => $rows->filter(fn (array $row) => $row['overdue']))
            ->values();

        $totals = ['given' => 0, 'taken' => 0];

        foreach ($loans as $loan) {
            if ($loan->status->wasApproved()) {
                $totals[$loan->direction->value] += max(0, LoanPosition::of($loan)->principal);
            }
        }

        return Inertia::render('accounting/loans/index', [
            'filters' => $filters,
            'loans' => $rows,
            'totals' => ['given' => LoanPresenter::money($totals['given']), 'taken' => LoanPresenter::money($totals['taken'])],
            'counterparties' => Counterparty::query()->orderBy('legal_name')->get(['id', 'legal_name'])->map(fn ($c) => ['id' => $c->id, 'label' => $c->legal_name]),
            'canManage' => $request->user()->can('accounting.loans.manage'),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null, $request);
    }

    public function store(Request $request, SaveLoanContract $save): RedirectResponse
    {
        $loan = $save->handle($this->validated($request), $request->user());

        return redirect()->route('accounting.loans.show', $loan)
            ->with('success', "{$loan->loan_number} saved as a draft. Send it to the Owner when the terms are right.")
            ->with('warning', $save->warnings === [] ? null : implode(' ', $save->warnings));
    }

    public function edit(Request $request, LoanContract $loan): Response|RedirectResponse
    {
        if ($loan->status !== LoanStatus::Draft) {
            return redirect()->route('accounting.loans.show', $loan)->with('error', "{$loan->loan_number} is {$loan->status->label()}. Only a draft is edited; an active loan changes by an amendment.");
        }

        return $this->form($loan, $request);
    }

    public function update(Request $request, LoanContract $loan, SaveLoanContract $save): RedirectResponse
    {
        $loan = $save->handle($this->validated($request), $request->user(), $loan);

        return redirect()->route('accounting.loans.show', $loan)
            ->with('success', "{$loan->loan_number} saved.")
            ->with('warning', $save->warnings === [] ? null : implode(' ', $save->warnings));
    }

    public function show(Request $request, LoanContract $loan): Response
    {
        $loan->load(['counterparty', 'principalAccount', 'currentPortionAccount', 'interestAccrualAccount', 'interestPlAccount', 'createdBy:id,name', 'approvalRequest.decisions']);
        $position = LoanPosition::of($loan);
        $user = $request->user();
        $today = Carbon::today();

        $entries = LoanEntry::query()->with(['journal', 'reversalJournal', 'moneyAccount'])->where('loan_contract_id', $loan->id)->orderBy('entry_date')->orderBy('id')->get();
        $liveIds = LoanEntry::query()->live()->where('loan_contract_id', $loan->id)->pluck('id')->flip();

        $tds = TdsPolicy::rateFor($loan, $today);
        $approval = $loan->approvalRequest;

        return Inertia::render('accounting/loans/show', [
            'loan' => LoanPresenter::summary($loan, $position) + [
                'currency' => $loan->currency,
                'day_count' => $loan->day_count->value,
                'repayment_type' => $loan->repayment_type->value,
                'frequency' => $loan->frequency->value,
                'grace_months' => (int) $loan->grace_months,
                'waterfall' => $loan->waterfall->value,
                'interest_free_reason' => $loan->interest_free_reason,
                'collateral' => $loan->collateral,
                'purpose' => $loan->purpose,
                'heads' => [
                    'principal' => $loan->principalAccount?->label(),
                    'current_portion' => $loan->currentPortionAccount?->label(),
                    'accrual' => $loan->interestAccrualAccount?->label(),
                    'profit_and_loss' => $loan->interestPlAccount?->label(),
                ],
                'owner_loan' => $loan->principalAccount?->system_slug === LoanHeads::OWNER_LOANS,
                'tds_certificate_ref' => $loan->tds_certificate_ref,
                'tds_certificate_rate' => $loan->tds_certificate_rate === null ? null : LoanMath::rateString(LoanMath::rateE4((string) $loan->tds_certificate_rate)),
                'terms_version' => (int) $loan->terms_version,
                'approved_at' => $loan->approved_at?->toDateString(),
                'settled_on' => $loan->settled_on?->toDateString(),
                'cancel_reason' => $loan->cancel_reason,
                'created_by' => $loan->createdBy?->name,
            ],
            'position' => [
                'principal_minor' => max(0, $position->principal),
                'accrued_minor' => $position->accrued,
                'left_to_advance' => LoanPresenter::money(max(0, (int) $loan->principal_minor - $position->advanced)),
                'left_to_advance_minor' => max(0, (int) $loan->principal_minor - $position->advanced),
                'max_interest_minor' => RepaymentPlan::maxInterest($position),
                'ledger_principal' => LoanPresenter::money(LoanLedger::principalOwed($loan)),
                'ledger_accrued' => LoanPresenter::money(LoanLedger::accruedOwed($loan)),
                'agrees' => LoanLedger::principalOwed($loan) === $position->principal
                    && LoanLedger::accruedOwed($loan) === $position->accrued,
            ],
            'schedule' => array_map(fn (array $row) => LoanPresenter::row($row), $position->currentRows()),
            'history' => array_values(array_map(fn (array $row) => LoanPresenter::row($row), array_filter($position->rows, fn (array $row) => ! $row['current']))),
            'entries' => $entries->map(fn (LoanEntry $entry) => LoanPresenter::entry($entry, $liveIds->has($entry->id)))->values(),
            'versions' => $loan->termsVersions()->with('createdBy:id,name')->get()->map(fn ($v) => [
                'version' => $v->version,
                'effective_from' => $v->effective_from->toDateString(),
                'terms' => $v->terms,
                'reason' => $v->reason,
                'by' => $v->createdBy?->name,
            ]),
            'amendments' => $loan->amendments()->with('createdBy:id,name')->get()->map(fn ($a) => [
                'id' => $a->id,
                'status' => $a->status,
                'effective_from' => $a->effective_from->toDateString(),
                'terms' => $a->terms,
                'reason' => $a->reason,
                'by' => $a->createdBy?->name,
                'can_decide' => $a->status === 'pending' && ($a->approvalRequest?->canBeActionedBy($user) ?? false),
                'self_approval' => $a->status === 'pending' && ($a->approvalRequest?->isSelfApprovalBy($user) ?? false),
            ]),
            'writeOffs' => $loan->writeOffs()->with(['createdBy:id,name', 'attachments'])->get()->map(fn (LoanWriteOff $w) => [
                'id' => $w->id,
                'kind' => $w->kind,
                'amount' => LoanPresenter::money((int) $w->amount_minor),
                'forgo' => LoanPresenter::money((int) $w->forgo_minor),
                'effective_date' => $w->effective_date->toDateString(),
                'reason' => $w->reason,
                'status' => $w->status,
                'by' => $w->createdBy?->name,
                'evidence' => $w->attachments->map(fn (Attachment $a) => ['id' => $a->id, 'name' => $a->original_name, 'url' => route('attachments.show', $a)])->values(),
                'can_decide' => $w->status === LoanWriteOff::PENDING && ($w->approvalRequest?->canBeActionedBy($user) ?? false),
                'self_approval' => $w->status === LoanWriteOff::PENDING && ($w->approvalRequest?->isSelfApprovalBy($user) ?? false),
            ]),
            'documents' => $loan->attachments()->where('collection', LoanContract::AGREEMENT_COLLECTION)->get()
                ->map(fn (Attachment $a) => ['id' => $a->id, 'name' => $a->original_name, 'url' => route('attachments.show', $a)])->values(),
            'approval' => [
                'status' => $approval?->status?->value,
                'note' => $approval?->review_note,
                'can_decide' => $loan->status === LoanStatus::PendingApproval && ($approval?->canBeActionedBy($user) ?? false),
                'self_approval' => $loan->status === LoanStatus::PendingApproval && ($approval?->isSelfApprovalBy($user) ?? false),
            ],
            'tds' => [
                'applies' => $tds['rule'] !== null,
                'rate' => $tds['rule'] === null ? null : LoanMath::rateString($tds['rate_e4']),
                'rate_e4' => $tds['rate_e4'],
                'rule' => $tds['rule']?->name,
                'legal_basis' => $tds['rule']?->legal_basis,
                'certificate' => $tds['certificate'],
            ],
            'moneyAccounts' => MoneyAccount::query()->handled()->active()->withLedger()->orderBy('name')->get()
                ->map(fn (MoneyAccount $m) => ['id' => $m->id, 'label' => $m->label()])->values(),
            'timeline' => RecordTimeline::for($loan),
            'today' => $today->toDateString(),
            'lastMonth' => $today->copy()->subMonthNoOverflow()->format('Y-m'),
            'can' => [
                'manage' => $user->can('accounting.loans.manage'),
                'view_ledger' => $user->can('accounting.view'),
            ],
        ]);
    }

    public function submit(Request $request, LoanContract $loan, LoanApprovals $approvals): RedirectResponse
    {
        $approvals->submit($loan, $request->user());

        return back()->with('success', "{$loan->loan_number} sent to the Owner for approval.");
    }

    public function approve(Request $request, LoanContract $loan, LoanApprovals $approvals): RedirectResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;

        try {
            $final = $approvals->approve($loan, $request->user(), $note);
        } catch (ValidationException $e) {
            return back()->with('error', 'Not approved: '.collect($e->errors())->flatten()->first());
        }

        return back()->with('success', $final ? "{$loan->loan_number} approved. Its money can now be recorded." : 'Approved. Sent to the next stage.');
    }

    public function sendBack(Request $request, LoanContract $loan, LoanApprovals $approvals): RedirectResponse
    {
        $data = $request->validate(['note' => ['required_without:review_note', 'nullable', 'string', 'max:1000'], 'review_note' => ['nullable', 'string', 'max:1000']]);

        $approvals->sendBack($loan, $request->user(), (string) ($data['note'] ?? $data['review_note']));

        return back()->with('success', "{$loan->loan_number} sent back as a draft.");
    }

    public function cancel(Request $request, LoanContract $loan, LoanApprovals $approvals): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $approvals->cancel($loan, $request->user(), $data['reason']);

        return back()->with('success', "{$loan->loan_number} cancelled.");
    }

    /**
     * The per-loan statement: its terms, its schedule and every line the
     * ledger holds for it, head by head with a running balance, on the
     * letterhead.
     */
    public function statement(Request $request, LoanContract $loan): Response
    {
        $loan->load(['counterparty', 'principalAccount', 'currentPortionAccount', 'interestAccrualAccount']);
        $sign = $loan->direction->sign();

        $lines = JournalLine::query()
            ->with(['journal', 'account'])
            ->where('loan_contract_id', $loan->id)
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->orderBy('journals.journal_date')->orderBy('journals.id')->orderBy('journal_lines.line_no')
            ->select('journal_lines.*')
            ->get();

        $heads = $lines->groupBy('account_id')->map(function ($group) use ($sign) {
            $running = 0;

            return [
                'account' => $group->first()->account?->label(),
                'lines' => $group->map(function (JournalLine $line) use (&$running, $sign) {
                    $running += (int) $line->amount_minor * $sign;

                    return [
                        'date' => $line->journal->journal_date->toDateString(),
                        'journal_id' => $line->journal_id,
                        'journal_number' => $line->journal->journal_number,
                        'memo' => $line->memo ?? $line->journal->memo,
                        'debit' => $line->amount_minor > 0 ? LoanPresenter::money((int) $line->amount_minor) : null,
                        'credit' => $line->amount_minor < 0 ? LoanPresenter::money(-(int) $line->amount_minor) : null,
                        'balance' => LoanPresenter::money($running),
                    ];
                })->values(),
                'closing' => LoanPresenter::money($running),
            ];
        })->values();

        $position = LoanPosition::of($loan);

        return Inertia::render('accounting/loans/statement', [
            'loan' => LoanPresenter::summary($loan, $position) + [
                'address' => $loan->counterparty?->address,
                'frequency' => $loan->frequency->value,
                'repayment_type' => $loan->repayment_type->value,
            ],
            'heads' => $heads,
            'schedule' => array_map(fn (array $row) => LoanPresenter::row($row), $position->currentRows()),
            'issuer' => BusinessIdentity::forCurrent()->toArray(),
            'today' => Carbon::today()->toDateString(),
        ]);
    }

    public function uploadAgreement(Request $request, LoanContract $loan, StoreAttachment $store): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240']]);

        $store->handle($loan, $request->file('file'), LoanContract::AGREEMENT_COLLECTION, $request->user());
        activity()->performedOn($loan)->causedBy($request->user())->log("Agreement attached to {$loan->loan_number}");

        return back()->with('success', 'Agreement attached.');
    }

    public function deleteAgreement(Request $request, LoanContract $loan, Attachment $attachment, DeleteAttachment $delete): RedirectResponse
    {
        abort_unless($attachment->attachable_type === $loan->getMorphClass() && $attachment->attachable_id === $loan->id, 404);

        if ($loan->status !== LoanStatus::Draft) {
            return back()->with('error', 'The agreement of an approved loan stays on record. Attach a corrected copy beside it.');
        }

        $delete->handle($attachment);

        return back()->with('success', 'Attachment removed.');
    }

    private function form(?LoanContract $loan, Request $request): Response
    {
        return Inertia::render('accounting/loans/form', [
            'loan' => $loan === null ? null : [
                'id' => $loan->id,
                'number' => $loan->loan_number,
                'direction' => $loan->direction->value,
                'counterparty_id' => $loan->counterparty_id,
                'principal' => $loan->principal()->toTaka(),
                'start_date' => $loan->start_date->toDateString(),
                'maturity_date' => $loan->maturity_date->toDateString(),
                'annual_rate' => LoanMath::rateString($loan->rateE4()),
                'interest_free_reason' => $loan->interest_free_reason ?? '',
                'day_count' => $loan->day_count->value,
                'repayment_type' => $loan->repayment_type->value,
                'frequency' => $loan->frequency->value,
                'grace_months' => (string) $loan->grace_months,
                'waterfall' => $loan->waterfall->value,
                'collateral' => $loan->collateral ?? '',
                'purpose' => $loan->purpose ?? '',
                'principal_head' => Account::query()->whereKey($loan->principal_account_id)->value('system_slug') ?? '',
                'tds_certificate_ref' => $loan->tds_certificate_ref ?? '',
                'tds_certificate_rate' => $loan->tds_certificate_rate === null ? '' : LoanMath::rateString(LoanMath::rateE4((string) $loan->tds_certificate_rate)),
                'custom_rows' => $loan->scheduleRows->map(fn ($row) => [
                    'due_date' => $row->due_date->toDateString(),
                    'principal' => Money::ofMinor($row->principal_due_minor)->toTaka(),
                    'interest' => Money::ofMinor($row->interest_due_minor)->toTaka(),
                ])->values(),
            ],
            'counterparties' => Counterparty::query()->active()->orderBy('legal_name')->get()
                ->map(fn (Counterparty $c) => ['id' => $c->id, 'label' => $c->legal_name, 'kind' => $c->kind->value, 'related' => (bool) $c->related_party])
                ->values(),
            'heads' => $this->headOptions(),
            'preset' => [
                'direction' => in_array($request->query('direction'), ['given', 'taken'], true) ? $request->query('direction') : 'given',
                'counterparty_id' => $request->integer('counterparty_id') ?: null,
            ],
            'options' => [
                'repayment_types' => array_map(fn ($c) => $c->value, LoanRepaymentType::cases()),
                'frequencies' => array_map(fn ($c) => $c->value, LoanFrequency::cases()),
                'day_counts' => array_map(fn ($c) => $c->value, LoanDayCount::cases()),
                'waterfalls' => array_map(fn ($c) => $c->value, LoanWaterfall::cases()),
            ],
            'today' => Carbon::today()->toDateString(),
        ]);
    }

    /**
     * Each principal head with the rule that offers it, so the form can show
     * only the ones that fit (the server checks again: LoanHeads).
     *
     * @return list<array<string, mixed>>
     */
    private function headOptions(): array
    {
        $rules = [
            ['slug' => 'loans_to_other_parties', 'direction' => 'given', 'term' => 'short', 'related' => false, 'bank' => null],
            ['slug' => 'loans_to_related_parties', 'direction' => 'given', 'term' => 'short', 'related' => true, 'bank' => null],
            ['slug' => 'long_term_loans_given', 'direction' => 'given', 'term' => 'long', 'related' => null, 'bank' => null],
            ['slug' => 'short_term_loans_payable', 'direction' => 'taken', 'term' => 'short', 'related' => null, 'bank' => null],
            ['slug' => 'bank_loans', 'direction' => 'taken', 'term' => 'long', 'related' => null, 'bank' => true],
            ['slug' => 'loans_from_other_parties', 'direction' => 'taken', 'term' => 'long', 'related' => null, 'bank' => false],
            ['slug' => 'owner_loans', 'direction' => 'taken', 'term' => 'long', 'related' => null, 'bank' => false],
        ];

        $labels = Account::query()->whereIn('system_slug', array_column($rules, 'slug'))->pluck('code', 'system_slug');
        $names = Account::query()->whereIn('system_slug', array_column($rules, 'slug'))->pluck('name', 'system_slug');

        return array_values(array_filter(array_map(fn (array $rule) => isset($labels[$rule['slug']])
            ? $rule + ['label' => "{$labels[$rule['slug']]} - {$names[$rule['slug']]}", 'warning' => $rule['slug'] === LoanHeads::OWNER_LOANS]
            : null, $rules)));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'direction' => ['required', 'in:given,taken'],
            'counterparty_id' => ['required', 'integer'],
            'principal' => ['required', 'string', 'max:20'],
            'start_date' => ['required', 'date'],
            'maturity_date' => ['required', 'date'],
            'annual_rate' => ['nullable', 'string', 'max:12'],
            'interest_free_reason' => ['nullable', 'string', 'max:1000'],
            'day_count' => ['nullable', 'string', 'max:12'],
            'repayment_type' => ['required', 'string', 'max:20'],
            'frequency' => ['required', 'string', 'max:12'],
            'grace_months' => ['nullable', 'integer', 'min:0', 'max:600'],
            'waterfall' => ['nullable', 'string', 'max:16'],
            'collateral' => ['nullable', 'string', 'max:2000'],
            'purpose' => ['nullable', 'string', 'max:2000'],
            'principal_head' => ['nullable', 'string', 'max:60'],
            'tds_certificate_ref' => ['nullable', 'string', 'max:120'],
            'tds_certificate_rate' => ['nullable', 'string', 'max:12'],
            'custom_rows' => ['nullable', 'array', 'max:600'],
            'custom_rows.*.due_date' => ['nullable', 'date'],
            'custom_rows.*.principal' => ['nullable', 'string', 'max:20'],
            'custom_rows.*.interest' => ['nullable', 'string', 'max:20'],
        ]);
    }
}
