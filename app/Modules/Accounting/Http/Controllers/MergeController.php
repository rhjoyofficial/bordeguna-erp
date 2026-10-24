<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Actions\MergeParties;
use App\Modules\Accounting\Actions\MoveHeadBalanceAndRetire;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Corrections\ValidatesActionReason;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * ⭐ B13 (24 Oct 2026; FINAL-PLAN section 7 Phase 4 "Merges (customer,
 * supplier, ledger head 'Move balance and retire'), Owner only, dry run
 * first").
 *
 * Both steps answer JSON to the merge popup: the dry run shows what would
 * move (rows per table, balances before and after) and hands back a token;
 * the merge itself needs that token. A refusal is a 422 with one sentence
 * under `merge`. The Owner gate is the action's (Owners::is), checked again at
 * every step; the route only needs a signed-in member of the business.
 */
class MergeController extends Controller
{
    use ValidatesActionReason;

    public function party(Request $request, string $kind, int $id, MergeParties $merge): JsonResponse
    {
        $data = $request->validate($this->actionReasonRules('correction') + [
            'into_id' => ['required', 'integer'],
            'dry_run' => ['required', 'boolean'],
            'token' => ['nullable', 'string', 'max:2000'],
        ], $this->actionReasonMessages());

        $class = $kind === 'customer' ? Customer::class : Supplier::class;
        // Business-scoped: another business's record is simply not found.
        $gone = $class::query()->findOrFail($id);
        $kept = $class::query()->findOrFail((int) $data['into_id']);

        $report = $merge->run($gone, $kept, $request->user(), $this->actionReason($request), (bool) $data['dry_run'], $data['token'] ?? null);

        return response()->json($report + ['kept_url' => $kind === 'customer'
            ? route('sales.customers.show', $kept->getKey())
            : route('purchasing.suppliers.show', $kept->getKey())]);
    }

    public function head(Request $request, Account $account, MoveHeadBalanceAndRetire $move): JsonResponse
    {
        $data = $request->validate($this->actionReasonRules('correction') + [
            'into_id' => ['required', 'integer', Rule::exists('accounts', 'id')],
            'on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'dry_run' => ['required', 'boolean'],
            'token' => ['nullable', 'string', 'max:2000'],
        ], $this->actionReasonMessages() + ['on.before_or_equal' => 'The day cannot be in the future.']);

        $report = $move->run($account, Account::query()->findOrFail((int) $data['into_id']), Carbon::parse($data['on']), $request->user(), $this->actionReason($request), (bool) $data['dry_run'], $data['token'] ?? null);

        return response()->json($report);
    }
}
