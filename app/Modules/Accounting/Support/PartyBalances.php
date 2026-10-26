<?php

namespace App\Modules\Accounting\Support;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Collection;

/**
 * ⭐ Who owes what, everyone on one page (Joy, 26 Oct 2026: "from two place i
 * needs to see how much ayesha ows, this is a very big problem ... not only
 * for ayesha, for all i needs in one place").
 *
 * One row per party:
 *   - a customer: what they owe on their invoices (1140) less any advance of
 *     theirs we still hold (2150);
 *   - a supplier: our advance still with them (1160) less what we owe on their
 *     bills (2110);
 *   - a firm that is BOTH (customers.supplier_id), ONE row with all four, the
 *     same arithmetic as PartyPosition (the "Also our supplier" card);
 *   - a courier: what it collected for us and has not paid out yet (1145).
 *
 * ⚠ Read from the LEDGER, each party's balance on its own control account,
 * the figure the subsidiary-ledger reconciliation proves against the control
 * account every night. Nothing is stored; the page is always today's books.
 *
 * `net` is signed from our side: positive = they owe us, negative = we owe them.
 */
final class PartyBalances
{
    /**
     * @return Collection<int, array{key: string, kind: string, name: string, phone: ?string, customer_id: ?int, supplier_id: ?int, money_account_id: ?int, they_owe: int, our_advance: int, we_owe: int, their_advance: int, net: int}>
     */
    public static function rows(?User $user): Collection
    {
        $ar = self::byParty('accounts_receivable', Customer::class);
        $customerAdvances = self::byParty('customer_advances', Customer::class);
        $ap = self::byParty('accounts_payable', Supplier::class);
        $supplierAdvances = self::byParty('supplier_advances', Supplier::class);

        $customerIds = $ar->keys()->merge($customerAdvances->keys())->unique();
        $supplierIds = $ap->keys()->merge($supplierAdvances->keys())->unique();

        // ⚠ The customer list's own visibility: a staff purchase account is
        // shown to the Owner, HR authority and Finance only.
        $customers = Customer::query()->listable($user)
            ->where(fn ($q) => $q->whereIn('id', $customerIds)->orWhereIn('supplier_id', $supplierIds))
            ->get(['id', 'name', 'phone', 'supplier_id', 'type'])
            ->keyBy('id');
        $suppliers = Supplier::query()
            ->whereIn('id', $supplierIds->merge($customers->pluck('supplier_id')->filter())->unique())
            ->get(['id', 'name', 'phone'])
            ->keyBy('id');

        $rows = collect();
        $suppliersShown = [];

        foreach ($customers as $customer) {
            if ($customer->isWalkIn() && (int) ($ar[$customer->id] ?? 0) === 0 && (int) ($customerAdvances[$customer->id] ?? 0) === 0) {
                continue;
            }

            $supplier = $customer->supplier_id !== null ? $suppliers->get($customer->supplier_id) : null;

            if ($supplier !== null) {
                $suppliersShown[$supplier->id] = true;
            }

            $rows->push(self::row(
                kind: $supplier !== null ? 'both' : 'customer',
                name: $customer->name,
                phone: $customer->phone ?? $supplier?->phone,
                customerId: $customer->id,
                supplierId: $supplier?->id,
                moneyAccountId: null,
                theyOwe: (int) ($ar[$customer->id] ?? 0),
                theirAdvance: -(int) ($customerAdvances[$customer->id] ?? 0),
                weOwe: $supplier !== null ? -(int) ($ap[$supplier->id] ?? 0) : 0,
                ourAdvance: $supplier !== null ? (int) ($supplierAdvances[$supplier->id] ?? 0) : 0,
            ));
        }

        foreach ($suppliers as $supplier) {
            if (isset($suppliersShown[$supplier->id]) || ! $supplierIds->contains($supplier->id)) {
                continue;
            }

            $rows->push(self::row(
                kind: 'supplier',
                name: $supplier->name,
                phone: $supplier->phone,
                customerId: null,
                supplierId: $supplier->id,
                moneyAccountId: null,
                theyOwe: 0,
                theirAdvance: 0,
                weOwe: -(int) ($ap[$supplier->id] ?? 0),
                ourAdvance: (int) ($supplierAdvances[$supplier->id] ?? 0),
            ));
        }

        foreach (self::couriers() as $courier) {
            $rows->push(self::row(
                kind: 'courier',
                name: $courier['name'],
                phone: null,
                customerId: null,
                supplierId: null,
                moneyAccountId: $courier['id'],
                theyOwe: $courier['balance'],
                theirAdvance: 0,
                weOwe: 0,
                ourAdvance: 0,
            ));
        }

        return $rows
            ->reject(fn (array $row) => $row['they_owe'] === 0 && $row['our_advance'] === 0 && $row['we_owe'] === 0 && $row['their_advance'] === 0)
            ->sortBy([['net', 'desc'], ['name', 'asc']])
            ->values();
    }

