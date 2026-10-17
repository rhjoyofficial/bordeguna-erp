<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Actions\RecordIncomeVoucher;
use App\Modules\Accounting\Corrections\IncomeVoucherActionPolicy;
use App\Modules\Accounting\Corrections\IncomeVoucherDuplicateRule;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\IncomeVoucher;
use App\Modules\Accounting\Support\IncomeVoucherRules;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Actions\DeleteAttachment;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DuplicateGuard;
use App\Modules\Foundation\Corrections\Exceptions\PossibleDuplicateException;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\CorrectionRequest;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\BusinessIdentity;
use App\Modules\Sales\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Money that came in and was not a sale.
 *
 * ⚠⚠ WHY THIS EXISTS. `4900 Other Income` was postable from the day the chart
 * was seeded and NO SCREEN COULD REACH IT. Scrap, bank interest, rent received,
 * a supplier refund: all of it had to go through a hand-written journal, on the
 * one screen the rest of this system exists to spare people. Found on 5
 * September while writing down how income is recorded, and it was the only
 * money path with no document behind it.
 *
 * ⚠ This adds no accounting behaviour. Every rule - the period lock, the
 * future-date refusal, income-accounts-only, the sales-account refusal, the
 * money account mapping, the numbering - lives in `RecordIncomeVoucher` and
 * stays there. This class collects a form, validates its SHAPE and ownership,
 * and hands it over.
 *
 * ⭐ 17 Oct 2026 (edit, delete and correct, Phase 0): a posted voucher is
 * Reversed or Corrected, never edited or deleted. The list shows live vouchers
 * by default ("Show corrections" reveals the rest with their badge), and the
 * Correct page is the record form pre-filled. The correction itself goes
 * through the generic records.* routes and CorrectionService.
 */
class IncomeVoucherController extends Controller
{
    public function index(Request $request, IncomeVoucherActionPolicy $policy): Response
    {
        $this->authorize('accounting.view');

        $showCorrections = $request->boolean('show_corrections');
        $user = $request->user();

        $vouchers = IncomeVoucher::query()
            ->with(['moneyAccount:id,name', 'customer:id,name', 'lines.account:id,code,name', 'attachments.uploadedBy:id,name', 'reversedBy:id,name', 'correctionAsOriginal', 'correctionAsSuccessor'])
            ->when(! $showCorrections, fn ($query) => $query->live())
            ->orderByDesc('received_on')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $vouchers->getCollection()->transform(fn (IncomeVoucher $voucher) => [
            'id' => $voucher->id,
            'voucher_number' => $voucher->voucher_number,
            // ⚠ toDateString(), not the model's date cast. A `date` cast
            // serialises to JSON in UTC and Dhaka is UTC+6, so midnight local
            // becomes 18:00 the PREVIOUS day.
            'received_on' => $voucher->received_on?->toDateString(),
            'received_into' => $voucher->moneyAccount?->name,
            'payer' => $voucher->payer(),
            'total' => Money::ofTaka((string) $voucher->total)->toTaka(),
            'reference' => $voucher->reference,
            'note' => $voucher->note,
            'status' => $voucher->correctionStatus(),
            'correction_of' => $voucher->correctionAsSuccessor?->original_number,
            'corrected_to' => $voucher->correctionAsOriginal?->successor_number,
            'corrected_to_id' => $voucher->correctionAsOriginal?->successor_id,
            'closed_month_exception' => (bool) ($voucher->correctionAsOriginal?->closed_period_exception),
            'reversal' => $voucher->reversalSummary(),
            'actions' => $policy->actionsFor($voucher, $user)->toArray()['actions'],
            'evidence' => $voucher->attachments->map(fn (Attachment $file) => [
                'id' => $file->id,
                'name' => $file->original_name ?? $file->file_name,
                'uploaded_by' => $file->uploadedBy?->name,
                'url' => route('attachments.show', $file->id),
            ])->values()->all(),
            'lines' => $voucher->lines->map(fn ($line) => [
                'id' => $line->id,
                'account' => $line->account?->label(),
                'amount' => Money::ofTaka((string) $line->amount)->toTaka(),
                'description' => $line->description,
            ])->all(),
        ]);

        return Inertia::render('accounting/other-income/index', [
            'vouchers' => $vouchers,
            'canRecord' => $user?->can('accounting.income.record') ?? false,
            'showCorrections' => $showCorrections,
            'reversalReasons' => $this->reasons('reversal'),
            'myWaitingRequests' => CorrectionRequest::query()
                ->where('requested_by', $user->id)
                ->whereNotNull('pending_key')
                ->where('document_type', (new IncomeVoucher)->getMorphClass())
                ->latest('id')
                ->get(['id', 'document_number', 'action', 'approval_route', 'created_at'])
                ->map(fn (CorrectionRequest $r) => [
                    'id' => $r->id,
                    'summary' => ($r->action === DocumentAction::Correct ? 'Correct ' : 'Reverse ').$r->document_number,
                    'waiting_for' => $r->approval_route?->needsOwnerApproval() ? 'the Owner' : 'a second person',
                    'withdraw_url' => route('corrections.requests.withdraw', $r),
                ])->all(),
            'withdrawalReasons' => $this->reasons('withdrawal'),
            ...$this->formLists(),
        ]);
    }

