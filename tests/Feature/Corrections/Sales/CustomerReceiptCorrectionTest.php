<?php

use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\ApprovalRoute;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\CorrectionService;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use App\Modules\Foundation\Corrections\Exceptions\CorrectionBlockedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\CorrectionLink;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Sales\Corrections\CustomerReceiptActionPolicy;
use App\Modules\Sales\Models\CustomerReceipt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\SalesWorld;

/**
 * ⭐ Corrections wave 1: a customer receipt, Reverse and Correct
 * (FINAL-PLAN 3.2 "Customer receipt"). The receipt is KEPT and marked; its
 * mirror journal lands on its own date; the invoices it paid owe again; the
 * corrected receipt pays what the form says. AR per customer keeps agreeing
 * with the documents throughout.
 */
beforeEach(function () {
    $this->s = SalesWorld::make();
    $this->invoice = $this->s->invoice($this->s->challan(null, '10'), '850');
    $this->receipt = $this->s->receive($this->invoice, '8500');
    $this->policy = app(DocumentPolicyRegistry::class)->for(CustomerReceiptActionPolicy::TYPE);
});

afterEach(fn () => Carbon::setTestNow());

function receiptCorrection(SalesWorld $s, CustomerReceipt $receipt, array $changes = []): array
{
    return array_replace([
        'customer_id' => $receipt->customer_id,
        'money_account_id' => $receipt->money_account_id,
        'received_on' => $receipt->received_on->toDateString(),
        'amount' => (string) (float) $receipt->amount,
        'reference' => $receipt->reference,
        'note' => $receipt->note,
        'allocations' => $receipt->allocations->map(fn ($a) => ['sales_invoice_id' => $a->sales_invoice_id, 'amount' => (string) (float) $a->amount])->all(),
    ], $changes);
}

it('reverses on its own date, keeps and marks the receipt, and the invoice owes again', function () {
    $arBefore = $this->s->balance('accounts_receivable');

    $result = $this->s->submit($this->s->input(CustomerReceiptActionPolicy::TYPE, $this->receipt, DocumentAction::Reverse), $this->s->w->financeB);

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($result->reversalJournal->journal_date->toDateString())->toBe('2026-10-14');

    $receipt = $this->receipt->fresh();
    expect($receipt->isReversed())->toBeTrue()
        ->and($receipt->allocations()->count())->toBe(0)
        ->and($receipt->reversed_allocations[0]['invoice_number'])->toBe($this->invoice->invoice_number)
        ->and($this->invoice->fresh()->outstanding()->toTaka())->toBe('8500.00')
        ->and($this->s->balance('accounts_receivable'))->toBe($arBefore + 850000)
        ->and($this->s->w->balance('11201', '2026-10-01', '2026-10-31'))->toBe(0)
        ->and($this->s->reconciliationProblems())->toBe([]);
});

it('refuses a reverse without a reason, from the server', function () {
    $this->actingAs($this->s->w->financeB)
        ->postJson('/records/'.CustomerReceiptActionPolicy::TYPE."/{$this->receipt->id}/reverse", ['reason_text' => 'no', 'idempotency_key' => (string) Str::uuid(), 'preview_token' => 'x'])
        ->assertStatus(422)->assertJsonValidationErrors('reason_text');

    expect($this->receipt->fresh()->isReversed())->toBeFalse();
});

it('corrects the amount and the date: the new receipt pays what the form says and is linked', function () {
    $replacement = receiptCorrection($this->s, $this->receipt->load('allocations'), [
        'amount' => '8000', 'received_on' => '2026-10-15',
        'allocations' => [['sales_invoice_id' => $this->invoice->id, 'amount' => '8000']],
    ]);

    $result = $this->s->submit($this->s->input(CustomerReceiptActionPolicy::TYPE, $this->receipt, DocumentAction::Correct, $replacement), $this->s->w->financeB);

    $successor = $result->successor->fresh('allocations');
    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and((float) $successor->amount)->toBe(8000.0)
        ->and($successor->received_on->toDateString())->toBe('2026-10-15')
        ->and((float) $successor->allocations->sum('amount'))->toBe(8000.0)
        ->and($this->invoice->fresh()->outstanding()->toTaka())->toBe('500.00')
        ->and(CorrectionLink::sole()->successor_id)->toBe($successor->id)
        ->and($this->s->reconciliationProblems())->toBe([]);
});

it('corrects the money account', function () {
    $replacement = receiptCorrection($this->s, $this->receipt->load('allocations'), ['money_account_id' => $this->s->w->cash->id]);

    $this->s->submit($this->s->input(CustomerReceiptActionPolicy::TYPE, $this->receipt, DocumentAction::Correct, $replacement), $this->s->w->financeB);

    expect($this->s->w->balance('11201'))->toBe(0)
        ->and($this->s->balance('cash_in_hand'))->toBe(850000);
});

it('refuses a correction that changes nothing, or that pays an invoice more than it owes', function () {
    $same = receiptCorrection($this->s, $this->receipt->load('allocations'));
    expect(fn () => $this->s->preview($this->s->input(CustomerReceiptActionPolicy::TYPE, $this->receipt, DocumentAction::Correct, $same), $this->s->w->financeB))
        ->toThrow(ValidationException::class, 'Nothing was changed');

    $over = receiptCorrection($this->s, $this->receipt, ['amount' => '9000', 'allocations' => [['sales_invoice_id' => $this->invoice->id, 'amount' => '9000']]]);
    expect(fn () => $this->s->preview($this->s->input(CustomerReceiptActionPolicy::TYPE, $this->receipt, DocumentAction::Correct, $over), $this->s->w->financeB))
        ->toThrow(ValidationException::class, 'has only');
});

