<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ B23 (25 Oct 2026; R111): the balance beside every party head on the chart.
 *
 * Joy: "Each employee, supplier, customer, account will have accounting
 * ledger head, auto created", findable by name. The heads exist
 * (EntityLedgerObserver: "1140-317 Rahim"), but they are non-postable labels
 * (PartyHeads): every line is posted to a control head (1140, 2110, 1170 and
 * their advance heads) with the person attached. So the chart showed the head
 * and no figure. This puts the person's figure on the head: the sum of every
 * line, on any head, that names them, Dr positive (they owe us), Cr negative
 * (we owe them), and the page that opens their lifetime.
 *
 * ⚠ Fixed query count: one read per kind of party for the heads, one grouped
 * sum for every balance, whatever the number of parties or lines.
 */
final class PartyHeadBalances
{
    /** @var array<class-string<Model>, array{0: string, 1: string}> class => [kind, page path prefix] */
    private const KINDS = [
        Customer::class => ['customer', '/sales/customers/'],
        Supplier::class => ['supplier', '/purchasing/suppliers/'],
        Employee::class => ['employee', '/hrm/employees/'],
    ];

    /**
     * Head account id => the party and its balance.
     *
     * @return array<int, array{kind: string, id: int, url: string, balance: string, side: string}>
     */
    public static function byHead(): array
    {
        $heads = [];
        $morphs = [];

        foreach (self::KINDS as $class => [$kind, $path]) {
            $morph = (new $class)->getMorphClass();
            $morphs[$morph] = $kind;

            foreach ($class::query()->whereNotNull('account_id')->pluck('account_id', 'id') as $id => $accountId) {
                $heads[$morph.'|'.$id] = ['account_id' => (int) $accountId, 'kind' => $kind, 'id' => (int) $id, 'url' => $path.$id];
            }
        }

        if ($heads === []) {
            return [];
        }

        $sums = DB::table('journal_lines')
            ->where('business_id', app(BusinessContext::class)->id())
            ->whereIn('party_type', array_keys($morphs))
            ->whereNotNull('party_id')
            ->groupBy('party_type', 'party_id')
            ->selectRaw('party_type, party_id, SUM(amount_minor) AS balance')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->party_type.'|'.$row->party_id => (int) $row->balance]);

        $out = [];

        foreach ($heads as $key => $head) {
            $balance = Money::ofMinor((int) ($sums[$key] ?? 0));

            $out[$head['account_id']] = [
                'kind' => $head['kind'],
                'id' => $head['id'],
                'url' => $head['url'],
                'balance' => $balance->absolute()->format(),
                'side' => $balance->minor > 0 ? 'dr' : ($balance->minor < 0 ? 'cr' : 'nil'),
            ];
        }

        return $out;
    }
}
