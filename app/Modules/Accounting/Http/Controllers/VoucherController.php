<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Actions\PostVoucher;
use App\Modules\Accounting\Actions\ReverseVoucher;
use App\Modules\Accounting\Actions\SaveVoucherDraft;
use App\Modules\Accounting\Enums\VoucherStatus;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Accounting\Support\AmountInWords;
use App\Modules\Accounting\Support\VoucherAccounts;
use App\Modules\Foundation\Actions\DeleteAttachment;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
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
    public function index(Request $request, string $type): Response
    {
        $voucherType = $this->type($type);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(VoucherStatus::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $vouchers = Voucher::query()
            ->where('type', $voucherType->value)
            ->with(['moneyAccount:id,name,account_number', 'toMoneyAccount:id,name,account_number', 'lines.account:id,code,name', 'createdBy:id,name'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('voucher_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('voucher_date', '<=', $to))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

                $query->where(fn ($inner) => $inner
                    ->where('voucher_number', 'like', $like)
                    ->orWhere('counterparty', 'like', $like)
                    ->orWhere('narration', 'like', $like)
                    ->orWhere('instrument_number', 'like', $like));
            })
            // Drafts first (they are work waiting), then newest.
            ->orderByRaw("CASE WHEN status = 'draft' THEN 0 ELSE 1 END")
            ->orderByDesc('voucher_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('accounting/vouchers/index', [
            'type' => $this->typeProps($voucherType),
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

    public function create(string $type): Response
    {
        return $this->form($this->type($type), null);
    }

    public function edit(Voucher $voucher): Response|RedirectResponse
    {
        if (! $voucher->isDraft()) {
            return redirect()->route('accounting.vouchers.show', $voucher)
                ->with('error', "{$voucher->title()} is posted and cannot be changed. Use Reverse and copy.");
        }

        return $this->form($voucher->type, $voucher);
    }

    public function store(Request $request, string $type, SaveVoucherDraft $save, PostVoucher $post): RedirectResponse
    {
        $voucher = $save->handle($this->type($type), $this->validated($request), $request->user());

        return $this->afterSave($request, $voucher, $post);
    }

    public function update(Request $request, Voucher $voucher, SaveVoucherDraft $save, PostVoucher $post): RedirectResponse
    {
        $voucher = $save->handle($voucher->type, $this->validated($request), $request->user(), $voucher);

        return $this->afterSave($request, $voucher, $post);
    }

    public function show(Voucher $voucher): Response
    {
        return Inertia::render('accounting/vouchers/show', [
            'voucher' => $this->document($voucher),
        ]);
    }

    public function print(Voucher $voucher): Response
    {
        abort_if($voucher->isDraft(), 404);

        return Inertia::render('accounting/vouchers/print', [
            'voucher' => $this->document($voucher),
            'business' => Business::find($voucher->business_id)?->only(['name', 'address', 'phone', 'email']),
        ]);
    }

    public function post(Request $request, Voucher $voucher, PostVoucher $post): RedirectResponse
    {
        $voucher = $post->handle($voucher, $request->user());

        return redirect()->route('accounting.vouchers.show', $voucher)->with('success', "{$voucher->voucher_number} posted.");
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
        $draft = $reverse->copy($voucher, $request->user());

        return redirect()->route('accounting.vouchers.edit', $draft)->with('success', 'Copied into a new draft.');
    }

    public function destroy(Voucher $voucher): RedirectResponse
    {
        if (! $voucher->isDraft()) {
            return back()->with('error', "{$voucher->title()} is posted. A posted voucher is reversed, never deleted.");
        }

        $type = $voucher->type->value;
        $voucher->delete();

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

    public function deleteEvidence(Voucher $voucher, Attachment $attachment, DeleteAttachment $delete): RedirectResponse
    {
        abort_unless(
            $attachment->attachable_type === $voucher->getMorphClass() && $attachment->attachable_id === $voucher->id,
            404,
        );

        $delete->handle($attachment);

        return back()->with('success', 'Attachment removed.');
    }

    private function afterSave(Request $request, Voucher $voucher, PostVoucher $post): RedirectResponse
    {
        // "Save and post" in one press, for whoever may post. Anyone else gets
        // a saved draft and is told so - never a silent downgrade.
        if ($request->boolean('post')) {
            if (! $request->user()->can('accounting.voucher.post')) {
                return redirect()->route('accounting.vouchers.show', $voucher)
                    ->with('success', 'Saved as a draft. Posting it needs someone with permission to post vouchers.');
            }

            $voucher = $post->handle($voucher, $request->user());

            return redirect()->route('accounting.vouchers.show', $voucher)->with('success', "{$voucher->voucher_number} posted.");
        }

        return redirect()->route('accounting.vouchers.show', $voucher)->with('success', 'Draft saved.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'voucher_date' => ['required', 'date'],
            'money_account_id' => ['nullable', 'integer'],
            'to_money_account_id' => ['nullable', 'integer'],
            'counterparty' => ['nullable', 'string', 'max:255'],
            'narration' => ['nullable', 'string', 'max:1000'],
            'instrument_type' => ['nullable', Rule::in(Voucher::INSTRUMENT_TYPES)],
            'instrument_number' => ['nullable', 'string', 'max:60'],
            'instrument_date' => ['nullable', 'date'],
            'instrument_bank' => ['nullable', 'string', 'max:120'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'lines' => ['nullable', 'array', 'max:100'],
            'lines.*.account_id' => ['nullable', 'integer'],
            'lines.*.side' => ['nullable', Rule::in(['debit', 'credit'])],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'lines.*.narration' => ['nullable', 'string', 'max:255'],
            'lines.*.party_id' => ['nullable', 'integer'],
        ]);
    }

    private function type(string $type): VoucherType
    {
        return VoucherType::tryFrom($type) ?? abort(404);
    }

    /**
     * @return array<string, mixed>
     */
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

    private function form(VoucherType $type, ?Voucher $voucher): Response
    {
        $voucher?->load('lines');
        $partySlugs = array_flip(array_keys(VoucherAccounts::PARTY_ACCOUNTS));

        return Inertia::render('accounting/vouchers/form', [
            'type' => $this->typeProps($type),
            'voucher' => $voucher === null ? null : [
                'id' => $voucher->id,
                'voucher_date' => $voucher->voucher_date->toDateString(),
                'money_account_id' => $voucher->money_account_id,
                'to_money_account_id' => $voucher->to_money_account_id,
                'counterparty' => $voucher->counterparty,
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
                ]),
            ],
            'today' => Carbon::today()->toDateString(),
            'moneyAccounts' => fn () => MoneyAccount::query()->handled()->where('is_active', true)->whereNotNull('account_id')
                ->orderBy('name')->get()
                ->map(fn (MoneyAccount $account) => ['id' => $account->id, 'label' => $account->label(), 'type' => $account->type->value]),
            'accounts' => fn () => VoucherAccounts::selectable(Carbon::today())->map(fn ($account) => [
                'id' => $account->id,
                'label' => $account->label(),
                'party' => isset($partySlugs[$account->system_slug])
                    ? ($account->system_slug === 'supplier_advances' ? 'supplier' : 'customer')
                    : null,
            ]),
            'suppliers' => fn () => Supplier::query()->orderBy('name')->get(['id', 'name'])->map(fn ($s) => ['id' => $s->id, 'label' => $s->name]),
            'customers' => fn () => Customer::query()->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['id' => $c->id, 'label' => $c->name]),
            'instrumentTypes' => Voucher::INSTRUMENT_TYPES,
        ]);
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
                'party' => $line->party?->getAttribute('name'),
                'narration' => $line->narration,
                'debit' => $line->isDebit() ? $line->amount()->format() : '',
                'credit' => $line->isDebit() ? '' : $line->amount()->format(),
            ]),
            'journal' => $voucher->journal === null ? null : ['id' => $voucher->journal->id, 'number' => $voucher->journal->journal_number],
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
        ];
    }
}