    public function store(Request $request, RecordIncomeVoucher $action, DuplicateGuard $duplicates, IncomeVoucherDuplicateRule $rule): RedirectResponse
    {
        $this->authorize('accounting.income.record');

        $data = $request->validate(IncomeVoucherRules::rules() + [
            'duplicate_ack' => ['nullable', 'string', 'max:2000'],
        ]);

        $args = IncomeVoucherRules::arguments($data);
        $businessId = (int) app(BusinessContext::class)->id();

        // ⚠⚠ The action's refusals are RENDERED, never re-decided here. A
        // ValidationException it raises passes through with its own wording onto
        // its own field. PeriodClosedException and MappingNotFound are plain
        // RuntimeExceptions and would otherwise reach the user as a raw 500.
        try {
            DB::transaction(function () use ($action, $args, $data, $request, $duplicates, $rule, $businessId) {
                // ⭐ 17 Oct 2026: "Possible duplicate" (same income head, same
                // amount, within 3 days), asked once per payload; "Save anyway"
                // is kept beside the voucher.
                $ack = $duplicates->check($rule, $data, $request->user(), $businessId, $data['duplicate_ack'] ?? null);

                $voucher = $action->handle(
                    moneyAccount: $args['moneyAccount'],
                    receivedOn: $args['receivedOn'],
                    lines: $args['lines'],
                    createdBy: $request->user(),
                    customer: $args['customer'],
                    receivedFrom: $args['receivedFrom'],
                    reference: $args['reference'],
                    note: $args['note'],
                );

                if ($ack !== null) {
                    $duplicates->record($ack, $voucher);
                }
            });
        } catch (PossibleDuplicateException $e) {
            $request->session()->flash('duplicateWarning', $e->warning->toArray());

            throw ValidationException::withMessages(['_duplicate' => 'Possible duplicate. Is this a different income?']);
        } catch (PeriodClosedException|MappingNotFound $e) {
            throw ValidationException::withMessages(['received_on' => $e->getMessage()]);
        }

        return redirect()
            ->route('accounting.other-income.index')
            ->with('success', 'Income recorded.');
    }

    /**
     * The record form, pre-filled, in "Correct" mode (PHASE-0-CONTRACT 3.2).
     * Nothing is saved from here: "Preview and correct" opens the action dialog,
     * which previews and then submits through records.act.
     */
    public function correct(Request $request, IncomeVoucher $voucher, IncomeVoucherActionPolicy $policy): Response|RedirectResponse
    {
        $this->authorize('accounting.view');

        $availability = $policy->availability($voucher, $request->user(), DocumentAction::Correct);

        if ($voucher->isReversed()) {
            $request->session()->reflash();

            return redirect()->route('accounting.other-income.index')
                ->with('error', $availability->blockers[0]->message ?? "{$voucher->voucher_number} was already reversed.");
        }

        $voucher->load('lines');

        return Inertia::render('accounting/other-income/correct', [
            'voucher' => [
                'id' => $voucher->id,
                'voucher_number' => $voucher->voucher_number,
                'received_on' => $voucher->received_on?->toDateString(),
                'money_account_id' => (string) $voucher->money_account_id,
                'customer_id' => $voucher->customer_id === null ? '' : (string) $voucher->customer_id,
                'received_from' => $voucher->received_from ?? '',
                'reference' => $voucher->reference ?? '',
                'note' => $voucher->note ?? '',
                'total' => Money::ofTaka((string) $voucher->total)->toTaka(),
                'lines' => $voucher->lines->map(fn ($line) => [
                    'account_id' => (string) $line->account_id,
                    'amount' => Money::ofTaka((string) $line->amount)->toTaka(),
                    'description' => $line->description ?? '',
                ])->values()->all(),
            ],
            'availability' => $availability->toArray(),
            'correctionReasons' => $this->reasons('correction'),
            'type' => IncomeVoucherActionPolicy::TYPE,
            ...$this->formLists(),
        ]);
    }

