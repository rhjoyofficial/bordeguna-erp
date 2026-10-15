<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Enums\AgeingBucket;
use App\Modules\Accounting\Support\Ageing;
use App\Modules\Accounting\Support\AgeingRow;
use App\Modules\Accounting\Support\Money;
use App\Modules\Purchasing\Actions\RollRecurringExpenses;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierCertification;
use App\Modules\Purchasing\Support\PurchasesPulse;
use App\Modules\Purchasing\Support\StandingCosts;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Purchases overview (module redesign, 15 October 2026): where purchases
 * stand step by step (order, approve, receive, bill, pay), what needs somebody,
 * what the business owes suppliers and how old it is, what it spent, the
 * standing costs coming up, the latest deliveries and the suppliers, on one
 * page, every number a link to the list behind it.
 *
 * ⚠⚠ NUMBERS ONLY FROM EXISTING ROWS. The chain and the money strip come from
 * PurchasesPulse (the same scopes the lists use); what is owed by age is
 * `Ageing::payables()`, the figure the Payables ageing report shows, never
 * re-derived here; the standing costs are StandingCosts, the Recurring screen's
 * own list.
 *
 * ⚠ The payables card needs `accounting.view`, the gate on the ageing report
 * it summarises. Without it the card is sent as null, not as zeros, and the
 * "We owe now" step of the chain is absent.
 */
class PurchasesOverviewController extends Controller
{
    public function index(Request $request, RollRecurringExpenses $roll): Response
    {
        $user = $request->user();
        $today = Carbon::today();

        // ⚠ Rolled on read, as the Recurring screen does: a schedule added this
        // morning is due this morning. Keyed and idempotent, so it cannot duplicate.
        $roll->handle();

        $can = [
            'accounting' => $user->can('accounting.view'),
            'create_order' => $user->can('purchasing.po.create'),
            'record_expense' => $user->can('purchasing.expense.record'),
            'record_payment' => $user->can('purchasing.payment.record'),
        ];

        $chain = PurchasesPulse::chain($today);
        $strip = PurchasesPulse::moneyStrip($today);
        $payables = $can['accounting'] ? $this->payables($today) : null;
        $standing = $this->standing($today);

        return Inertia::render('purchasing/overview', [
            'today' => $today->toDateString(),
            'can' => $can,
            'chain' => $chain + ['owed' => $payables === null ? null : [
                'total' => $payables['total'],
                'overdue' => $payables['overdue'],
                'url' => '/accounting/payables-ageing',
            ]],
            'needs' => $this->needs($chain, $strip, $standing),
            'payables' => $payables,
            'spend' => $this->spend($today),
            'standing' => $standing,
            'deliveries' => PurchasesPulse::recentDeliveries($today),
            'suppliers' => $this->suppliers($today),
        ]);
    }

    /**
     * What the business owes suppliers by age: the Payables ageing, summarised.
     *
     * @return array<string, mixed>
     */
    private function payables(Carbon $today): array
    {
        $ageing = Ageing::payables($today);
        $total = $ageing->total();
        $overdue = Money::sum(collect(AgeingBucket::ordered())
            ->reject(fn (AgeingBucket $bucket) => $bucket === AgeingBucket::Current)
            ->map(fn (AgeingBucket $bucket) => $ageing->bucket($bucket)));
        $aged = $total->minor - $ageing->onAccount()->minor;

        return [
            'total' => $total->format(),
            'overdue' => $overdue->format(),
            'on_account' => $ageing->onAccount()->format(),
            'ties_out' => $ageing->tiesOut(),
            'buckets' => collect(AgeingBucket::ordered())->map(function (AgeingBucket $bucket) use ($ageing, $aged) {
                $amount = $ageing->bucket($bucket);

                return [
                    'key' => $bucket->value,
                    'label' => $bucket->label(),
                    'amount' => $amount->format(),
                    'share' => $aged > 0 ? (int) round($amount->minor * 100 / $aged) : 0,
                ];
            })->values()->all(),
            'top' => $ageing->rows
                ->sortByDesc(fn (AgeingRow $row) => $row->total()->minor)
                ->take(3)
                ->map(fn (AgeingRow $row) => [
                    'id' => $row->partyId,
                    'name' => $row->partyName,
                    'amount' => $row->total()->format(),
                    'overdue' => $row->aged()->minor > $row->bucket(AgeingBucket::Current)->minor,
                    'url' => '/purchasing/suppliers/'.$row->partyId,
                ])->values()->all(),
            'url' => '/accounting/payables-ageing',
        ];
    }

    /**
     * Running costs for the three period chips: this month to date, last
     * month, this quarter to date.
     *
     * @return array<string, array<string, mixed>>
     */
    private function spend(Carbon $today): array
    {
        $lastMonth = $today->copy()->subMonthNoOverflow();
        $quarterStart = $today->copy()->firstOfQuarter();

        return [
            'month' => PurchasesPulse::spend($today->copy()->startOfMonth(), $today, $today->format('F Y').', to date'),
            'last' => PurchasesPulse::spend($lastMonth->copy()->startOfMonth(), $lastMonth->copy()->endOfMonth(), $lastMonth->format('F Y')),
            'quarter' => PurchasesPulse::spend(
                $quarterStart,
                $today,
                $quarterStart->format('M').' to '.$today->copy()->lastOfQuarter()->format('M Y').', to date',
            ),
        ];
    }