it('blocks a receipt a voucher made, one into a courier account, and one matched on the bank statement', function () {
    DB::table('customer_receipts')->where('id', $this->receipt->id)->update(['voucher_id' => null]);
    $journal = Journal::query()->where('source_type', $this->receipt->getMorphClass())->where('source_id', $this->receipt->id)->sole();
    $line = DB::table('journal_lines')->where('journal_id', $journal->id)->where('amount_minor', '>', 0)->value('id');
    $import = DB::table('bank_statement_imports')->insertGetId(['business_id' => $this->s->w->business->id, 'money_account_id' => $this->s->w->bank->id, 'filename' => 'b.csv', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('bank_statement_lines')->insert(['business_id' => $this->s->w->business->id, 'import_id' => $import, 'money_account_id' => $this->s->w->bank->id, 'txn_date' => '2026-10-14', 'amount' => '8500', 'match_status' => 'matched', 'matched_journal_line_id' => $line, 'created_at' => now(), 'updated_at' => now()]);

    $codes = collect($this->policy->availability($this->receipt->fresh(), $this->s->w->financeB, DocumentAction::Reverse)->blockers)->pluck('code');
    expect($codes)->toContain('bank_matched');

    $courier = MoneyAccount::create(['business_id' => $this->s->w->business->id, 'name' => 'Pathao', 'type' => 'courier', 'is_active' => true, 'account_id' => $this->s->w->bank->account_id]);
    DB::table('customer_receipts')->where('id', $this->receipt->id)->update(['money_account_id' => $courier->id]);
    $codes = collect($this->policy->availability($this->receipt->fresh(), $this->s->w->financeB, DocumentAction::Reverse)->blockers)->pluck('code');
    expect($codes)->toContain('courier_or_gateway');
});

it('a Manager and a cashier have no receipt correction, and get 403 from the server', function () {
    foreach ([$this->s->w->manager, $this->s->cashier] as $user) {
        expect($this->policy->actionsFor($this->receipt, $user)->actions)->toBe([]);

        $this->actingAs($user)
            ->postJson('/records/'.CustomerReceiptActionPolicy::TYPE."/{$this->receipt->id}/reverse/preview", ['reason_text' => 'Wrong amount typed', 'idempotency_key' => (string) Str::uuid()])
            ->assertForbidden();
    }
});

it('a closed month: Finance asks the Owner; the Owner reverses it on its own date without unlocking the month', function () {
    $this->s->w->closeThrough('2026-10-14');

    $asked = $this->s->submit($this->s->input(CustomerReceiptActionPolicy::TYPE, $this->receipt, DocumentAction::Reverse), $this->s->w->financeB);
    expect($asked->outcome)->toBe(CorrectionOutcome::Requested)
        ->and($asked->request->approval_route)->toBe(ApprovalRoute::OwnerClosedPeriod);

    $this->actingAs($this->s->w->financeB)->post(route('corrections.requests.withdraw', $asked->request), ['reason_text' => 'The Owner will do it'])->assertSessionHas('success');

    $done = $this->s->submit($this->s->input(CustomerReceiptActionPolicy::TYPE, $this->receipt, DocumentAction::Reverse), $this->s->w->owner);

    expect($done->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($done->reversalJournal->journal_date->toDateString())->toBe('2026-10-14')
        ->and($done->reversalJournal->period_exception_id)->not->toBeNull()
        ->and((bool) AccountingPeriod::current()->is_unlocked)->toBeFalse();
});

it('above Tk 20,000 a second person must approve it', function () {
    $big = $this->s->invoice($this->s->challan(null, '30'), '850', '2026-10-13');
    $receipt = $this->s->receive($big, '25500');

    $result = $this->s->submit($this->s->input(CustomerReceiptActionPolicy::TYPE, $receipt, DocumentAction::Reverse), $this->s->w->financeB);

    expect($result->outcome)->toBe(CorrectionOutcome::Requested)
        ->and($result->request->approval_route)->toBe(ApprovalRoute::SecondPerson)
        ->and($receipt->fresh()->isReversed())->toBeFalse();
});

it('a double submit with the same key does it once', function () {
    $input = $this->s->input(CustomerReceiptActionPolicy::TYPE, $this->receipt, DocumentAction::Reverse);
    $preview = $this->s->preview($input, $this->s->w->financeB);
    $service = app(CorrectionService::class);

    $first = $service->submit($input, $this->s->w->financeB, $preview->previewToken);
    $second = $service->submit($input, $this->s->w->financeB, $preview->previewToken);

    expect($first->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($second->outcome)->toBe(CorrectionOutcome::AlreadyDone)
        ->and(Journal::whereNotNull('reverses_journal_id')->count())->toBe(1);

    expect(fn () => $this->s->submit($this->s->input(CustomerReceiptActionPolicy::TYPE, $this->receipt->fresh(), DocumentAction::Reverse), $this->s->w->financeC))
        ->toThrow(CorrectionBlockedException::class);
});
