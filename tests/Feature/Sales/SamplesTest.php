<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Enums\TaskStatus;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\CrmTask;
use App\Modules\Crm\Models\Lead;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use App\Modules\Foundation\database\seeders\ApprovalWorkflowSeeder;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\StockLedger;
use App\Modules\Purchasing\Actions\PostLandedCost;
use App\Modules\Purchasing\Actions\SaveLandedCost;
use App\Modules\Purchasing\Actions\SubmitLandedCost;
use App\Modules\Sales\Actions\ApproveSampleIssue;
use App\Modules\Sales\Actions\RecordSampleIssue;
use App\Modules\Sales\Actions\RecordSampleMake;
use App\Modules\Sales\Corrections\SampleIssueActionPolicy;
use App\Modules\Sales\Models\SampleIssue;
use App\Modules\Sales\Support\SampleHeads;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\DiscountWorld;
use Tests\Support\SalesWorld;

/**
 * ⭐ Samples (Joy, 25 Oct 2026): goods given away free for tasting, a gift or
 * marketing, from product stock or from a sample lot made of raw materials;
 * no money taken, the delivery charge ours. Every hand-out goes through the
 * approvals engine (self-approved within the finance policy limit), posts the
 * goods at cost to 6333 Free Sample and the delivery to Sample Delivery,
 * and is reversed through the corrections engine.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->s = SalesWorld::make();
    (new ApprovalWorkflowSeeder)->run();
});

afterEach(fn () => Carbon::setTestNow());

function giveSample(SalesWorld $s, array $overrides = []): SampleIssue
{
    $o = $overrides + [
        'on' => '2026-10-15',
        'recipient' => ['kind' => 'customer', 'id' => $s->customer->id],
        'purpose' => 'tasting',
        'lines' => [['product_id' => $s->honey->id, 'quantity' => '2']],
        'delivery' => ['method' => 'hand'],
        'by' => $s->w->manager,
        'feedback' => null,
        'warehouse' => $s->warehouse,
        'purpose_note' => null,
    ];

    return app(RecordSampleIssue::class)->handle(
        givenOn: $o['on'],
        recipient: $o['recipient'],
        purpose: $o['purpose'],
        warehouse: $o['warehouse'],
        lines: $o['lines'],
        delivery: $o['delivery'],
        givenBy: $o['by'],
        purposeNote: $o['purpose_note'],
        feedbackOn: $o['feedback'],
    );
}

function sampleJournal(SampleIssue $issue): ?Journal
{
    return Journal::query()->where('source_type', $issue->getMorphClass())->where('source_id', $issue->id)->whereNull('reverses_journal_id')->first();
}

it('gives a sample from stock within the limit: approved as given, goods out at cost to 6333 Free Sample', function () {
    $issue = giveSample($this->s);

    $journal = sampleJournal($issue);
    $head = SampleHeads::goods(Carbon::parse('2026-10-15'));

    expect($issue->number)->toStartWith('SMP-')
        ->and($issue->status)->toBe(SampleIssue::POSTED)
        ->and($issue->approvalRequest->status)->toBe(ApprovalStatus::Approved)
        ->and($issue->cost_total_paisa)->toBe(100000)
        ->and($this->s->onHand())->toBe(98.0)
        ->and(StockMovement::query()->where('reason', MovementReason::Sample->value)->count())->toBe(1)
        ->and($journal->totalDebits()->minor)->toBe(100000)
        ->and($this->s->w->balance($head))->toBe(100000)
        ->and($head->code)->toBe('6333')
        ->and($head->name)->toBe('Free Sample')
        ->and($head->parent?->code)->toBe('6330');

    // The delivery head is new, created beside it on first use.
    $delivery = SampleHeads::delivery(Carbon::parse('2026-10-15'));

    expect($delivery->name)->toBe('Sample Delivery')
        ->and($delivery->code)->toBe('6338')
        ->and($delivery->parent?->code)->toBe('6330');

    // No revenue, no receivable: nobody paid for it.
    expect($this->s->balance('accounts_receivable'))->toBe(0);
});

it('creates Samples and Marketing only when the chart has no Free Sample head, and a mapping wins over both', function () {
    Account::query()->where('code', '6333')->update(['name' => 'Old samples']);

    $made = SampleHeads::goods(Carbon::parse('2026-10-15'));

    expect($made->name)->toBe('Samples and Marketing')
        ->and($made->code)->not->toBe('6333');

    AccountMapping::create(['business_id' => $this->s->w->business->id, 'key' => SampleHeads::GOODS_MAPPING, 'account_id' => Account::where('code', '6331')->sole()->id, 'effective_from' => '2026-01-01']);

    expect(SampleHeads::goods(Carbon::parse('2026-10-15'))->code)->toBe('6331');
});

it('takes exactly the batch chosen, never FEFO', function () {
    $second = app(RecordStockIn::class)->handle($this->s->honey, $this->s->warehouse, '10', '650', $this->s->w->owner, batchNumber: 'B2', occurredAt: '2026-10-05 09:00:00');

    $issue = giveSample($this->s, ['lines' => [['product_id' => $this->s->honey->id, 'stock_batch_id' => $second->batch_id, 'quantity' => '1']]]);

    expect($issue->cost_total_paisa)->toBe(65000)
        ->and((float) StockBatch::query()->find($second->batch_id)->current_quantity)->toBe(9.0);
});

it('posts the delivery charge we bear: paid from a money account, or owed to the courier', function () {
    $paid = giveSample($this->s, ['delivery' => ['method' => 'rider', 'charge' => '80', 'money_account_id' => $this->s->w->cash->id]]);

    expect($this->s->balance('cash_in_hand'))->toBe(-8000)
        ->and($this->s->w->balance(SampleHeads::delivery(Carbon::parse('2026-10-15'))))->toBe(8000)
        ->and(sampleJournal($paid)->totalDebits()->minor)->toBe(108000);

    $pathao = MoneyAccount::create(['business_id' => $this->s->w->business->id, 'name' => 'Pathao', 'type' => 'courier', 'is_active' => true, 'account_id' => Account::bySlug('courier_receivable')->id]);
    $owed = giveSample($this->s, ['delivery' => ['method' => 'courier', 'charge' => '120', 'courier_account_id' => $pathao->id, 'district' => 'Bagerhat', 'zone' => 'Outside Dhaka']]);

    $line = DB::table('journal_lines')->where('journal_id', $owed->journal_id)->where('account_id', Account::bySlug('courier_payable')->id)->first();

    expect((int) $line->amount_minor)->toBe(-12000)
        ->and((int) $line->money_account_id)->toBe($pathao->id);
});

it('asks for the money account when there is a delivery charge', function () {
    expect(fn () => giveSample($this->s, ['delivery' => ['method' => 'hand', 'charge' => '50']]))
        ->toThrow(ValidationException::class);
});

it('saves a new name and phone to the phone book once, and finds them again by phone', function () {
    $first = giveSample($this->s, ['recipient' => ['kind' => 'new', 'name' => 'Rahim Uddin', 'phone' => '01711 000111']]);
    $again = giveSample($this->s, ['recipient' => ['kind' => 'new', 'name' => 'Rahim', 'phone' => '+8801711000111']]);

    expect(Contact::query()->count())->toBe(1)
        ->and($first->recipientKind())->toBe('contact')
        ->and((int) $again->recipient_id)->toBe((int) $first->recipient_id);
});

it('makes a CRM follow-up for a lead on the feedback date, and none for a contact', function () {
    $lead = Lead::create(['business_id' => $this->s->w->business->id, 'name' => 'Kit Shop', 'phone' => '01711999001', 'status' => LeadStatus::Contacted]);

    $issue = giveSample($this->s, ['recipient' => ['kind' => 'lead', 'id' => $lead->id], 'feedback' => '2026-10-20', 'purpose' => 'marketing']);
    $task = CrmTask::query()->findOrFail($issue->crm_task_id);

    expect($task->subject_id)->toBe($lead->id)
        ->and($task->due_at->toDateString())->toBe('2026-10-20')
        ->and($task->assigned_to_user_id)->toBe($this->s->w->manager->id);

    $contact = giveSample($this->s, ['recipient' => ['kind' => 'new', 'name' => 'Nila', 'phone' => '01811000222'], 'feedback' => '2026-10-20']);

    expect($contact->crm_task_id)->toBeNull()
        ->and($contact->feedback_on->toDateString())->toBe('2026-10-20');
});

it('waits for Owner or Manager above the limit, moving nothing until approved', function () {
    // 50 jars at Tk 500 = Tk 25,000, above the Tk 20,000 policy limit.
    $issue = giveSample($this->s, ['by' => $this->s->cashier, 'lines' => [['product_id' => $this->s->honey->id, 'quantity' => '50']], 'purpose' => 'gift']);

    expect($issue->status)->toBe(SampleIssue::WAITING)
        ->and($this->s->onHand())->toBe(100.0)
        ->and(sampleJournal($issue))->toBeNull()
        ->and($issue->approvalRequest->canBeActionedBy($this->s->cashier))->toBeFalse()
        ->and($issue->approvalRequest->canBeActionedBy($this->s->w->manager))->toBeTrue();

    $posted = app(ApproveSampleIssue::class)->approve($issue, $this->s->w->manager, 'Dealer launch');

    expect($posted->status)->toBe(SampleIssue::POSTED)
        ->and($this->s->onHand())->toBe(50.0)
        ->and(sampleJournal($posted)->totalDebits()->minor)->toBe(2500000);
});

it('rejects a waiting sample: nothing moves and its follow-up is cancelled', function () {
    $issue = giveSample($this->s, ['by' => $this->s->cashier, 'lines' => [['product_id' => $this->s->honey->id, 'quantity' => '50']], 'feedback' => '2026-10-20']);

    $rejected = app(ApproveSampleIssue::class)->reject($issue, $this->s->w->owner, 'Too many');

    expect($rejected->status)->toBe(SampleIssue::REJECTED)
        ->and($this->s->onHand())->toBe(100.0)
        ->and(CrmTask::query()->findOrFail($issue->crm_task_id)->status)->toBe(TaskStatus::Cancelled);

    expect(fn () => app(ApproveSampleIssue::class)->approve($issue, $this->s->w->owner))->toThrow(RuntimeException::class);
});

it('gives a product that is not for sale', function () {
    $this->s->honey->update(['can_be_sold' => false]);

    expect(giveSample($this->s)->status)->toBe(SampleIssue::POSTED);
});

it('refuses a quarantine warehouse, a future date and a closed month', function () {
    $quarantine = Warehouse::create(['business_id' => $this->s->w->business->id, 'name' => 'Quarantine', 'code' => 'QRN', 'not_for_sale' => true]);

    expect(fn () => giveSample($this->s, ['warehouse' => $quarantine]))->toThrow(ValidationException::class)
        ->and(fn () => giveSample($this->s, ['on' => '2026-10-30']))->toThrow(ValidationException::class);

    $this->s->w->closeThrough('2026-10-10');

    expect(fn () => giveSample($this->s, ['on' => '2026-10-05']))->toThrow(PeriodClosedException::class);
});

it('refuses more than the chosen batch holds', function () {
    $batch = StockBatch::query()->where('product_id', $this->s->honey->id)->firstOrFail();

    expect(fn () => giveSample($this->s, ['lines' => [['product_id' => $this->s->honey->id, 'stock_batch_id' => $batch->id, 'quantity' => '500']]]))
        ->toThrow(ValidationException::class);
});

it('makes a sample lot from raw materials at their cost, then gives from it', function () {
    $unit = $this->s->honey->unit_of_measure_id;
    $raw = Product::create(['business_id' => $this->s->w->business->id, 'unit_of_measure_id' => $unit, 'sku' => 'RAW-HNY', 'name' => 'Raw honey', 'type' => ProductType::RawMaterial]);
    $jar = Product::create(['business_id' => $this->s->w->business->id, 'unit_of_measure_id' => $unit, 'sku' => 'SMP-HNY', 'name' => 'Honey tasting jar', 'type' => ProductType::FinishedGood, 'can_be_sold' => false]);
    $in = app(RecordStockIn::class)->handle($raw, $this->s->warehouse, '10', '300', $this->s->w->owner, occurredAt: '2026-10-02 09:00:00');

    $make = app(RecordSampleMake::class)->handle('2026-10-14', $jar, $this->s->warehouse, '3', [['stock_batch_id' => $in->batch_id, 'quantity' => '1']], $this->s->w->manager);

    expect($make->number)->toStartWith('SMK-')
        ->and($make->cost_total_paisa)->toBe(30000)
        ->and($make->batch->batch_number)->toBe("SMPL-{$make->number}")
        ->and((float) $make->batch->current_quantity)->toBe(3.0)
        ->and((float) StockBatch::query()->find($in->batch_id)->current_quantity)->toBe(9.0)
        // Rs 300 / 3 = 100.0000 exactly: nothing for the ledger to hear.
        ->and(Journal::query()->where('source_type', $make->getMorphClass())->count())->toBe(0);

    $issue = giveSample($this->s, ['lines' => [['product_id' => $jar->id, 'stock_batch_id' => $make->stock_batch_id, 'quantity' => '2']]]);

    expect($issue->cost_total_paisa)->toBe(20000);
});

it('makes an uneven sample lot at exactly what it consumed, so no rounding is posted and stock and ledger agree', function () {
    $unit = $this->s->honey->unit_of_measure_id;
    $raw = Product::create(['business_id' => $this->s->w->business->id, 'unit_of_measure_id' => $unit, 'sku' => 'RAW-2', 'name' => 'Raw 2', 'type' => ProductType::RawMaterial]);
    $jar = Product::create(['business_id' => $this->s->w->business->id, 'unit_of_measure_id' => $unit, 'sku' => 'SMP-2', 'name' => 'Sample 2', 'type' => ProductType::FinishedGood]);
    $in = app(RecordStockIn::class)->handle($raw, $this->s->warehouse, '10', '10000', $this->s->w->owner, occurredAt: '2026-10-02 09:00:00');

    $make = app(RecordSampleMake::class)->handle('2026-10-14', $jar, $this->s->warehouse, '3000', [['stock_batch_id' => $in->batch_id, 'quantity' => '10']], $this->s->w->manager);

    // Tk 100,000 / 3,000 = 33.3333 a unit, but the lot carries its exact
    // value (26 Oct 2026): 100,000.00, not 99,999.90.
    expect((string) $make->batch->unit_cost)->toBe('33.3333')
        ->and((int) $make->batch->current_value)->toBe(10_000_000)
        ->and(Journal::query()->where('source_type', $make->getMorphClass())->count())->toBe(0)
        ->and($this->s->w->balance(Account::bySlug('stock_on_hand')))->toBe(0)
        ->and(StockLedger::verify())->toBe([]);

    // A sample from the lot takes its proportional share of that value.
    $issue = giveSample($this->s, ['lines' => [['product_id' => $jar->id, 'stock_batch_id' => $make->stock_batch_id, 'quantity' => '100']]]);

    expect($issue->cost_total_paisa)->toBe(333_333);
});

it('posts a sample from a landed-cost batch at the exact value it moved, and the reconciliation stays clean through a reversal', function () {
    // 1,000 kg of salt at Tk 1.00 plus Tk 3.33 freight: worth exactly
    // 1,003.33, a unit cost of 1.0033 (1,000 x 1.0033 is only 1,003.30).
    $d = DiscountWorld::make($this->s->w);
    $receipt = $d->order([[$d->salt, '1000', '1.00']])->goodsReceipts()->with('lines')->latest('id')->firstOrFail();
    $lc = app(SaveLandedCost::class)->handle($receipt, '2026-10-11', [[
        'cost_type' => 'freight', 'treatment' => null, 'override_reason' => null, 'allocation_method' => 'value',
        'goods_receipt_line_id' => null, 'amount' => '3.33', 'supplier_id' => $d->supplier->id,
        'money_account_id' => null, 'expense_account_id' => null, 'description' => 'Van from the port',
    ]], $this->s->w->owner);
    app(PostLandedCost::class)->handle(app(SubmitLandedCost::class)->handle($lc, $this->s->w->owner)->fresh(['approvalRequest', 'lines']), $this->s->w->owner);

    $batch = fn (): StockBatch => StockBatch::query()->where('product_id', $d->salt->id)->sole();
    $stock = Account::bySlug('stock_on_hand');
    // The honey's opening stock is a bare stock-in with no journal, so the
    // ledger is compared with the salt alone.
    $agrees = function () use ($batch, $stock) {
        $warnings = collect(SubledgerReconciliation::reconcileCurrentBusiness()['warnings'])->filter(fn (string $l) => str_contains($l, 'stock batch'))->values()->all();

        expect((int) DB::table('journal_lines')->sum('amount_minor'))->toBe(0)
            ->and(StockLedger::verify())->toBe([])
            ->and($warnings)->toBe([])
            ->and($this->s->w->balance($stock))->toBe((int) $batch()->current_value);
    };

    expect((string) $batch()->unit_cost)->toBe('1.0033')
        ->and((int) $batch()->current_value)->toBe(100_333);

    $first = giveSample($this->s, ['warehouse' => $d->warehouse, 'lines' => [['product_id' => $d->salt->id, 'quantity' => '400']]]);
    $second = giveSample($this->s, ['warehouse' => $d->warehouse, 'lines' => [['product_id' => $d->salt->id, 'stock_batch_id' => $batch()->id, 'quantity' => '600']], 'on' => '2026-10-16']);
    $credit = fn (SampleIssue $i) => -(int) DB::table('journal_lines')->where('journal_id', $i->journal_id)->where('account_id', $stock->id)->sum('amount_minor');

    // 100,333 x 400 / 1,000 = 40,133.2 -> 40,133 (quantity x unit cost
    // would say 40,132); the last 600 kg take exactly what is left, 60,200.
    expect($first->status)->toBe(SampleIssue::POSTED)
        ->and($first->cost_total_paisa)->toBe(40_133)
        ->and($credit($first))->toBe(40_133)
        ->and($second->cost_total_paisa)->toBe(60_200)
        ->and($credit($second))->toBe(60_200)
        ->and($this->s->w->balance(SampleHeads::goods(Carbon::parse('2026-10-15'))))->toBe(100_333)
        ->and((int) $batch()->current_value)->toBe(0);

    $agrees();

    // Reversed: the goods come back at exactly what they left at.
    $result = $this->s->submit($this->s->input(SampleIssueActionPolicy::TYPE, $second, DocumentAction::Reverse), $this->s->w->financeA);

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and((int) $batch()->current_value)->toBe(60_200)
        ->and((string) $batch()->current_quantity)->toBe('600.0000');

    $agrees();
});

it('reverses a given sample through the engine: goods back on the batch, journal mirrored', function () {
    $issue = giveSample($this->s, ['delivery' => ['method' => 'rider', 'charge' => '80', 'money_account_id' => $this->s->w->cash->id], 'feedback' => '2026-10-20']);

    $result = $this->s->submit($this->s->input(SampleIssueActionPolicy::TYPE, $issue, DocumentAction::Reverse), $this->s->w->financeA);
    $issue->refresh();

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($issue->isReversed())->toBeTrue()
        ->and($this->s->onHand())->toBe(100.0)
        ->and($this->s->balance('cash_in_hand'))->toBe(0)
        ->and($this->s->w->balance(SampleHeads::goods(Carbon::parse('2026-10-15'))))->toBe(0)
        ->and(Journal::query()->findOrFail($issue->journal_id)->hasBeenReversed())->toBeTrue();

    $again = app(DocumentPolicyRegistry::class)->for(SampleIssueActionPolicy::TYPE)->availability($issue->fresh(), $this->s->w->financeA, DocumentAction::Reverse);

    expect($again->allowed)->toBeFalse();
});

it('offers Reverse to Owner, Manager and Finance, never to Staff, and never on a waiting sample', function () {
    $issue = giveSample($this->s);
    $policy = app(SampleIssueActionPolicy::class);

    expect($policy->actionsFor($issue, $this->s->cashier)->actions)->toBe([])
        ->and($policy->actionsFor($issue, $this->s->w->manager)->actions)->toHaveCount(1);

    $waiting = giveSample($this->s, ['by' => $this->s->cashier, 'lines' => [['product_id' => $this->s->honey->id, 'quantity' => '50']]]);

    expect($policy->actionsFor($waiting, $this->s->w->owner)->actions)->toBe([]);
});

it('refuses a reversal once the batch was re-costed', function () {
    $issue = giveSample($this->s);
    StockBatch::query()->where('product_id', $this->s->honey->id)->update(['unit_cost' => '520']);

    $availability = app(SampleIssueActionPolicy::class)->availability($issue->fresh(), $this->s->w->owner, DocumentAction::Reverse);

    expect($availability->allowed)->toBeFalse()
        ->and(collect($availability->blockers)->pluck('code'))->toContain('goods_cannot_go_back');
});
