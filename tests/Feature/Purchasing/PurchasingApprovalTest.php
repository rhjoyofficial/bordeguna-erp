<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\database\seeders\ApprovalWorkflowSeeder;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordSupplierAdvance;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\Actions\RecordSupplierPayment;
use App\Modules\Purchasing\Actions\RefundSupplierAdvance;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Exceptions\PurchaseApprovalRequired;
use App\Modules\Purchasing\Models\PurchaseApproval;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Support\PurchaseDraft;
use App\Modules\Purchasing\Support\PurchasingApprovalGate;
use App\Support\ApprovalCards;
use App\Support\ApprovalInbox;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Purchasing approvals (ruling P7, Joy's decision 18 Oct 2026): below Tk
 * 200,000 nothing changes; AT OR ABOVE it, and for every exception whatever
 * the amount, a bill, payment, advance or refund waits for an Owner or Manager
 * who is not its maker. Nothing posts until then.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-20 10:00:00');
    config(['accounting.purchasing_approvals' => true]);

    $this->business = Business::create(['name' => 'Approve Co', 'slug' => 'approve-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);
    TenantScope::enter($this->business->id);

    foreach (['Owner', 'Manager', 'Finance'] as $name) {
        Role::firstOrCreate(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }
    (new PurchasingPermissionsSeeder)->run();
    (new ApprovalWorkflowSeeder)->run();
    setPermissionsTeamId($this->business->id);

    $this->make = function (string $role, string $name): User {
        $user = User::factory()->create(['name' => $name]);
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };
    $this->joy = ($this->make)('Owner', 'Joy');
    $this->moin = ($this->make)('Manager', 'Moin');
    $this->clerk = ($this->make)('Finance', 'Clerk');

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pc']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAV']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'JAR-500', 'name' => 'Glass jar 500ml', 'type' => ProductType::RawMaterial,
    ]);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Padma Glass', 'code' => 'SUP-001', 'supplier_type' => 'goods']);
    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => MoneyAccountType::Bank,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    // The policy as Joy set it, with any switch overridden for one test.
    $this->policy = fn (array $overrides = []) => FinancePolicy::withoutGlobalScopes()->updateOrCreate(
        ['business_id' => $this->business->id],
        $overrides + ['refuse_negative_money' => false],
    );

    // An approved order of `quantity` at `cost`, received in full.
    $this->received = function (string $quantity, string $cost = '20.00'): PurchaseOrder {
        $order = app(CreatePurchaseOrder::class)->handle(
            supplier: $this->supplier, warehouse: $this->warehouse,
            lines: [['product' => $this->product, 'quantity' => $quantity, 'unit_cost' => $cost]],
            createdBy: $this->joy,
        );
        approveIfPending($order->approvalRequest, $this->moin);
        $order = $order->fresh('lines');
        $line = $order->lines->first();
        app(RecordGoodsReceipt::class)->handle($order, [['line' => $line, 'quantity' => (string) $line->quantity_ordered]], $this->joy);

        return $order->fresh('lines');
    };

    $this->bill = fn (PurchaseOrder $order, string $number, ?string $cost = null, ?array $discount = null, ?User $by = null) => app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier, billNumber: $number, billDate: '2026-08-20',
        lines: [['line' => $order->lines->first()->fresh(), 'quantity' => (string) $order->lines->first()->quantity_ordered, 'unitCost' => $cost ?? (string) $order->lines->first()->unit_cost]],
        createdBy: $by ?? $this->joy, purchaseOrder: $order, tradeDiscount: $discount,
    );

    $this->pay = fn (SupplierBill $bill, string $amount, bool $excess = false, ?MoneyAccount $from = null) => app(RecordSupplierPayment::class)->handle(
        supplier: $this->supplier, moneyAccount: $from ?? $this->bank, paidOn: '2026-08-20',
        allocations: [['bill' => $bill->fresh(), 'amount' => $amount]], createdBy: $this->joy, excessToAdvance: $excess,
    );

    // A reason code the gate gives, or null when it lets the document through.
    $this->reasonsOf = function (callable $attempt): array {
        try {
            $attempt();
        } catch (PurchaseApprovalRequired $e) {
            return array_column($e->reasons, 'code');
        }

        return [];
    };

    // The supplier already has history, so "new supplier" stays quiet.
    $this->seasoned = function (): void {
        ($this->policy)(['purchasing_approve_new_supplier' => false]);
        $first = ($this->bill)(($this->received)('1'), 'OLD-1');
        ($this->pay)($first, '20');
    };
});