    /**
     * The standing costs: how many, the run rate, the next few due.
     *
     * @return array<string, mixed>
     */
    private function standing(Carbon $today): array
    {
        $totals = StandingCosts::totals();

        return [
            'active' => $totals['count'],
            'monthly' => Money::ofTaka((string) $totals['monthly'])->format(),
            'annual' => Money::ofTaka((string) $totals['annual'])->format(),
            'due' => StandingCosts::due(30)->map(fn (array $row) => [
                'id' => $row['id'],
                'name' => $row['name'],
                'period_label' => $row['period_label'],
                'due_on' => $row['due_on'],
                'days_away' => $row['days_away'],
                'expected_amount' => Money::ofTaka((string) $row['expected_amount'])->format(),
                'amount_varies' => $row['amount_varies'],
                // The expense dialog, already filled in (RecurringExpenseController).
                'url' => '/purchasing/expenses?from_recurring='.$row['id'],
            ])->take(5)->values()->all(),
            'due_week' => StandingCosts::due(7)->count(),
            'url' => '/purchasing/recurring-expenses',
        ];
    }

    /**
     * The suppliers: active, with a balance, a certificate running out.
     *
     * @return array<string, mixed>
     */
    private function suppliers(Carbon $today): array
    {
        $withBalance = SupplierBill::query()->owingWithOutstanding()->get()->pluck('supplier_id')->unique();
        $expiring = SupplierCertification::query()
            ->with('supplier:id,name')
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', $today->copy()->addDays(60)->toDateString())
            ->orderBy('expires_on')
            ->get();

        return [
            'active' => Supplier::query()->where('is_active', true)->count(),
            'with_balance' => $withBalance->count(),
            'certificate_expiring' => $expiring->count(),
            'rows' => $expiring->take(3)->map(fn (SupplierCertification $c) => [
                'id' => $c->supplier_id,
                'name' => $c->supplier?->name,
                'note' => ($c->name ?: $c->type->value).' '.($c->expires_on->lt($today) ? 'ended' : 'ends').' '.$c->expires_on->format('j M'),
                'tone' => $c->expires_on->lt($today) ? 'danger' : 'warning',
                'url' => '/purchasing/suppliers/'.$c->supplier_id,
            ])->values()->all(),
            'url' => '/purchasing/suppliers',
        ];
    }

    /**
     * The "Needs you" rows, loudest first. Only rows with something in them.
     *
     * @param  array<string, mixed>  $chain
     * @param  array<string, mixed>  $strip
     * @param  array<string, mixed>  $standing
     * @return list<array{key: string, title: string, hint: string, count: int, tone: string, url: string}>
     */
    private function needs(array $chain, array $strip, array $standing): array
    {
        $rows = [
            [
                'key' => 'overdue_bills',
                'title' => $strip['overdue']['count'] === 1 && $strip['overdue']['oldest_days'] !== null
                    ? 'Bill overdue '.$strip['overdue']['oldest_days'].' days'
                    : 'Bills overdue',
                'hint' => 'Tk '.$strip['overdue']['amount'].' past the due date',
                'count' => $strip['overdue']['count'],
                'tone' => 'danger',
                'url' => $strip['overdue']['url'],
            ],
            [
                'key' => 'to_approve',
                'title' => 'Purchase orders to approve',
                'hint' => implode(', ', $chain['awaiting_approval']['numbers']) ?: 'Nothing waiting',
                'count' => $chain['awaiting_approval']['count'],
                'tone' => 'warning',
                'url' => $chain['awaiting_approval']['url'],
            ],
            [
                'key' => 'due_week',
                'title' => 'Bills due this week',
                'hint' => 'Tk '.$strip['due_week']['amount'].' falls due in the next 7 days',
                'count' => $strip['due_week']['count'],
                'tone' => 'warning',
                'url' => $strip['due_week']['url'],
            ],
            [
                'key' => 'standing_due',
                'title' => 'Standing costs due in 7 days',
                'hint' => 'Subscriptions and monthly bills to record',
                'count' => $standing['due_week'],
                'tone' => 'info',
                'url' => $standing['url'],
            ],
            [
                'key' => 'to_bill',
                'title' => 'Received, waiting for the supplier bill',
                'hint' => implode(', ', $chain['received_not_billed']['numbers']) ?: 'Nothing waiting',
                'count' => $chain['received_not_billed']['count'],
                'tone' => 'ok',
                'url' => $chain['received_not_billed']['url'],
            ],
        ];

        return array_values(array_filter($rows, fn (array $row) => $row['count'] > 0));
    }
}
