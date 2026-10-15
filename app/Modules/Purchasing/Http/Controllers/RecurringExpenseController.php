<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\VoucherAccounts;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Actions\RollRecurringExpenses;
use App\Modules\Purchasing\Actions\SettleRecurringOccurrence;
use App\Modules\Purchasing\Enums\RecurringExpenseStatus;
use App\Modules\Purchasing\Enums\RecurringFrequency;
use App\Modules\Purchasing\Models\RecurringExpense;
use App\Modules\Purchasing\Models\RecurringExpenseOccurrence;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Support\StandingCosts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum as EnumRule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Standing costs: the subscriptions and monthly bills that arrive whether or not
 * anybody remembers them.
 *
 * ⛔⛔ NOTHING HERE POSTS. The screen predicts what is due and fills a form in;
 * the money is recorded by the ordinary expense screen, by a human, because a
 * voucher in this ERP is paid the moment it exists.
 */
class RecurringExpenseController extends Controller
{
    public function index(Request $request, RollRecurringExpenses $roll): Response
    {
        $this->authorize('purchasing.reports.view');

        // ⚠ Rolled on READ as well as nightly. A schedule added this morning
        // must appear in "due soon" this morning, not tomorrow - and because
        // generation is keyed and idempotent, doing it here costs nothing and
        // cannot duplicate.
        $roll->handle();

        $schedules = RecurringExpense::with(['supplier:id,name', 'expenseAccount:id,code,name', 'moneyAccount:id,name'])
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->get();

        return Inertia::render('purchasing/recurring-expenses/index', [
            'canRecord' => $request->user()?->can('purchasing.expense.record') ?? false,

            // ⚠ Overdue FIRST and never hidden. A bill missed last month is more
            // urgent this month, not less, and a list that quietly drops it is
            // how a system teaches people to stop trusting it. The Purchases
            // overview reads the same list (StandingCosts), so the two agree.
            'due' => StandingCosts::due(30),

            'schedules' => $schedules->map(fn (RecurringExpense $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'supplier' => $s->supplier?->name,
                'account' => $s->expenseAccount?->label(),
                'paid_from' => $s->moneyAccount?->name,
                'amount' => (float) $s->amount,
                'amount_varies' => (bool) $s->amount_varies,
                'frequency' => $s->frequency->value,
                'frequency_label' => $s->frequency->label(),
                'day_of_month' => $s->day_of_month,
                // ⚠ 31 means "the last day", which is what Nikola Host actually
                // is. Saying "31st" for February would be a lie the screen can
                // easily avoid telling.
                'day_label' => $s->day_of_month >= 29 ? 'last day of the month' : 'day '.$s->day_of_month,
                'status' => $s->status->value,
                'status_label' => $s->status->label(),
                'monthly_run_rate' => $s->monthlyRunRate(),
                'annual_commitment' => $s->annualCommitment(),
                'note' => $s->note,
            ]),

            'totals' => StandingCosts::totals($schedules),

            'frequencies' => collect(RecurringFrequency::cases())
                ->map(fn (RecurringFrequency $f) => ['value' => $f->value, 'label' => $f->label()]),

            'expenseAccounts' => Account::query()
                ->where('type', AccountType::Expense)->where('is_postable', true)->where('is_active', true)
                // PUR-002 (14 Oct 2026): not a head another module posts.
                ->whereNotIn('id', VoucherAccounts::refusedIds(Carbon::today()))
                ->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn (Account $a) => ['id' => $a->id, 'label' => $a->label()]),

            'moneyAccounts' => MoneyAccount::query()->handled()->where('is_active', true)
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn (MoneyAccount $a) => ['id' => $a->id, 'name' => $a->name]),

            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Supplier $s) => ['id' => $s->id, 'name' => $s->name]),
        ]);
    }

    public function store(Request $request, RollRecurringExpenses $roll): RedirectResponse
    {
        $this->authorize('purchasing.expense.record');

        $data = $this->validated($request);

        $schedule = RecurringExpense::create([
            ...$data,
            'business_id' => $this->businessId(),
            'created_by' => $request->user()->id,
        ]);

        // Straight away, so the person who just entered it sees what it means.
        $roll->forScheduleNow($schedule);

        return back()->with('success', "{$schedule->name} added. What it owes is listed above.");
    }

    public function update(Request $request, RecurringExpense $recurringExpense, RollRecurringExpenses $roll): RedirectResponse
    {
        $this->authorize('purchasing.expense.record');

        $recurringExpense->update($this->validated($request));
        $roll->forScheduleNow($recurringExpense->fresh());

        return back()->with('success', "{$recurringExpense->name} updated.");
    }

    /**
     * Pause, resume or end a schedule.
     *
     * ⚠ Ending never deletes. A subscription that stopped in March still
     * explains March's costs.
     */
    public function status(Request $request, RecurringExpense $recurringExpense): RedirectResponse
    {
        $this->authorize('purchasing.expense.record');

        $data = $request->validate(['status' => ['required', new EnumRule(RecurringExpenseStatus::class)]]);

        $recurringExpense->update(['status' => RecurringExpenseStatus::from($data['status'])]);

        return back()->with('success', "{$recurringExpense->name} is now ".strtolower($recurringExpense->fresh()->status->label()).'.');
    }

    public function skip(Request $request, RecurringExpenseOccurrence $occurrence, SettleRecurringOccurrence $settle): RedirectResponse
    {
        $this->authorize('purchasing.expense.record');

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $settle->skipped($occurrence, $request->user(), $data['reason']);

        return back()->with('success', $occurrence->period_label.' marked as skipped.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $businessId = $this->businessId();

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // ⚠⚠ Scoped, never bare: a bare exists accepts another business's id,
            // which is then stored on the schedule and paid from every month.
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('business_id', $businessId)],
            // ⚠ The chart is shared, so this is checked by KIND rather than by
            // business: an expense head that can be posted to, the same rule
            // RecordExpenseVoucher refuses everything else by. A revenue or
            // bank account here only failed later, on the day it fell due.
            'expense_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')
                ->where('type', AccountType::Expense->value)
                ->where('is_postable', true)
                ->where('is_active', true),
                // ⚠ PUR-002 (audit 14 Oct 2026): nor a head another module
                // owns, the rule RecordExpenseVoucher refuses it by on the day.
                Rule::notIn(VoucherAccounts::refusedIds(Carbon::today()))],
            'money_account_id' => ['nullable', 'integer', Rule::exists('money_accounts', 'id')->where('business_id', $businessId)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'amount_varies' => ['nullable', 'boolean'],
            'frequency' => ['required', new EnumRule(RecurringFrequency::class)],
            // ⚠ 31 is legal and means the last day. The generator clamps it, so
            // February gets the 28th or 29th rather than rolling into March.
            'day_of_month' => ['required', 'integer', 'min:1', 'max:31'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'reference_prefix' => ['nullable', 'string', 'max:32'],
            'lead_days' => ['nullable', 'integer', 'min:0', 'max:60'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function businessId(): int
    {
        return app(BusinessContext::class)->id();
    }
}