afterEach(fn () => Carbon::setTestNow());

// ── The amount rule ─────────────────────────────────────────────────────────

it('posts a bill below Tk 200,000 at once, exactly as before', function () {
    ($this->seasoned)();
    $bill = ($this->bill)(($this->received)('9999.9995', '20.00'), 'B-199');

    expect($bill->exists)->toBeTrue()
        ->and(Journal::query()->where('source_type', $bill->getMorphClass())->where('source_id', $bill->id)->exists())->toBeTrue()
        ->and(PurchaseApproval::count())->toBe(0);
});

it('stops a bill AT Tk 200,000 before anything is written', function () {
    ($this->seasoned)();
    $journals = Journal::count();
    $order = ($this->received)('10000', '20.00');

    expect(($this->reasonsOf)(fn () => ($this->bill)($order, 'B-200')))->toBe(['threshold'])
        ->and(SupplierBill::query()->where('bill_number', 'B-200')->exists())->toBeFalse()
        ->and(Journal::count())->toBe($journals + 1) // the receipt only
        ->and((float) $order->lines->first()->fresh()->quantity_invoiced)->toBe(0.0);
});

it('stops a bill above the limit, holds it as pending, and posts it when a Manager approves', function () {
    ($this->seasoned)();
    $order = ($this->received)('12000', '20.00');

    try {
        ($this->bill)($order, 'B-240');
        $this->fail('The bill should have needed approval.');
    } catch (PurchaseApprovalRequired $e) {
        $approval = app(PurchasingApprovalGate::class)->hold($e, $this->joy);
    }

    expect($approval->isPending())->toBeTrue()
        ->and($approval->amount_minor)->toBe(24_000_000)
        ->and($approval->approvalRequest->isPending())->toBeTrue()
        ->and(SupplierBill::query()->where('bill_number', 'B-240')->exists())->toBeFalse();

    // The common inbox: Moin may act on it, Joy (the maker) may not.
    expect(app(ApprovalInbox::class)->pendingActionableFor($this->moin)->pluck('approvable_id'))->toContain($approval->id)
        ->and(app(ApprovalInbox::class)->pendingActionableFor($this->joy)->pluck('approvable_id'))->not->toContain($approval->id);

    $bill = app(PurchasingApprovalGate::class)->approve($approval, $this->moin, 'Checked against the delivery');

    expect($bill)->toBeInstanceOf(SupplierBill::class)
        ->and($bill->bill_number)->toBe('B-240')
        ->and((int) $bill->created_by)->toBe($this->joy->id)
        ->and(Journal::query()->where('source_type', $bill->getMorphClass())->where('source_id', $bill->id)->exists())->toBeTrue()
        ->and($approval->fresh()->status)->toBe(PurchaseApproval::APPROVED)
        ->and($approval->fresh()->document_id)->toBe($bill->id)
        ->and($approval->fresh()->approvalRequest->decisions)->toHaveCount(1);
});

