<?php

use App\Models\User;
use App\Modules\Accounting\Actions\AuthoriseOwnerWithdrawal;
use App\Modules\Accounting\Actions\PostOwnerWithdrawal;
use App\Modules\Accounting\Actions\PostVoucher;
use App\Modules\Accounting\Actions\ReopenOwnerWithdrawal;
use App\Modules\Accounting\Actions\SaveOwnerWithdrawal;
use App\Modules\Accounting\Actions\SaveVoucherDraft;
use App\Modules\Accounting\database\seeders\AccountingPermissionsSeeder;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Enums\OwnerWithdrawalStatus;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\OwnerWithdrawal;
use App\Modules\Accounting\Support\OwnerWithdrawalsMonthlyReport;
use App\Modules\Accounting\Support\Proprietor;
use App\Modules\Accounting\Support\SubsystemAccounts;
use App\Modules\Accounting\Support\VoucherAccounts;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Owner withdrawals (Phase 2, 17 Oct 2026, ChatGPT ruling Q2 "Proprietor
 * Moin" and Q3 "What about Moin", decisions D1, D2, D5, D8).
 *
 * The cases the plan names (F5): the withdrawal is priced at the RESERVED
 * batch's cost (the FIFO head, not the newest); the journal is Dr 3200 /
 * Cr 1150 with no revenue, no customer, no invoice; authorisation is required
 * (a second person, or the proprietor with a note); a reimbursement receipt
 * credits 3200; the monthly report totals; the drawings head is found by
 * slug and never planted.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-17 10:00:00');

    $this->business = Business::create(['name' => 'Guna Co', 'slug' => 'guna-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Finance', 'Manager', 'Staff'] as $name) {
        Role::firstOrCreate(['name' => $name, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }

    (new AccountingPermissionsSeeder)->run();
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->asRole = function (string $role, string $name) {
        $user = User::factory()->create(['name' => $name]);
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->moin = ($this->asRole)('Owner', 'C M Moin');
    $this->finance = ($this->asRole)('Finance', 'Musa');
    $this->manager = ($this->asRole)('Manager', 'Joy');
    $this->staff = ($this->asRole)('Staff', 'Rahim');

    $this->business->update(['proprietor_user_id' => $this->moin->id]);
    $this->business->refresh();

    $kg = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAV', 'is_default' => true]);
    $this->ghee = Product::create(['business_id' => $this->business->id, 'unit_of_measure_id' => $kg->id, 'sku' => 'GHEE', 'name' => 'Ghee', 'type' => ProductType::FinishedGood, 'mrp' => '50.0000']);
    $this->jar = Product::create(['business_id' => $this->business->id, 'unit_of_measure_id' => $kg->id, 'sku' => 'JAR', 'name' => 'Jar', 'type' => ProductType::RawMaterial]);

    $in = fn (Product $product, string $qty, string $cost, string $number, string $on) => StockBatch::findOrFail(app(RecordStockIn::class)->handle(
        $product, $this->warehouse, $qty, $cost, $this->moin, batchNumber: $number, occurredAt: $on,
    )->batch_id);

    // FIFO order: A (older, 20.00) before B (newer, 30.00).
    $this->batchA = $in($this->ghee, '10', '20.00', 'A', '2026-10-01 09:00:00');
    $this->batchB = $in($this->ghee, '10', '30.00', 'B', '2026-10-05 09:00:00');
    $this->jars = $in($this->jar, '100', '5.00', 'J', '2026-10-02 09:00:00');

    $this->draft = fn (array $lines, string $on = '2026-10-15') => app(SaveOwnerWithdrawal::class)->handle($this->warehouse, $on, $lines, $this->moin);
    $this->authorise = fn (OwnerWithdrawal $w, ?User $by = null, ?string $note = null) => app(AuthoriseOwnerWithdrawal::class)->handle($w, $by ?? $this->finance, $note);
    $this->post = fn (OwnerWithdrawal $w) => app(PostOwnerWithdrawal::class)->handle($w->fresh(), $this->moin);
    $this->balanceOn = fn (string $slug) => (int) JournalLine::where('account_id', Account::bySlug($slug)->id)->sum('amount_minor');
});

afterEach(fn () => Carbon::setTestNow());

it('saves a numbered draft for the proprietor named in Settings, with no price yet', function () {
    $w = ($this->draft)([['product_id' => $this->ghee->id, 'quantity' => '4']]);

    expect($w->number)->toStartWith('OW-2026-27-')
        ->and($w->status)->toBe(OwnerWithdrawalStatus::Draft)
        ->and($w->proprietor_user_id)->toBe($this->moin->id)
        ->and($w->lines)->toHaveCount(1)
        ->and($w->lines->first()->unit_cost)->toBeNull()
        ->and($w->lines->first()->stock_batch_id)->toBeNull()
        ->and(StockReservation::count())->toBe(0);

    // Nobody named and no BDG-000001: refused, never silently booked to somebody.
    $this->business->update(['proprietor_user_id' => null]);
    expect(fn () => ($this->draft)([['product_id' => $this->ghee->id, 'quantity' => '1']]))->toThrow(ValidationException::class, 'proprietor');
});

it('falls back to the Owner-role user whose employee is BDG-000001 while Settings names nobody', function () {
    $this->business->update(['proprietor_user_id' => null]);
    $this->business->refresh();

    expect(Proprietor::userFor($this->business))->toBeNull();

    Employee::create(['employee_code' => Proprietor::SEED_EMPLOYEE_CODE, 'user_id' => $this->moin->id, 'first_name' => 'Chowdhury Mohammad', 'last_name' => 'Moin', 'employment_type' => 'permanent', 'status' => 'active']);

    expect(Proprietor::userFor($this->business)?->id)->toBe($this->moin->id)
        ->and(Proprietor::is($this->moin, $this->business))->toBeTrue()
        ->and(Proprietor::is($this->finance, $this->business))->toBeFalse();
});

it('authorises by a second person: the FIFO head is reserved and its cost frozen, the MRP kept as the reference', function () {
    $w = ($this->authorise)(($this->draft)([['product_id' => $this->ghee->id, 'quantity' => '4']]));
    $line = $w->lines->first();

    expect($w->status)->toBe(OwnerWithdrawalStatus::Approved)
        ->and($w->authorised_by)->toBe($this->finance->id)
        ->and($w->self_authorised)->toBeFalse()
        // The FIFO head A at 20.00, not the newest B at 30.00 (ruling Q1).
        ->and($line->stock_batch_id)->toBe($this->batchA->id)
        ->and((string) $line->unit_cost)->toBe('20.0000')
        ->and($line->cost()->toTaka())->toBe('80.00')
        ->and($line->reference_source)->toBe('mrp')
        ->and((string) $line->reference_price)->toBe('50.0000')
        ->and($line->reference()->toTaka())->toBe('200.00')
        ->and($line->difference()->toTaka())->toBe('120.00')
        ->and($w->costTotal()->toTaka())->toBe('80.00')
        ->and($w->referenceTotal()->toTaka())->toBe('200.00')
        ->and(StockReservation::heldOn($this->batchA->id))->toBe('4.0000')
        // Nothing has left the shelf yet.
        ->and((string) $this->batchA->fresh()->current_quantity)->toBe('10.0000');
});

it('lets the proprietor authorise himself only with a note, and refuses anybody else without the permission', function () {
    $w = ($this->draft)([['product_id' => $this->ghee->id, 'quantity' => '2']]);

    expect(fn () => ($this->authorise)($w, $this->moin))->toThrow(ValidationException::class, 'note');
    expect(fn () => ($this->authorise)($w, $this->staff))->toThrow(RuntimeException::class, 'second person');
    expect($w->fresh()->status)->toBe(OwnerWithdrawalStatus::Draft)
        ->and(StockReservation::count())->toBe(0);

    $w = ($this->authorise)($w, $this->moin, 'Musa on leave, taken for the house');

    expect($w->status)->toBe(OwnerWithdrawalStatus::Approved)
        ->and($w->self_authorised)->toBeTrue()
        ->and($w->authorisation_note)->toBe('Musa on leave, taken for the house');

    // The Manager is the other second person.
    $second = ($this->authorise)(($this->draft)([['product_id' => $this->ghee->id, 'quantity' => '1']]), $this->manager);
    expect($second->self_authorised)->toBeFalse()->and($second->authorised_by)->toBe($this->manager->id);
});

it('posts: stock leaves the reserved batch at its cost, Dr 3200 Owner\'s Drawings / Cr 1150 Stock on Hand, no revenue and no customer', function () {
    $w = ($this->authorise)(($this->draft)([['product_id' => $this->ghee->id, 'quantity' => '4'], ['product_id' => $this->jar->id, 'quantity' => '10']]));

    $drawingsBefore = ($this->balanceOn)('owners_drawings');
    $stockBefore = ($this->balanceOn)('stock_on_hand');

    // Nothing may be posted until authorised: a fresh draft is refused.
    expect(fn () => ($this->post)(($this->draft)([['product_id' => $this->ghee->id, 'quantity' => '1']])))->toThrow(RuntimeException::class, 'cannot be posted');

    $w = ($this->post)($w);

    expect($w->status)->toBe(OwnerWithdrawalStatus::Posted)
        ->and($w->journal_id)->not->toBeNull()
        ->and($w->posted_by)->toBe($this->moin->id)
        ->and($w->costTotal()->toTaka())->toBe('130.00');

    // The movements: batch A (not B), reason owner_withdrawal, at 20.00.
    $out = StockMovement::query()->where('reason', MovementReason::OwnerWithdrawal)->get();
    expect($out)->toHaveCount(2)
        ->and($out->firstWhere('product_id', $this->ghee->id)->batch_id)->toBe($this->batchA->id)
        ->and((string) $out->firstWhere('product_id', $this->ghee->id)->unit_cost)->toBe('20.0000')
        ->and((string) $this->batchA->fresh()->current_quantity)->toBe('6.0000')
        ->and((string) $this->batchB->fresh()->current_quantity)->toBe('10.0000')
        ->and((string) $this->jars->fresh()->current_quantity)->toBe('90.0000');

    // The journal: exactly two heads, drawings up by 130, stock down by 130.
    $heads = JournalLine::where('journal_id', $w->journal_id)->pluck('account_id')->unique()->sort()->values()->all();
    expect($heads)->toBe(collect([Account::bySlug('owners_drawings')->id, Account::bySlug('stock_on_hand')->id])->sort()->values()->all())
        ->and(($this->balanceOn)('owners_drawings') - $drawingsBefore)->toBe(13000)
        ->and(($this->balanceOn)('stock_on_hand') - $stockBefore)->toBe(-13000)
        ->and(Account::bySlug('owners_drawings')->code)->toBe('3200');

    // Not a sale: no invoice, no customer, no revenue line anywhere.
    expect(DB::table('sales_invoices')->count())->toBe(0)
        ->and(DB::table('customers')->count())->toBe(0);

    // The hold is consumed, the line remembers its movement, the document is frozen.
    expect(StockReservation::query()->open()->count())->toBe(0)
        ->and($w->lines->first()->stock_movement_id)->not->toBeNull();
    expect(fn () => app(SaveOwnerWithdrawal::class)->handle($this->warehouse, '2026-10-15', [['product_id' => $this->ghee->id, 'quantity' => '1']], $this->moin, null, $w))
        ->toThrow(RuntimeException::class, 'cannot be edited');
});

it('splits a quantity the FIFO head cannot cover into one line per batch, each at its own cost', function () {
    $w = ($this->authorise)(($this->draft)([['product_id' => $this->ghee->id, 'quantity' => '12']]));

    expect($w->lines)->toHaveCount(2)
        ->and((string) $w->lines[0]->quantity)->toBe('10.0000')
        ->and((string) $w->lines[0]->unit_cost)->toBe('20.0000')
        ->and($w->lines[0]->stock_batch_id)->toBe($this->batchA->id)
        ->and((string) $w->lines[1]->quantity)->toBe('2.0000')
        ->and((string) $w->lines[1]->unit_cost)->toBe('30.0000')
        ->and($w->lines[1]->stock_batch_id)->toBe($this->batchB->id)
        ->and($w->costTotal()->toTaka())->toBe('260.00');

    // More than the shelf holds: refused, nothing reserved.
    expect(fn () => ($this->authorise)(($this->draft)([['product_id' => $this->ghee->id, 'quantity' => '9']])))->toThrow(RuntimeException::class);
    expect(StockReservation::query()->open()->sum('quantity'))->toEqual(12);
});

it('keeps a sale off the batch an authorised withdrawal holds', function () {
    ($this->authorise)(($this->draft)([['product_id' => $this->ghee->id, 'quantity' => '10']]));

    $movements = app(RecordStockOut::class)->handle($this->ghee, $this->warehouse, '3', $this->moin);

    expect($movements->first()->batch_id)->toBe($this->batchB->id)
        ->and((string) $movements->first()->unit_cost)->toBe('30.0000');
});

it('sends the document back for re-approval when the reserved batch was re-costed, never re-pricing it quietly', function () {
    $w = ($this->authorise)(($this->draft)([['product_id' => $this->ghee->id, 'quantity' => '4']]));

    // A landed cost restated batch A after the authorisation.
    StockBatch::query()->whereKey($this->batchA->id)->update(['unit_cost' => '25.0000']);

    expect(fn () => ($this->post)($w))->toThrow(RuntimeException::class, 're-approval');

    $w->refresh();
    expect($w->status)->toBe(OwnerWithdrawalStatus::Draft)
        ->and($w->reapproval_reason)->toContain('25.0000')
        ->and($w->authorised_by)->toBeNull()
        ->and($w->lines->first()->unit_cost)->toBeNull()
        ->and(StockReservation::query()->open()->count())->toBe(0)
        ->and(StockMovement::query()->where('reason', MovementReason::OwnerWithdrawal)->count())->toBe(0);

    // Authorised again at the new cost, it posts.
    $w = ($this->post)(($this->authorise)($w));
    expect($w->costTotal()->toTaka())->toBe('100.00');
});

it('sends an authorised withdrawal back to draft on request, releasing the holds', function () {
    $w = ($this->authorise)(($this->draft)([['product_id' => $this->ghee->id, 'quantity' => '4']]));

    $w = app(ReopenOwnerWithdrawal::class)->handle($w);

    expect($w->status)->toBe(OwnerWithdrawalStatus::Draft)
        ->and($w->reapproval_reason)->toBeNull()
        ->and($w->cost_total_paisa)->toBe(0)
        ->and(StockReservation::heldOn($this->batchA->id))->toBe('0.0000');

    expect(fn () => app(ReopenOwnerWithdrawal::class)->handle($w))->toThrow(RuntimeException::class);
});

it('takes the money the owner pays in as a receipt crediting 3200, which the mapping leaves usable on a voucher', function () {
    $w = ($this->post)(($this->authorise)(($this->draft)([['product_id' => $this->ghee->id, 'quantity' => '4']])));
    $drawings = Account::bySlug('owners_drawings');

    // Mapped for inventory.owner_withdrawal, yet NOT owned by Inventory: the
    // receipt voucher must still be able to credit it.
    expect(AccountMapping::resolve('inventory.owner_withdrawal', Carbon::today())->id)->toBe($drawings->id)
        ->and(SubsystemAccounts::on(Carbon::today()))->not->toHaveKey($drawings->id)
        ->and(VoucherAccounts::refusal($drawings, Carbon::today(), VoucherType::Receipt))->toBeNull()
        // Stock on Hand stays Inventory's.
        ->and(SubsystemAccounts::on(Carbon::today()))->toHaveKey(Account::bySlug('stock_on_hand')->id);

    $cash = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Cash box', 'type' => 'cash', 'is_active' => true,
        'account_id' => Account::bySlug('cash_in_hand')->id,
    ]);

    $before = ($this->balanceOn)('owners_drawings');

    app(PostVoucher::class)->handle(app(SaveVoucherDraft::class)->handle(VoucherType::Receipt, [
        'voucher_date' => '2026-10-16', 'money_account_id' => $cash->id, 'counterparty' => 'C M Moin',
        'narration' => "Owner reimbursement {$w->number}",
        'lines' => [['account_id' => $drawings->id, 'amount' => $w->costTotal()->toTaka()]],
    ], $this->finance), $this->finance);

    expect(($this->balanceOn)('owners_drawings') - $before)->toBe(-8000)
        ->and(($this->balanceOn)('cash_in_hand'))->toBe(8000);
});

