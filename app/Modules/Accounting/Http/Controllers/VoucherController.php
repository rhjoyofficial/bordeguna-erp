<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Actions\FixVoucherDetails;
use App\Modules\Accounting\Actions\PostVoucher;
use App\Modules\Accounting\Actions\ReverseVoucher;
use App\Modules\Accounting\Actions\SaveVoucherDraft;
use App\Modules\Accounting\Actions\SubmitOrPostVoucher;
use App\Modules\Accounting\Corrections\VoucherActionPolicy;
use App\Modules\Accounting\Corrections\VoucherDuplicateRule;
use App\Modules\Accounting\Enums\VoucherStatus;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\OwnerWithdrawal;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Accounting\Support\AmountInWords;
use App\Modules\Accounting\Support\CounterpartyRules;
use App\Modules\Accounting\Support\Loans\LoanOptions;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\PartyBillWise;
use App\Modules\Accounting\Support\PartyHeads;
use App\Modules\Accounting\Support\PartyLedgers;
use App\Modules\Accounting\Support\VoucherAccounts;
use App\Modules\Accounting\Support\VoucherForm;
use App\Modules\Foundation\Actions\DeleteAttachment;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DuplicateGuard;
use App\Modules\Foundation\Corrections\Exceptions\PossibleDuplicateException;
use App\Modules\Foundation\Corrections\ValidatesActionReason;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\BusinessIdentity;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierAdvanceRefund;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Models\SupplierRefund;
use App\Modules\Purchasing\Models\WithholdingRate;
use App\Modules\Purchasing\Support\CorrectionScreen;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\CustomerRefund;
use App\Support\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payment, Receive, Contra and Journal vouchers: one controller, because the
 * four are one document with four entry shapes (VoucherType).
 *
 * ⚠ Permissions live on the routes: reading is `accounting.view`, drafting is
 * `accounting.voucher.prepare`, and anything that writes to the ledger - post,
 * reverse - is `accounting.voucher.post`.
 */
class VoucherController extends Controller
{
    use ValidatesActionReason;