it('never lets the maker approve, even as Owner, and never a role outside the policy', function () {
    ($this->seasoned)();
    $order = ($this->received)('12000', '20.00');

    try {
        ($this->bill)($order, 'B-SELF');
    } catch (PurchaseApprovalRequired $e) {
        $approval = app(PurchasingApprovalGate::class)->hold($e, $this->joy);
    }

    expect(fn () => app(PurchasingApprovalGate::class)->approve($approval, $this->joy))->toThrow(ValidationException::class)
        ->and(fn () => app(PurchasingApprovalGate::class)->approve($approval, $this->clerk))->toThrow(ValidationException::class)
        ->and($approval->approvalRequest->canBeActionedBy($this->joy))->toBeFalse()
        ->and($approval->approvalRequest->canBeActionedBy($this->clerk))->toBeFalse()
        ->and($approval->approvalRequest->canBeActionedBy($this->moin))->toBeTrue()
        ->and($approval->fresh()->isPending())->toBeTrue()
        ->and(SupplierBill::query()->where('bill_number', 'B-SELF')->exists())->toBeFalse();

    // A Manager's own bill goes to the Owner instead.
    $other = ($this->received)('12000', '20.00');

    try {
        ($this->bill)($other, 'B-MGR', by: $this->moin);
    } catch (PurchaseApprovalRequired $e) {
        $mine = app(PurchasingApprovalGate::class)->hold($e, $this->moin);
    }

    expect($mine->approvalRequest->canBeActionedBy($this->moin))->toBeFalse()
        ->and($mine->approvalRequest->canBeActionedBy($this->joy))->toBeTrue();

    // Finance policy can name the approvers: Finance alone now.
    ($this->policy)(['purchasing_approver_roles' => ['Finance']]);
    app(PurchasingApprovalGate::class)->syncWorkflow($this->business->id);
    expect($approval->fresh()->approvalRequest->canBeActionedBy($this->moin))->toBeFalse()
        ->and(app(PurchasingApprovalGate::class)->refusalFor($approval->fresh(), $this->clerk))->toBeNull();
});

it('rejects with a reason, posts nothing and frees the goods for a corrected bill', function () {
    ($this->seasoned)();
    $order = ($this->received)('12000', '20.00');

    try {
        ($this->bill)($order, 'B-REJ');
    } catch (PurchaseApprovalRequired $e) {
        $approval = app(PurchasingApprovalGate::class)->hold($e, $this->joy);
    }

    expect(fn () => app(PurchasingApprovalGate::class)->reject($approval, $this->moin, ' '))->toThrow(ValidationException::class);

    app(PurchasingApprovalGate::class)->reject($approval, $this->moin, 'Price not agreed');

    expect($approval->fresh()->status)->toBe(PurchaseApproval::REJECTED)
        ->and($approval->fresh()->decision_note)->toBe('Price not agreed')
        ->and($approval->fresh()->approvalRequest->status->value)->toBe('rejected')
        ->and(SupplierBill::query()->where('bill_number', 'B-REJ')->exists())->toBeFalse()
        ->and((float) $order->lines->first()->fresh()->quantity_invoiced)->toBe(0.0)
        ->and(fn () => app(PurchasingApprovalGate::class)->approve($approval->fresh(), $this->moin))->toThrow(ValidationException::class);
});

it('refuses a second pending copy of the same supplier bill number', function () {
    ($this->seasoned)();
    $order = ($this->received)('12000', '20.00');

    try {
        ($this->bill)($order, 'B-DUP');
    } catch (PurchaseApprovalRequired $e) {
        app(PurchasingApprovalGate::class)->hold($e, $this->joy);
        expect(fn () => app(PurchasingApprovalGate::class)->hold($e, $this->joy))->toThrow(ValidationException::class);
    }

    expect(PurchaseApproval::count())->toBe(1);
});

// ── Payments, advances and refunds ──────────────────────────────────────────