it('reports owner withdrawals by month: count, cost, reference and the difference, posted documents only', function () {
    ($this->post)(($this->authorise)(($this->draft)([['product_id' => $this->ghee->id, 'quantity' => '4']], '2026-10-10')));
    ($this->post)(($this->authorise)(($this->draft)([['product_id' => $this->ghee->id, 'quantity' => '2'], ['product_id' => $this->jar->id, 'quantity' => '10']], '2026-10-15')));
    // Authorised but not posted: not in the report.
    ($this->authorise)(($this->draft)([['product_id' => $this->ghee->id, 'quantity' => '1']], '2026-10-16'));

    $months = OwnerWithdrawalsMonthlyReport::months();

    expect($months)->toHaveCount(1)
        ->and($months[0]['month'])->toBe('2026-10')
        ->and($months[0]['count'])->toBe(2)
        ->and($months[0]['lines'])->toBe(3)
        // 4 x 20 + 2 x 20 + 10 x 5 = 170 at cost.
        ->and($months[0]['cost'])->toBe('170.00')
        // 6 x 50 at MRP; the jars have no reference.
        ->and($months[0]['reference'])->toBe('300.00')
        // 300 - (6 x 20): only the referenced lines count.
        ->and($months[0]['difference'])->toBe('180.00')
        ->and($months[0]['unreferenced'])->toBe(1);

    $detail = OwnerWithdrawalsMonthlyReport::month('2026-10');

    expect($detail['rows'])->toHaveCount(3)
        ->and($detail['totals']['cost'])->toBe('170.00')
        ->and($detail['totals']['reference'])->toBe('300.00')
        ->and($detail['totals']['difference'])->toBe('180.00')
        ->and($detail['rows'][0]['reference_source'])->toBe('mrp')
        ->and($detail['rows'][2]['reference_source'])->toBe('none')
        ->and(OwnerWithdrawalsMonthlyReport::month('2026-09')['rows'])->toBe([]);
});

it('finds the drawings head by slug and never plants a second one', function () {
    $this->seed(InventoryAccountMappingSeeder::class);

    expect(Account::query()->where('system_slug', 'owners_drawings')->count())->toBe(1)
        ->and(Account::query()->where('code', '3200')->count())->toBe(1)
        ->and(AccountMapping::query()->where('key', 'inventory.owner_withdrawal')->count())->toBe(1);
});