    public function index(Request $request, string $type): Response
    {
        $voucherType = $this->type($type);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(VoucherStatus::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        // Everything but the status: the chips count inside it (round 3).
        $filtered = Voucher::query()
            ->where('type', $voucherType->value)
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('voucher_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('voucher_date', '<=', $to))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

                $query->where(fn ($inner) => $inner
                    ->where('voucher_number', 'like', $like)
                    ->orWhere('counterparty', 'like', $like)
                    ->orWhere('narration', 'like', $like)
                    ->orWhere('instrument_number', 'like', $like));
            });

        // ⭐ Status chips with counts (Accounts redesign, 16 Oct 2026, D3): one
        // grouped count over the same type, dates and search as the list.
        $byStatus = (clone $filtered)->toBase()->reorder()->groupBy('status')->selectRaw('status, COUNT(*) as how_many')->pluck('how_many', 'status');
        $statusCounts = ['all' => (int) $byStatus->sum()];

        foreach (VoucherStatus::cases() as $status) {
            $statusCounts[$status->value] = (int) ($byStatus[$status->value] ?? 0);
        }

        $vouchers = $filtered
            ->with(['moneyAccount:id,name,account_number', 'toMoneyAccount:id,name,account_number', 'lines.account:id,code,name', 'createdBy:id,name'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            // Drafts first (they are work waiting), then newest.
            ->orderByRaw("CASE WHEN status = 'draft' THEN 0 ELSE 1 END")
            ->orderByDesc('voucher_date')
            ->orderByDesc('id')
            ->paginate(PerPage::from())
            ->withQueryString();

        return Inertia::render('accounting/vouchers/index', [
            'type' => $this->typeProps($voucherType),
            'statusCounts' => $statusCounts,
            'filters' => [
                'status' => $filters['status'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
                'search' => $filters['search'] ?? '',
            ],
            'vouchers' => $vouchers->through(fn (Voucher $voucher) => [
                'id' => $voucher->id,
                'title' => $voucher->title(),
                'number' => $voucher->voucher_number,
                'date' => $voucher->voucher_date->toDateString(),
                'status' => $voucher->status->value,
                'counterparty' => $voucher->counterparty,
                'money_account' => $voucher->moneyAccount?->label(),
                'to_money_account' => $voucher->toMoneyAccount?->label(),
                'particulars' => $voucher->lines->take(3)->map(fn ($line) => $line->account?->name)->filter()->implode(', ')
                    .($voucher->lines->count() > 3 ? ' …' : ''),
                'narration' => $voucher->narration,
                'total' => $voucher->total()->format(),
                'created_by' => $voucher->createdBy?->name,
            ]),
        ]);
    }

    public function create(Request $request, string $type): Response
    {
        return $this->form($this->type($type), null, $this->prefill($request, $this->type($type)));
    }

    /**
     * ⭐ "Owner reimbursement" shortcut (Phase 2, 17 Oct 2026, ruling Q2): money
     * the proprietor pays in for goods he took is a RECEIPT crediting Owner's
     * Drawings, never sales revenue. The owner-withdrawals screen links here
     * with `?purpose=owner_reimbursement&withdrawal={id}` and the receipt
     * opens with the drawings line, the amount at cost and the narration
     * filled; the person still picks the money account and posts.
     *
     * @return array<string, mixed>|null
     */
    private function prefill(Request $request, VoucherType $type): ?array
    {
        if ($type !== VoucherType::Receipt || $request->string('purpose')->toString() !== 'owner_reimbursement') {
            return null;
        }

        $drawings = Account::bySlug('owners_drawings');

        if ($drawings === null) {
            return null;
        }

        $withdrawal = $request->integer('withdrawal') ?: null;
        $document = $withdrawal === null ? null : OwnerWithdrawal::query()->with('proprietor:id,name')->whereKey($withdrawal)->first();

        return [
            'counterparty' => $document?->proprietor?->name,
            'narration' => $document === null
                ? 'Owner reimbursement for goods taken (offsets drawings)'
                : "Owner reimbursement for owner personal withdrawal {$document->number} (offsets drawings)",
            'lines' => [[
                'account_id' => $drawings->id,
                'side' => 'credit',
                'amount' => $document === null ? '' : $document->costTotal()->toTaka(),
                'narration' => $document === null ? 'Owner reimbursement' : "Owner reimbursement {$document->number}",
            ]],
        ];
    }

    public function edit(Voucher $voucher): Response|RedirectResponse
    {
        if (! $voucher->isDraft()) {
            return redirect()->route('accounting.vouchers.show', $voucher)
                ->with('error', "{$voucher->title()} is posted and cannot be changed. Use Reverse and copy.");
        }

        return $this->form($voucher->type, $voucher);
    }

    public function store(Request $request, string $type, SaveVoucherDraft $save, SubmitOrPostVoucher $post): RedirectResponse
    {
        $voucherType = $this->type($type);
        $voucher = $this->savingOnce($request, $voucherType, null, fn (array $data) => $save->handle($voucherType, $data, $request->user()));

        return $this->afterSave($request, $voucher, $post);
    }

    public function update(Request $request, Voucher $voucher, SaveVoucherDraft $save, SubmitOrPostVoucher $post): RedirectResponse
    {
        $saved = $this->savingOnce($request, $voucher->type, $voucher, fn (array $data) => $save->handle($voucher->type, $data, $request->user(), $voucher));

        return $this->afterSave($request, $saved, $post);
    }

    /**
     * ⭐ Corrections wave 2 (24 Oct 2026; FINAL-PLAN 5): "Possible duplicate"
     * before a voucher is saved. The same party (or "Paid to" text), amount and
     * account within 3 days warns once per payload; "Save anyway" sends the
     * server's ack token, bound to this person and exactly what was typed, and
     * the ack is kept beside the voucher. The draft being edited never warns
     * about itself.
     *
     * @param  callable(array): Voucher  $save
     */
    private function savingOnce(Request $request, VoucherType $type, ?Voucher $editing, callable $save): Voucher
    {
        $data = $this->validated($request);
        $ack = $request->validate(['duplicate_ack' => ['nullable', 'string', 'max:2000']])['duplicate_ack'] ?? null;
        $guard = app(DuplicateGuard::class);
        $exclude = $editing === null ? [] : [$editing->getMorphClass().':'.$editing->id];

        try {
            return DB::transaction(function () use ($data, $type, $request, $ack, $guard, $exclude, $save) {
                $acknowledged = $guard->check(app(VoucherDuplicateRule::class), $data + ['type' => $type->value], $request->user(), (int) app(BusinessContext::class)->id(), $ack, $exclude);
                $voucher = $save($data);

                if ($acknowledged !== null) {
                    $guard->record($acknowledged, $voucher);
                }

                return $voucher;
            });
        } catch (PossibleDuplicateException $e) {
            $request->session()->flash('duplicateWarning', $e->warning->toArray());

            throw ValidationException::withMessages(['_duplicate' => 'Possible duplicate. Is this a different voucher?']);
        }
    }

    public function show(Voucher $voucher): Response
    {
        return Inertia::render('accounting/vouchers/show', [
            'voucher' => $this->document($voucher),
            // The address (/accounting/vouchers/doc/{id}) names no type: the
            // page lights its type's tab itself (round 3, D2).
            'sectionTab' => $this->typeTab($voucher->type),
        ]);
    }

    public function print(Voucher $voucher): Response
    {
        abort_unless($voucher->isPosted() || $voucher->status === VoucherStatus::Reversed, 404);

        return Inertia::render('accounting/vouchers/print', [
            'voucher' => $this->document($voucher),
            'issuer' => BusinessIdentity::forCurrent()->toArray(),
        ]);
    }

    public function post(Request $request, Voucher $voucher, SubmitOrPostVoucher $submit): RedirectResponse
    {
        $result = $submit->handle($voucher, $request->user());

        return redirect()->route('accounting.vouchers.show', $result['voucher'])->with('success', $this->postedMessage($result));
    }

    /**
     * The second person's yes (finance decision B13). The stage decides who may
     * act, and the preparer never can (Voucher::refusesApprovalBy).
     */
    public function approve(Request $request, Voucher $voucher, PostVoucher $post): RedirectResponse
    {
        $approval = $voucher->approvalRequest;
        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($request->user()), 403);

        try {
            $voucher = DB::transaction(function () use ($request, $voucher, $approval, $post) {
                $final = $approval->approve($request->user());

                return $final ? $post->handle($voucher->fresh(), $request->user()) : $voucher;
            });
        } catch (ValidationException $e) {
            // ⚠ Rolled back: still awaiting approval. The inbox shows no field
            // errors, so the refusal travels as the page message.
            return back()->with('error', 'Not approved: '.collect($e->errors())->flatten()->first());
        }

        return redirect()->route('accounting.vouchers.show', $voucher)
            ->with('success', $voucher->isPosted() ? "Approved and posted as {$voucher->voucher_number}." : 'Approved.');
    }

    /** Back to its preparer as a draft, with the reason (a refusal that can be corrected). */
    public function sendBack(Request $request, Voucher $voucher): RedirectResponse
    {
        $approval = $voucher->approvalRequest;
        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($request->user()), 403);

        $data = $request->validate(['note' => ['required_without:review_note', 'nullable', 'string', 'max:1000'], 'review_note' => ['nullable', 'string', 'max:1000']]);
        $note = $data['note'] ?? $data['review_note'];

        DB::transaction(function () use ($request, $voucher, $approval, $note) {
            $approval->sendBack($request->user(), $note);
            $voucher->update(['status' => VoucherStatus::Draft]);
        });

        return back()->with('success', "{$voucher->title()} sent back to its preparer.");
    }

    /**
     * @param  array{voucher: Voucher, pending: bool}  $result
     */
    private function postedMessage(array $result): string
    {
        $voucher = $result['voucher'];

        return $result['pending']
            ? "{$voucher->title()} is above the approval limit, so it has been sent for a second person's approval. It posts when they approve."
            : "{$voucher->voucher_number} posted.";
    }

    public function reverse(Request $request, Voucher $voucher, ReverseVoucher $reverse): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'copy' => ['nullable', 'boolean'],
        ]);

        if ($data['copy'] ?? false) {
            $draft = $reverse->andCopy($voucher, $request->user(), $data['reason']);

            return redirect()->route('accounting.vouchers.edit', $draft)
                ->with('success', "{$voucher->voucher_number} reversed. Correct this copy and post it.");
        }

        $reverse->handle($voucher, $request->user(), $data['reason']);

        return redirect()->route('accounting.vouchers.show', $voucher)->with('success', "{$voucher->voucher_number} reversed.");
    }

    public function duplicate(Request $request, Voucher $voucher, ReverseVoucher $reverse): RedirectResponse
    {
        // ⭐ Corrections wave 2 (24 Oct 2026): Copy after a Reverse is the
        // correcting copy (its own date, cheque and bank: the old one is no
        // longer live); a posted voucher's Duplicate is a new payment.
        $draft = $reverse->copy($voucher, $request->user(), correcting: $voucher->status === VoucherStatus::Reversed);

        return redirect()->route('accounting.vouchers.edit', $draft)->with('success', 'Copied into a new draft.');
    }

    public function destroy(Voucher $voucher): RedirectResponse
    {
        if (! $voucher->isDraft()) {
            return back()->with('error', "{$voucher->title()} is posted. A posted voucher is reversed, never deleted.");
        }

        $type = $voucher->type->value;

        // ⚠⚠ ACC-003 (14 Oct 2026): checked again under the row lock. A Post
        // committed between the check above and this delete left its journal
        // in the ledger pointing at no voucher, which no screen can reverse.
        $deleted = DB::transaction(function () use ($voucher) {
            $locked = Voucher::query()->lockForUpdate()->find($voucher->id);

            if ($locked === null || ! $locked->isDraft()) {
                return false;
            }

            $locked->delete();

            return true;
        });

        if (! $deleted) {
            return back()->with('error', "{$voucher->title()} was posted or sent for approval a moment ago, so it was not deleted. A posted voucher is reversed, never deleted.");
        }

        return redirect()->route('accounting.vouchers.index', $type)->with('success', 'Draft deleted.');
    }

    public function uploadEvidence(Request $request, Voucher $voucher, StoreAttachment $store): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,csv,xls,xlsx', 'max:5120'],
        ]);

        $store->handle($voucher, $request->file('file'), Voucher::EVIDENCE_COLLECTION, $request->user());

        return back()->with('success', "Attached to {$voucher->title()}.");
    }

    public function deleteEvidence(Request $request, Voucher $voucher, Attachment $attachment, DeleteAttachment $delete): RedirectResponse
    {
        abort_unless(
            $attachment->attachable_type === $voucher->getMorphClass() && $attachment->attachable_id === $voucher->id,
            404,
        );

        // ⚠ ACC-008 (14 Oct 2026): the receipt or bank slip behind a POSTED
        // payment could be deleted by any preparer, file and row, with no
        // trace. On a draft it is still just paperwork being assembled. Once
        // the voucher has left draft, only someone who may post vouchers can
        // remove it, and the removal is written to the voucher's history.
        if (! $voucher->isDraft()) {
            if (! $request->user()->can('accounting.voucher.post')) {
                return back()->with('error', "{$voucher->title()} is no longer a draft, so its proof stays. Ask whoever posts vouchers if a wrong file was attached.");
            }

            activity()
                ->performedOn($voucher)
                ->causedBy($request->user())
                ->withProperties(['file' => $attachment->original_name ?? $attachment->path, 'status' => $voucher->status->value])
                ->log('voucher proof removed');
        }

        $delete->handle($attachment);

        return back()->with('success', 'Attachment removed.');
    }

    private function afterSave(Request $request, Voucher $voucher, SubmitOrPostVoucher $post): RedirectResponse
    {
        // "Save and post" in one press, for whoever may post. Anyone else gets
        // a saved draft and is told so - never a silent downgrade.
        if ($request->boolean('post')) {
            if (! $request->user()->can('accounting.voucher.post')) {
                return redirect()->route('accounting.vouchers.show', $voucher)
                    ->with('success', 'Saved as a draft. Posting it needs someone with permission to post vouchers.');
            }

            try {
                $result = $post->handle($voucher, $request->user());
            } catch (ValidationException $e) {
                // ⚠ The draft IS saved. Landing back on the blank "new" form
                // would invite a second press and a second draft, so the
                // refusal is shown on the saved draft's own edit form.
                return redirect()->route('accounting.vouchers.edit', $voucher)
                    ->withErrors($e->errors())
                    ->with('error', 'Saved as a draft, but it could not be posted yet.');
            }

            return redirect()->route('accounting.vouchers.show', $result['voucher'])->with('success', $this->postedMessage($result));
        }

        return redirect()->route('accounting.vouchers.show', $voucher)->with('success', 'Draft saved.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        // ⭐ Corrections wave 2: the rules live in VoucherForm, which a posted
        // voucher's Correct reads too.
        return $request->validate(VoucherForm::rules());
    }

    /**
     * ⭐ Corrections wave 2 (24 Oct 2026; FINAL-PLAN 3.1): the voucher form,
     * pre-filled, in "Correct" mode. Nothing is saved from here: "Preview and
     * correct" opens the action dialog, which previews the dates, the effect
     * and what stays, then posts to records.act (the corrections process).
     */
    public function correct(Request $request, Voucher $voucher, VoucherActionPolicy $policy): Response|RedirectResponse
    {
        $availability = $policy->availability($voucher, $request->user(), DocumentAction::Correct);

        if ($voucher->status !== VoucherStatus::Posted) {
            return redirect()->route('accounting.vouchers.show', $voucher)
                ->with('error', $availability->blockers[0]->message ?? "{$voucher->title()} cannot be corrected.");
        }

        return $this->form($voucher->type, $voucher, null, [
            'id' => $voucher->id,
            'number' => $voucher->voucher_number,
            'total' => $voucher->total()->format(),
            'type' => VoucherActionPolicy::TYPE,
            'availability' => $availability->toArray(),
            'reasons' => CorrectionScreen::reasons('correction'),
        ]);
    }

    /** ⭐ Corrections wave 2: Fix details on a posted voucher. Text only; no journal moves. */
    public function fixDetails(Request $request, Voucher $voucher, FixVoucherDetails $action): RedirectResponse
    {
        abort_unless($request->user()->canAny(VoucherActionPolicy::FIX_PERMISSIONS), 403);

        $data = $request->validate([
            ...$this->actionReasonRules('fix_details'),
            'narration' => ['nullable', 'string', 'max:1000'],
            'counterparty' => ['nullable', 'string', 'max:255'],
            'instrument_bank' => ['nullable', 'string', 'max:120'],
            'lines' => ['nullable', 'array', 'max:100'],
            'lines.*' => ['nullable', 'string', 'max:255'],
            'seen' => ['required', 'string', 'size:64'],
        ], $this->actionReasonMessages());

        $fixed = $action->handle(
            $voucher,
            array_intersect_key($data, array_flip(['narration', 'counterparty', 'instrument_bank', 'lines'])),
            $this->actionReason($request),
            $request->user(),
            $data['seen'],
        );

        return back()->with('success', "{$fixed->title()}: details fixed. The change and your reason are in its history.");
    }

    private function type(string $type): VoucherType
    {
        return VoucherType::tryFrom($type) ?? abort(404);
    }

    /**
     * @return array<string, mixed>
     */
    /** The Accounts > Vouchers tab of a voucher type (accounts-sections.ts). */
    private function typeTab(VoucherType $type): string
    {
        return '/accounting/vouchers/'.$type->value;
    }

    private function typeProps(VoucherType $type): array
    {
        return [
            'value' => $type->value,
            'label' => $type->label(),
            'plural' => $type->plural(),
            'prefix' => $type->prefix(),
            'fixed_side' => $type->fixedLineSide(),
            'uses_money_account' => $type->usesMoneyAccount(),
            'has_lines' => $type->hasLines(),
            'counterparty_label' => $type->counterpartyLabel(),
        ];
    }

    private function form(VoucherType $type, ?Voucher $voucher, ?array $prefill = null, ?array $correcting = null): Response
    {
        $voucher?->load('lines');
        $partyNouns = array_map(fn (string $class) => PartyLedgers::noun($class), PartyLedgers::voucherPickable());

        return Inertia::render('accounting/vouchers/form', [
            'type' => $this->typeProps($type),
            // A new voucher opened from a shortcut (owner reimbursement) starts
            // with these fields filled; null otherwise.
            'prefill' => $voucher === null ? $prefill : null,
            // ⭐ Corrections wave 2: set when the form opens to Correct a posted
            // voucher; the page then previews and corrects instead of saving.
            'correcting' => $correcting,
            // A draft's edit address (/accounting/vouchers/doc/{id}/edit)
            // names no type: the form lights its type's tab (round 3, D2).
            'sectionTab' => $this->typeTab($type),
            'voucher' => $voucher === null ? null : [
                'id' => $voucher->id,
                'voucher_date' => $voucher->voucher_date->toDateString(),
                'money_account_id' => $voucher->money_account_id,
                'to_money_account_id' => $voucher->to_money_account_id,
                'counterparty' => $voucher->counterparty,
                'party' => $voucher->party_type === null ? null : strtolower(class_basename($voucher->party_type)).':'.$voucher->party_id,
                'narration' => $voucher->narration,
                'instrument_type' => $voucher->instrument_type,
                'instrument_number' => $voucher->instrument_number,
                'instrument_date' => $voucher->instrument_date?->toDateString(),
                'instrument_bank' => $voucher->instrument_bank,
                'amount' => $voucher->total()->toTaka(),
                'copied_from' => $voucher->copiedFrom?->voucher_number,
                'lines' => $voucher->lines->map(fn ($line) => [
                    'account_id' => $line->account_id,
                    'side' => $line->side,
                    'amount' => $line->amount()->toTaka(),
                    'narration' => $line->narration,
                    'party_id' => $line->party_id,
                    'loan_contract_id' => $line->loan_contract_id,
                    'bill_wise' => $line->bill_wise,
                    'tds_rate_id' => $line->tds_rate_id,
                    'vds_rate_id' => $line->vds_rate_id,
                ]),
            ],
            'today' => Carbon::today()->toDateString(),
            // ⭐ A CONTRA may move money OUT OF a payment gateway (accountant
            // meeting 19 Sep, F4: an SSLCommerz payout reaching the bank had no
            // screen). The gateway is usable as a SOURCE only, and only here: it
            // is greyed under "Moved to" and on every other voucher.
            //
            // ⚠ EVERY account in Bank & cash is sent, the unusable ones with the
            // reason (Joy, 21 Sep 2026: an account set up there but missing from
            // the list, with nothing saying why, reads as a broken form). Usable
            // ones first; PostVoucher refuses the rest whatever the form sends.
            //
            // ⭐ 26 Sep 2026 (the accountant: "money flows by heading"): every
            // account is shown AS ITS LEDGER HEAD, grouped Cash / Bank / Mobile,
            // because each cash, bank and wallet account owns exactly one head
            // (MoneyLedgers). The server still resolves head to money account,
            // so the cash book and the till guard are untouched.
            'moneyAccounts' => function () use ($type) {
                $accounts = MoneyAccount::query()->orderBy('name')->get();
                $heads = Account::query()->findMany($accounts->pluck('account_id')->filter())->keyBy('id');

                return $accounts
                    ->map(fn (MoneyAccount $account) => [
                        'id' => $account->id,
                        'label' => $account->label(),
                        'ledger' => $heads->get($account->account_id)?->label(),
                        'type' => $account->type->value,
                        'group' => match ($account->type) {
                            MoneyAccountType::Cash => 'Cash in hand',
                            MoneyAccountType::Bank => 'Bank accounts',
                            MoneyAccountType::Mfs => 'Mobile wallets',
                            MoneyAccountType::Gateway => 'Payment gateways',
                            MoneyAccountType::EmployeeCash => 'Cash with employees',
                            default => 'Courier accounts',
                        },
                        'hint' => $this->moneyAccountHint($type, $account),
                    ])
                    ->sortBy(fn (array $row) => $row['hint'] === null ? 0 : 1)
                    ->values();
            },
            // Every head of the chart, the unusable ones greyed with where they
            // are posted instead (VoucherAccounts::catalogue).
            'accounts' => fn () => VoucherAccounts::catalogue(Carbon::today(), $type)->map(fn (array $row) => [
                'id' => $row['account']->id,
                'label' => $row['account']->label(),
                'party' => $partyNouns[$row['account']->system_slug ?? ''] ?? null,
                'usable' => $row['usable'],
                'heading' => $row['heading'],
                'hint' => $row['hint'],
                // ⭐ The loan heads' note (14 Oct 2026), as a key the form
                // translates: internal money is an inter-business transfer,
                // 11752 is for related parties only, 2220 only for a documented
                // owner loan (CounterpartyRules::guidanceKey).
                'loan_rule' => CounterpartyRules::guidanceKey($row['account']),
            ]),
            // ⭐ `head`: the party's own ledger head, which "Paid to / Received
            // from" places on the first line (party ledgers, 20 Oct 2026).
            'suppliers' => fn () => Supplier::query()->orderBy('name')->get(['id', 'name', 'account_id', 'supplier_type'])
                ->map(fn ($s) => ['id' => $s->id, 'label' => $s->name, 'head' => $s->account_id, 'supplier_type' => $s->supplier_type]),
            'customers' => fn () => Customer::query()->listable()->orderBy('name')->get(['id', 'name', 'account_id'])->map(fn ($c) => ['id' => $c->id, 'label' => $c->name, 'head' => $c->account_id]),
            // Every employee, separated ones too: a person who left may still
            // owe an advance or be owed a deduction refund.
            'employees' => fn () => Employee::query()->orderBy('first_name')->orderBy('last_name')->get()
                ->map(fn (Employee $e) => ['id' => $e->id, 'label' => PartyLedgers::label($e).' ('.$e->employee_code.')'.($e->status !== EmployeeStatus::Separated ? '' : ' (left)'), 'head' => $e->account_id]),
            // ⭐ TDS / VDS a supplier payment line may hold back: the owner's
            // own rates (Purchases > Tax rates), never a hard-coded one.
            'withholdingRates' => fn () => $type !== VoucherType::Payment ? [] : WithholdingRate::query()->orderBy('kind')->orderBy('name')->get()
                ->map(fn (WithholdingRate $r) => [
                    'id' => $r->id, 'kind' => $r->kind, 'label' => $r->label(), 'rate' => (float) $r->rate,
                    'supplier_type' => $r->supplier_type,
                    'from' => $r->effective_from?->toDateString(), 'to' => $r->effective_to?->toDateString(),
                ]),
            // ⭐ The lenders and borrowers a loan line names (loans plan v2, 14
            // Oct 2026). Group-wide, so the same list in every business.
            'counterparties' => fn () => JournalController::counterpartyOptions(),
            // ⭐ The register loans a loan line names (loans plan v2, phase 2).
            'loans' => fn () => LoanOptions::forPickers(),
            'instrumentTypes' => Voucher::INSTRUMENT_TYPES,
        ]);
    }

    /**
     * The receipts and payments this voucher created, and what each paid.
     *
     * @return list<array{party: string, amount: string, against: list<string>}>
     */
    private function settles(Voucher $voucher): array
    {
        $receipts = CustomerReceipt::query()->where('voucher_id', $voucher->id)->with(['customer:id,name', 'allocations.invoice:id,invoice_number'])->get()
            ->map(fn (CustomerReceipt $receipt) => [
                'party' => (string) $receipt->customer?->name,
                'amount' => Money::ofTaka((string) $receipt->amount)->format(),
                'against' => $receipt->allocations->map(fn ($a) => $a->invoice?->invoice_number.' '.Money::ofTaka((string) $a->amount)->format())->values()->all(),
            ]);

        $payments = SupplierPayment::query()->where('voucher_id', $voucher->id)->with(['supplier:id,name', 'allocations.bill:id,bill_number'])->get()
            ->map(fn (SupplierPayment $payment) => [
                'party' => (string) $payment->supplier?->name,
                'amount' => Money::ofTaka((string) $payment->amount)->format(),
                'against' => $payment->allocations->map(fn ($a) => $a->bill?->bill_number.' '.Money::ofTaka((string) $a->amount)->format())->values()->all(),
            ]);

        // ⭐ Party ledgers (20 Oct 2026): the refunds and adjustment a voucher made.
        $refunds = collect([
            ...SupplierRefund::query()->where('voucher_id', $voucher->id)->with('supplier:id,name')->get()
                ->map(fn (SupplierRefund $r) => ['party' => (string) $r->supplier?->name, 'amount' => Money::ofTaka((string) $r->amount)->format(), 'against' => ["Refund {$r->number} of what they owed us"]]),
            ...SupplierAdvanceRefund::query()->where('voucher_id', $voucher->id)->with(['supplier:id,name', 'advance:id,number'])->get()
                ->map(fn (SupplierAdvanceRefund $r) => ['party' => (string) $r->supplier?->name, 'amount' => Money::ofTaka((string) $r->amount)->format(), 'against' => ["Advance refund {$r->number}".($r->advance ? " of {$r->advance->number}" : ' on account')]]),
            ...CustomerRefund::query()->where('voucher_id', $voucher->id)->with(['customer:id,name', 'salesReturn:id,return_number', 'salesOrder:id,order_number'])->get()
                ->map(fn (CustomerRefund $r) => ['party' => (string) $r->customer?->name, 'amount' => Money::ofTaka((string) $r->amount)->format(), 'against' => ['Refund for '.($r->salesReturn?->return_number ?? $r->salesOrder?->order_number ?? 'the customer')]]),
            ...PartyAdjustment::query()->where('voucher_id', $voucher->id)->with(['customer:id,name', 'supplier:id,name', 'allocations.invoice:id,invoice_number', 'allocations.bill:id,bill_number'])->get()
                ->map(fn (PartyAdjustment $a) => [
                    'party' => trim(($a->supplier?->name ?? '').' '.($a->customer?->name ?? '')),
                    'amount' => $a->money()->format(),
                    'against' => $a->allocations->map(fn ($x) => ($x->invoice?->invoice_number ?? $x->bill?->bill_number).' '.Money::ofTaka((string) $x->amount)->format())->prepend($a->label())->values()->all(),
                ]),
        ]);

        return $receipts->concat($payments)->concat($refunds)->values()->all();
    }

    /**
     * ⭐ Tally's bill-wise details for one party on one voucher (party
     * ledgers, 20 Oct 2026): what the line can be against, what is open on
     * each, and whether the rest may stay as an advance. Read only; the form
     * asks it when a party's head is on a line, and PostVoucher checks the
     * same list.
     */
    public function partyItems(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(VoucherType::class)],
            'head' => ['required', 'integer'],
            'date' => ['nullable', 'date'],
        ]);

        $type = VoucherType::from($data['type']);
        $head = Account::query()->find($data['head']);
        $party = $head === null || $head->is_postable ? null : PartyHeads::entityFor($head);
        $mode = $party === null ? null : PartyBillWise::mode($type, $party);

        if ($party === null || $mode === null) {
            return response()->json(['mode' => null, 'party' => null, 'items' => [], 'rest_is_advance' => false]);
        }

        $date = Carbon::parse($data['date'] ?? Carbon::today())->startOfDay();

        return response()->json([
            'mode' => $mode,
            'party' => ['type' => strtolower(class_basename($party)), 'id' => $party->getKey(), 'name' => PartyLedgers::label($party)],
            'rest_is_advance' => PartyBillWise::restIsAdvance($mode),
            'items' => PartyBillWise::present(PartyBillWise::items($mode, $party, $date)),
        ]);
    }

    /** Why this money account cannot be chosen on this voucher type, or null when it can. */
    private function moneyAccountHint(VoucherType $type, MoneyAccount $account): ?string
    {
        return match (true) {
            ! $account->is_active => 'Switched off in Bank & cash.',
            $account->account_id === null => 'Set "Reports under" for it in Bank & cash first.',
            $account->isCourier() => 'Courier money: settled from Sales > Courier payouts.',
            // A contra may move money OUT of a gateway; the form greys it under "Moved to".
            $account->isGateway() && $type !== VoucherType::Contra => 'Online-sale money: moved to the bank by a Contra voucher.',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function document(Voucher $voucher): array
    {
        $voucher->load([
            'lines.account:id,code,name', 'lines.party', 'moneyAccount', 'toMoneyAccount', 'journal:id,journal_number',
            'reversalJournal:id,journal_number,journal_date', 'createdBy:id,name', 'postedBy:id,name', 'reversedBy:id,name',
            'copiedFrom:id,voucher_number,type', 'copies:id,voucher_number,status,copied_from_id', 'attachments',
        ]);

        return [
            'id' => $voucher->id,
            'type' => $this->typeProps($voucher->type),
            'title' => $voucher->title(),
            'number' => $voucher->voucher_number,
            'status' => $voucher->status->value,
            'date' => $voucher->voucher_date->toDateString(),
            'counterparty' => $voucher->counterparty,
            'narration' => $voucher->narration,
            'money_account' => $voucher->moneyAccount?->label(),
            'to_money_account' => $voucher->toMoneyAccount?->label(),
            'instrument' => [
                'type' => $voucher->instrument_type,
                'number' => $voucher->instrument_number,
                'date' => $voucher->instrument_date?->toDateString(),
                'bank' => $voucher->instrument_bank,
            ],
            'total' => $voucher->total()->format(),
            'total_in_words' => AmountInWords::taka($voucher->total()),
            'lines' => $voucher->lines->map(fn ($line) => [
                'account' => $line->account?->label(),
                'party' => PartyLedgers::label($line->party, $line->party_type, $line->party_id),
                'loan' => $line->loan?->loan_number,
                'narration' => $line->narration,
                'debit' => $line->isDebit() ? $line->amount()->format() : '',
                'credit' => $line->isDebit() ? '' : $line->amount()->format(),
            ]),
            'journal' => $voucher->journal === null ? null : ['id' => $voucher->journal->id, 'number' => $voucher->journal->journal_number],
            // What a customer or supplier line settled (finance decision C3).
            'settles' => $this->settles($voucher),
            // Whether the reader may give the second approval (never its preparer).
            'can_approve' => $voucher->isPendingApproval() && auth()->user() !== null
                && ($voucher->approvalRequest?->canBeActionedBy(auth()->user()) ?? false),
            'reversal' => $voucher->reversalJournal === null ? null : [
                'id' => $voucher->reversalJournal->id,
                'number' => $voucher->reversalJournal->journal_number,
                'date' => $voucher->reversalJournal->journal_date->toDateString(),
                'reason' => $voucher->reversal_reason,
                'by' => $voucher->reversedBy?->name,
            ],
            'copied_from' => $voucher->copiedFrom === null ? null : ['id' => $voucher->copiedFrom->id, 'number' => $voucher->copiedFrom->voucher_number],
            'copies' => $voucher->copies->map(fn (Voucher $copy) => ['id' => $copy->id, 'title' => $copy->title(), 'status' => $copy->status->value]),
            'prepared_by' => $voucher->createdBy?->name,
            'posted_by' => $voucher->postedBy?->name,
            'posted_at' => $voucher->posted_at?->toDayDateTimeString(),
            'attachments' => $voucher->attachments->where('collection', Voucher::EVIDENCE_COLLECTION)->values()->map(fn (Attachment $file) => [
                'id' => $file->id,
                'name' => $file->original_name,
                'url' => route('attachments.show', $file),
            ]),
            'current_business_id' => app(BusinessContext::class)->id(),
            // ⭐ Corrections wave 2 (24 Oct 2026): the buttons the policy draws
            // (Withdraw, Fix details, Correct, Reverse, each greyed with its
            // reason when blocked), the correction chain, the reason lists, and
            // what Fix details shows and seals against a stale save.
            'corrections' => auth()->user() === null ? null : [
                ...CorrectionScreen::for($voucher, app(VoucherActionPolicy::class), auth()->user()),
                'withdrawal_reasons' => CorrectionScreen::reasons('withdrawal'),
                'approval_request_id' => $voucher->isPendingApproval() ? $voucher->approvalRequest?->id : null,
                'allow_today' => ! AccountingPeriod::isClosedOn($voucher->voucher_date->copy()->startOfDay()),
                'fix' => [
                    'seen' => FixVoucherDetails::fingerprint($voucher),
                    'narration' => $voucher->narration,
                    'counterparty' => $voucher->counterparty,
                    'counterparty_is_party' => $voucher->party_type !== null,
                    'instrument_bank' => $voucher->instrument_bank,
                    'lines' => $voucher->lines->map(fn ($line) => ['id' => $line->id, 'line_no' => $line->line_no, 'account' => $line->account?->label(), 'narration' => $line->narration])->values(),
                ],
            ],
        ];
    }
}