it('holds a payment at the limit through the screen and pays it when the Manager approves', function () {
    ($this->seasoned)();
    ($this->policy)(['purchasing_approve_new_supplier' => false]);
    $bill = ($this->bill)(($this->received)('9000', '20.00'), 'B-180');
    ($this->policy)(['purchasing_approval_at_or_above_minor' => 10_000_000]); // Tk 100,000 for this test
    $payments = SupplierPayment::count();

    $this->actingAs($this->joy)->post('/purchasing/payments', [
        'supplier_id' => $this->supplier->id, 'money_account_id' => $this->bank->id, 'paid_on' => '2026-08-20',
        'allocations' => [['supplier_bill_id' => $bill->id, 'amount' => '150000']],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $approval = PurchaseApproval::sole();

    expect($approval->kind)->toBe('payment')
        ->and($approval->reasons[0]['code'])->toBe('threshold')
        ->and(SupplierPayment::count())->toBe($payments);

    $this->actingAs($this->joy)->get("/purchasing/approvals/{$approval->id}")->assertOk();
    $this->actingAs($this->joy)->post("/purchasing/approvals/{$approval->id}/approve")->assertSessionHas('error');
    $this->actingAs($this->moin)->post("/purchasing/approvals/{$approval->id}/approve")->assertRedirect();

    $payment = SupplierPayment::query()->latest('id')->first();

    expect(SupplierPayment::count())->toBe($payments + 1)
        ->and($payment->amount)->toBe('150000.0000')
        ->and($bill->fresh()->outstanding()->format())->toBe('30,000.00')
        ->and($approval->fresh()->document_id)->toBe($payment->id);
});

it('holds an advance and a refund at the limit', function () {
    ($this->seasoned)();
    ($this->policy)(['purchasing_approval_at_or_above_minor' => 500_000]); // Tk 5,000

    expect(($this->reasonsOf)(fn () => app(RecordSupplierAdvance::class)->pay(
        supplier: $this->supplier, moneyAccount: $this->bank, paidOn: '2026-08-18', amount: '5000', createdBy: $this->joy,
    )))->toBe(['threshold']);

    $advance = app(RecordSupplierAdvance::class)->pay(supplier: $this->supplier, moneyAccount: $this->bank, paidOn: '2026-08-18', amount: '4999', createdBy: $this->joy);
    ($this->policy)(['purchasing_approval_at_or_above_minor' => 100_000]); // Tk 1,000

    expect(($this->reasonsOf)(fn () => app(RefundSupplierAdvance::class)->handle(
        supplier: $this->supplier, advance: $advance, moneyAccount: $this->bank, refundedOn: '2026-08-19', amount: '1000', by: $this->joy,
    )))->toBe(['threshold']);
});

it('switches the amount rule off at zero and everything off outside the policy flag', function () {
    ($this->seasoned)();
    ($this->policy)(['purchasing_approval_at_or_above_minor' => 0]);
    expect(($this->bill)(($this->received)('12000', '20.00'), 'B-ZERO')->exists)->toBeTrue();

    config(['accounting.purchasing_approvals' => false]);
    ($this->policy)(['purchasing_approval_at_or_above_minor' => 20_000_000]);
    expect(($this->bill)(($this->received)('12000', '20.00'), 'B-OFF')->exists)->toBeTrue();
});

// ── The exceptions, whatever the amount ─────────────────────────────────────

it("asks for approval on a new supplier's first bill and first payment, not after", function () {
    // OFF by default (Joy, 18 Oct 2026): nothing is asked.
    expect(app(PurchasingApprovalGate::class)->assess(new PurchaseDraft(
        kind: 'bill', supplier: $this->supplier, amount: Money::ofTaka('20'), documentDate: Carbon::parse('2026-08-20'),
        reference: 'N-0', summary: 'First bill',
    )))->toBe([]);
    $order = ($this->received)('1', '20.00');

    ($this->policy)(['purchasing_approve_new_supplier' => true]);

    expect(($this->reasonsOf)(fn () => ($this->bill)($order, 'N-1')))->toBe(['new_supplier']);

    ($this->policy)(['purchasing_approve_new_supplier' => false]);
    $bill = ($this->bill)($order, 'N-1');
    ($this->policy)(['purchasing_approve_new_supplier' => true]);

    expect(($this->reasonsOf)(fn () => ($this->pay)($bill, '10')))->toBe(['new_supplier'])
        ->and(($this->reasonsOf)(fn () => ($this->bill)(($this->received)('1'), 'N-2')))->toBe([]);
});

it('asks for approval on a bill price above the order beyond the tolerance', function () {
    ($this->seasoned)();

    expect(($this->reasonsOf)(fn () => ($this->bill)(($this->received)('10', '20.00'), 'P-1', '21.00')))->toBe(['price_mismatch'])
        ->and(($this->reasonsOf)(fn () => ($this->bill)(($this->received)('10', '20.00'), 'P-2', '20.30')))->toBe([])
        // Below the order price is not an overcharge.
        ->and(($this->reasonsOf)(fn () => ($this->bill)(($this->received)('10', '20.00'), 'P-3', '15.00')))->toBe([]);

    ($this->policy)(['purchasing_approve_price_mismatch' => false]);
    expect(($this->reasonsOf)(fn () => ($this->bill)(($this->received)('10', '20.00'), 'P-4', '25.00')))->toBe([]);
});

it('asks for approval on an unusual discount', function () {
    ($this->seasoned)();
    $discount = fn (string $percent) => ['mode' => 'percent', 'value' => $percent, 'reference' => 'TD-1'];

    expect(($this->reasonsOf)(fn () => ($this->bill)(($this->received)('10'), 'D-1', null, $discount('15'))))->toBe(['unusual_discount'])
        ->and(($this->reasonsOf)(fn () => ($this->bill)(($this->received)('10'), 'D-2', null, $discount('5'))))->toBe([]);
});

it('asks for approval when a payment pays beyond the bills or advances pass the order value', function () {
    ($this->seasoned)();
    $bill = ($this->bill)(($this->received)('10'), 'O-1');

    expect(($this->reasonsOf)(fn () => ($this->pay)($bill, '300', true)))->toBe(['advance_over_application'])
        ->and(($this->reasonsOf)(fn () => ($this->pay)($bill, '200')))->toBe([]);

    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier, warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '10', 'unit_cost' => '20.00']], createdBy: $this->joy,
    );
    approveIfPending($order->approvalRequest, $this->moin);

    expect(($this->reasonsOf)(fn () => app(RecordSupplierAdvance::class)->pay(
        supplier: $this->supplier, moneyAccount: $this->bank, paidOn: '2026-08-18', amount: '250', createdBy: $this->joy, purchaseOrder: $order->fresh(),
    )))->toBe(['advance_over_application']);
});