    /** @return array{they_owe: int, we_owe: int, net: int, owe_us_count: int, we_owe_count: int} in paisa */
    public static function totals(Collection $rows): array
    {
        return [
            'they_owe' => (int) $rows->where('net', '>', 0)->sum('net'),
            'we_owe' => (int) -$rows->where('net', '<', 0)->sum('net'),
            'net' => (int) $rows->sum('net'),
            'owe_us_count' => $rows->where('net', '>', 0)->count(),
            'we_owe_count' => $rows->where('net', '<', 0)->count(),
        ];
    }

    /** @return array<string, mixed> */
    private static function row(string $kind, string $name, ?string $phone, ?int $customerId, ?int $supplierId, ?int $moneyAccountId, int $theyOwe, int $theirAdvance, int $weOwe, int $ourAdvance): array
    {
        return [
            'key' => "{$kind}-".($customerId ?? $supplierId ?? $moneyAccountId),
            'kind' => $kind,
            'name' => $name,
            'phone' => $phone,
            'customer_id' => $customerId,
            'supplier_id' => $supplierId,
            'money_account_id' => $moneyAccountId,
            'they_owe' => $theyOwe,
            'our_advance' => $ourAdvance,
            'we_owe' => $weOwe,
            'their_advance' => $theirAdvance,
            'net' => $theyOwe + $ourAdvance - $weOwe - $theirAdvance,
        ];
    }

    /**
     * Each party's signed balance (debit positive) on one control account.
     *
     * @return Collection<int, int> party id => paisa
     */
    private static function byParty(string $slug, string $model): Collection
    {
        $account = Account::bySlug($slug);

        if ($account === null) {
            return collect();
        }

        return JournalLine::query()
            ->where('account_id', $account->id)
            ->where('party_type', (new $model)->getMorphClass())
            ->whereNotNull('party_id')
            ->groupBy('party_id')
            ->selectRaw('party_id, SUM(amount_minor) as balance')
            ->pluck('balance', 'party_id')
            ->map(fn ($v) => (int) $v);
    }

    /** @return list<array{id: int, name: string, balance: int}> what each courier still holds for us on 1145 */
    private static function couriers(): array
    {
        $account = Account::bySlug('courier_receivable');

        if ($account === null) {
            return [];
        }

        $balances = JournalLine::query()
            ->where('account_id', $account->id)
            ->whereNotNull('money_account_id')
            ->groupBy('money_account_id')
            ->selectRaw('money_account_id, SUM(amount_minor) as balance')
            ->pluck('balance', 'money_account_id');

        $names = MoneyAccount::query()->whereIn('id', $balances->keys())->pluck('name', 'id');

        return $balances
            ->map(fn ($balance, $id) => ['id' => (int) $id, 'name' => (string) ($names[$id] ?? "Courier #{$id}"), 'balance' => (int) $balance])
            ->values()
            ->all();
    }
}