    /**
     * Attach the deposit slip, bank advice or dealer's chit.
     *
     * ⚠ Evidence is added AFTER the voucher, deliberately, exactly as on the
     * expense side: the document is recorded when the money moves, the proof is
     * attached when it is in hand.
     */
    public function uploadEvidence(Request $request, IncomeVoucher $voucher, StoreAttachment $store): RedirectResponse
    {
        $this->authorize('accounting.income.record');

        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,csv,xls,xlsx', 'max:5120'],
        ]);

        $store->handle($voucher, $request->file('file'), IncomeVoucher::EVIDENCE_COLLECTION, $request->user());

        return back()->with('success', "Proof attached to {$voucher->voucher_number}.");
    }

    /**
     * ⚠ Removing proof is allowed but logged. The voucher itself stays: money
     * that arrived is not deleted because its paperwork changed.
     */
    public function deleteEvidence(IncomeVoucher $voucher, Attachment $attachment, DeleteAttachment $delete): RedirectResponse
    {
        $this->authorize('accounting.income.record');

        abort_unless(
            $attachment->attachable_type === $voucher->getMorphClass() && $attachment->attachable_id === $voucher->id,
            404,
        );

        // ACC-008 (14 Oct 2026): the docblock promised a log and nothing wrote
        // one. An income voucher is posted the moment it is recorded, so every
        // removal is a removal from a posted record.
        activity()
            ->performedOn($voucher)
            ->causedBy(request()->user())
            ->withProperties(['file' => $attachment->original_name ?? $attachment->path])
            ->log('income proof removed');

        $delete->handle($attachment);

        return back()->with('success', 'Proof removed.');
    }

    /**
     * The voucher as paper: the office copy and the payer's copy on one sheet.
     *
     * ⚠ Behind the permission that can VIEW the books, not the one that records
     * income: a document is no more secret than the record it prints.
     *
     * ⭐ 17 Oct 2026: a reversed or corrected voucher prints with its stamp, and
     * a successor prints "Correction of ...".
     */
    public function print(IncomeVoucher $voucher): Response
    {
        $this->authorize('accounting.view');

        $voucher->load(['moneyAccount:id,name', 'customer:id,name', 'lines.account:id,code,name', 'createdBy:id,name', 'correctionAsOriginal', 'correctionAsSuccessor']);

        $stamp = match ($voucher->correctionStatus()) {
            'corrected' => 'CORRECTED, see '.$voucher->correctionAsOriginal?->successor_number,
            'reversed' => 'REVERSED',
            default => null,
        };

        return Inertia::render('accounting/other-income/print', [
            'issuer' => BusinessIdentity::forCurrent()->toArray(),
            'voucher' => [
                'voucher_number' => $voucher->voucher_number,
                'received_on' => $voucher->received_on?->toDateString(),
                'received_into' => $voucher->moneyAccount?->name,
                'payer' => $voucher->payer(),
                'reference' => $voucher->reference,
                'note' => $voucher->note,
                'total' => Money::ofTaka((string) $voucher->total)->toTaka(),
                'recorded_by' => $voucher->createdBy?->name,
                'stamp' => $stamp,
                'correction_of' => $voucher->correctionAsSuccessor?->original_number,
                'lines' => $voucher->lines->map(fn ($line) => [
                    'account' => $line->account?->label(),
                    'description' => $line->description,
                    'amount' => Money::ofTaka((string) $line->amount)->toTaka(),
                ])->values()->all(),
            ],
        ]);
    }

    /** @return list<array{id: int, name: string}> */
    private function reasons(string $context): array
    {
        return Reason::query()->active()->forContext($context)->orderBy('id')->get(['id', 'name'])
            ->map(fn (Reason $r) => ['id' => $r->id, 'name' => $r->name])->all();
    }

    /** The pickers the record form and the Correct form share. */
    private function formLists(): array
    {
        return [
            // ⚠⚠ INCOME accounts, postable, and NOT the sales ones. The action
            // refuses sales revenue and its contra pair outright, so offering
            // them here would be a picker whose most obvious choice is an error.
            'incomeAccounts' => Account::query()
                ->where('type', AccountType::Income)
                ->where('is_postable', true)
                ->where('is_active', true)
                // ⚠⚠ THE NULL BRANCH IS NOT OPTIONAL. `system_slug NOT IN
                // (...)` evaluates to NULL for a row whose slug is NULL, and
                // NULL is not true, so a bare whereNotIn would have hidden
                // EVERY head that has no slug - which is all of them, including
                // the five this feature ships and anything the accountant adds.
                ->where(function ($query) {
                    $query->whereNull('system_slug')
                        ->orWhereNotIn('system_slug', RecordIncomeVoucher::SALES_ONLY);
                })
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Account $account) => [
                    'id' => $account->id,
                    'label' => $account->label(),
                ])
                ->all(),

            // ⚠ Active accounts WITH a ledger mapping. An unmapped one throws
            // MappingNotFound out of the action, so listing it would be offering
            // a guaranteed failure.
            'moneyAccounts' => MoneyAccount::query()->handled()
                ->where('is_active', true)
                ->whereNotNull('account_id')
                ->orderBy('name')
                ->get(['id', 'name', 'type'])
                ->all(),

            'customers' => Customer::query()->listable()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->all(),

            'today' => now()->toDateString(),
        ];
    }
}