it("asks for approval on the first bank payment after the supplier's bank details changed", function () {
    ($this->seasoned)();
    $bill = ($this->bill)(($this->received)('10'), 'K-1');

    Carbon::setTestNow('2026-08-20 11:00:00');
    $this->supplier->update(['bank_account_number' => '1234567890']);
    $this->supplier->refresh();

    expect($this->supplier->bank_details_changed_at)->not->toBeNull()
        ->and(($this->reasonsOf)(fn () => ($this->pay)($bill, '50')))->toBe(['bank_change']);

    ($this->policy)(['purchasing_approve_bank_change' => false]);
    ($this->pay)($bill, '50');
    ($this->policy)(['purchasing_approve_bank_change' => true]);

    Carbon::setTestNow('2026-08-20 12:00:00');
    expect(($this->reasonsOf)(fn () => ($this->pay)($bill, '50')))->toBe([]);

    // A new supplier's first details are not a change.
    $fresh = Supplier::create(['business_id' => $this->business->id, 'name' => 'New Co', 'code' => 'SUP-002', 'bank_account_number' => '999']);
    expect($fresh->bank_details_changed_at)->toBeNull();
});

it('asks for approval on a non-PO bill line, as a caller reports it', function () {
    $gate = app(PurchasingApprovalGate::class);
    ($this->seasoned)();

    $draft = fn (int $nonPo) => new PurchaseDraft(
        kind: 'bill', supplier: $this->supplier, amount: Money::ofTaka('500'), documentDate: Carbon::parse('2026-08-15'),
        reference: 'S-1', summary: 'Service bill', nonPoLines: $nonPo,
    );

    // OFF by default (Joy, 18 Oct 2026), then switched on.
    expect($gate->assess($draft(1)))->toBe([]);
    ($this->policy)(['purchasing_approve_non_po_bill' => true]);

    expect(array_column($gate->assess($draft(1)), 'code'))->toBe(['non_po'])
        ->and($gate->assess($draft(0)))->toBe([]);

    ($this->policy)(['purchasing_approve_non_po_bill' => false]);
    expect($gate->assess($draft(1)))->toBe([]);
});

it('gates a voucher-made supplier payment: the poster must be another approver', function () {
    $gate = app(PurchasingApprovalGate::class);
    ($this->seasoned)();
    ($this->policy)(['purchasing_approval_at_or_above_minor' => 100]);

    $draft = new PurchaseDraft(
        kind: 'payment', supplier: $this->supplier, amount: Money::ofTaka('500'), documentDate: Carbon::parse('2026-08-18'),
        reference: null, summary: 'Voucher payment', moneyAccount: $this->bank,
    );

    expect(fn () => $gate->enforceForVoucher($draft, $this->joy, $this->joy->id))->toThrow(ValidationException::class)
        ->and(fn () => $gate->enforceForVoucher($draft, $this->clerk, $this->joy->id))->toThrow(ValidationException::class);

    $gate->enforceForVoucher($draft, $this->moin, $this->joy->id);
    expect(true)->toBeTrue();
});

// ── Screens ─────────────────────────────────────────────────────────────────

it('holds a bill from the bill screen and shows it on the approvals list, inbox card and bill list', function () {
    ($this->seasoned)();
    $order = ($this->received)('12000', '20.00');
    $line = $order->lines->first();

    $this->actingAs($this->joy)->post('/purchasing/bills', [
        'purchase_order_id' => $order->id, 'bill_number' => 'S-240', 'bill_date' => '2026-08-20',
        'lines' => [['purchase_order_line_id' => $line->id, 'quantity' => '12000', 'unit_cost' => '20']],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $approval = PurchaseApproval::sole();

    $this->actingAs($this->moin)->get('/purchasing/approvals')->assertOk()
        ->assertInertia(fn ($page) => $page->component('purchasing/approvals/index')->where('approvals.0.can_decide', true)->where('approvals.0.reasons.0', fn ($reason) => str_contains($reason, 'approval limit')));
    $this->actingAs($this->joy)->get('/purchasing/approvals')
        ->assertInertia(fn ($page) => $page->where('approvals.0.can_decide', false));
    $this->actingAs($this->joy)->get('/purchasing/bills')
        ->assertInertia(fn ($page) => $page->where('pending_approvals', 1));

    $this->actingAs($this->moin);
    $card = app(ApprovalCards::class)->present($approval->approvalRequest()->with('requestedBy', 'decisions', 'workflow.stages')->first());
    expect($card['approve_url'])->toBe(route('purchasing.approvals.approve', $approval))
        ->and(collect($card['details'])->pluck('label'))->toContain('Needs approval because');

    $this->actingAs($this->moin)->post("/purchasing/approvals/{$approval->id}/reject", ['review_note' => 'Wrong price'])->assertSessionHas('success');
    expect($approval->fresh()->status)->toBe(PurchaseApproval::REJECTED);
});

it('saves the purchasing approval settings on the Finance policy screen', function () {
    Permission::findOrCreate('accounting.policy.manage', 'web');
    $this->joy->givePermissionTo('accounting.policy.manage');
    $policy = FinancePolicy::for($this->business->id);

    $this->actingAs($this->joy)->get('/accounting/finance-policy')->assertOk()
        ->assertInertia(fn ($page) => $page->where('policy.purchasing_approval_at_or_above', '200000.00')
            ->where('policy.purchasing_approver_roles', ['Owner', 'Manager'])
            ->where('policy.purchasing_approve_new_supplier', false)
            ->where('policy.purchasing_approve_non_po_bill', false)
            ->where('policy.purchasing_approve_bank_change', true));

    $form = [
        'refuse_negative_money' => true,
        'staff_purchase_cap_percent' => 10, 'manager_discount_cap_percent' => 3, 'staff_discount_cap_percent' => 1,
        'bad_debt_half_after_days' => 180, 'bad_debt_full_after_days' => 365, 'courier_settlement_alert_days' => 7,
        'purchasing_approval_at_or_above' => '150000', 'purchasing_approver_roles' => ['Owner'],
        'purchasing_price_tolerance_percent' => 5, 'purchasing_unusual_discount_percent' => 12,
        'purchasing_approve_new_supplier' => true,
    ];

    foreach (FinancePolicy::MONEY_FIELDS as $field) {
        $form[substr($field, 0, -6)] = $policy->money($field)->toTaka();
    }

    $this->actingAs($this->joy)->put('/accounting/finance-policy', $form)->assertSessionHasNoErrors();

    $saved = FinancePolicy::for($this->business->id);
    expect($saved->purchasing_approval_at_or_above_minor)->toBe(15_000_000)
        ->and($saved->purchasingApproverRoles())->toBe(['Owner'])
        ->and($saved->purchasing_approve_new_supplier)->toBeTrue()
        ->and($saved->purchasing_approve_bank_change)->toBeTrue()
        ->and(ApprovalWorkflow::withoutGlobalScopes()->where('business_id', $this->business->id)
            ->where('key', 'purchase_approval')->first()->stages()->first()->allowed_roles)->toBe(['Owner']);
});
