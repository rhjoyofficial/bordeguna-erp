<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Actions\ReturnPurchasedGoods;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Support\RefusalMessage;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * ⭐ Initial plan B9 (R66, R68, R69; Joy's Q21, 25 Oct 2026): the "Return"
 * popup on the goods receipt page and on the supplier bill page. One request
 * sends the goods back from that delivery's batches and settles what the
 * supplier owes back (ReturnPurchasedGoods).
 *
 * ⚠ `purchasing.po.approve`, as returning from the order page always needed.
 * Crediting a billed return also needs `purchasing.credit_note.record`, and
 * a refund `purchasing.advance.record`, the permissions of the documents it
 * writes.
 */
class PurchaseReturnFromDocumentController extends Controller
{
    public function fromReceipt(Request $request, GoodsReceipt $goodsReceipt, ReturnPurchasedGoods $action): RedirectResponse
    {
        $goodsReceipt->loadMissing('purchaseOrder');

        if ($goodsReceipt->purchaseOrder === null) {
            throw ValidationException::withMessages(['lines' => 'This delivery has no purchase order to return against.']);
        }

        return $this->record($request, $goodsReceipt->purchaseOrder, $action, receipt: $goodsReceipt);
    }

    public function fromBill(Request $request, SupplierBill $bill, ReturnPurchasedGoods $action): RedirectResponse
    {
        $order = $bill->purchaseOrder ?? PurchaseOrder::query()
            ->whereHas('lines', fn ($q) => $q->whereIn('id', $bill->lines()->whereNotNull('purchase_order_line_id')->pluck('purchase_order_line_id')))
            ->first();

        if ($order === null) {
            throw ValidationException::withMessages(['lines' => "Bill {$bill->bill_number} has no purchased goods to return."]);
        }

        return $this->record($request, $order, $action, bill: $bill);
    }

    private function record(Request $request, PurchaseOrder $order, ReturnPurchasedGoods $action, ?GoodsReceipt $receipt = null, ?SupplierBill $bill = null): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'returned_on' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'request_key' => ['required', 'string', 'max:64'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'settle' => ['required', Rule::in([ReturnPurchasedGoods::SETTLE_ADVANCE, ReturnPurchasedGoods::SETTLE_REFUND])],
            'refunds' => ['array'],
            'refunds.*.money_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')->where('business_id', $businessId)],
            'refunds.*.amount' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'credit_note_number' => ['nullable', 'string', 'max:60'],
        ], [
            'returned_on.before_or_equal' => 'A return cannot be dated in the future.',
            'reason.min' => 'Say why the goods go back (at least 5 characters).',
        ]);

        $order->load('lines');

        $lineReturns = collect($data['lines'])
            ->map(fn (array $entry) => [
                'line' => $order->lines->firstWhere('id', (int) $entry['purchase_order_line_id']),
                'quantity' => (string) ($entry['quantity'] ?? '0'),
            ])
            ->filter(fn (array $entry) => $entry['line'] !== null)
            ->values()
            ->all();

        $billed = ReturnPurchasedGoods::billedValue(array_values(array_filter($lineReturns, fn (array $entry) => (float) $entry['quantity'] > 0)));

        if ($billed->minor > 0 && ! $request->user()->can('purchasing.credit_note.record')) {
            throw ValidationException::withMessages(['lines' => 'These goods were billed, so the supplier owes them back, and recording that credit needs the credit note permission. Ask Finance or the Owner.']);
        }

        $refunds = $data['settle'] === ReturnPurchasedGoods::SETTLE_REFUND ? ($data['refunds'] ?? []) : [];

        if ($refunds !== [] && ! $request->user()->can('purchasing.advance.record')) {
            throw ValidationException::withMessages(['refunds' => 'Recording a refund from the supplier needs the advance permission. Keep the credit as an advance instead, or ask Finance.']);
        }

        // ⚠ Double click after the first one finished (the in-flight guard
        // only covers the same request while it runs): the popup sends one key
        // per opening, and a key is used once.
        if (! Cache::add('purchase-return:'.$request->user()->id.':'.$data['request_key'], true, 600)) {
            throw ValidationException::withMessages(['lines' => 'This return was already recorded. Reload the page to see it.']);
        }

        try {
            $result = $action->handle(
                order: $order,
                lineReturns: $lineReturns,
                returnedOn: $data['returned_on'],
                reason: $data['reason'],
                by: $request->user(),
                receipt: $receipt,
                bill: $bill,
                settle: $data['settle'],
                refundLines: array_map(fn (array $row) => [
                    'money_account' => MoneyAccount::query()->findOrFail((int) $row['money_account_id']),
                    'amount' => (string) $row['amount'],
                ], $refunds),
                creditNoteNumber: $data['credit_note_number'] ?? null,
            );
        } catch (ValidationException $e) {
            Cache::forget('purchase-return:'.$request->user()->id.':'.$data['request_key']);

            throw $e;
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            Cache::forget('purchase-return:'.$request->user()->id.':'.$data['request_key']);

            throw ValidationException::withMessages(['lines' => RefusalMessage::of($e)]);
        }

        $return = $result['return'];
        $said = ["{$return->label()} recorded."];

        if ($result['credit_note'] !== null) {
            $said[] = "Credit note {$result['credit_note']->credit_note_number} reduced what the bill owes by ".Money::ofTaka((string) $result['credit_note']->total)->format().'.';
        }

        if ($result['advance'] !== null) {
            $said[] = "Advance {$result['advance']->number} holds ".$result['advance']->money()->format().' the supplier owes back.';
        }

        foreach ($result['refunds'] as $refund) {
            $said[] = "Refund {$refund->number}: ".$refund->money()->format()." into {$refund->moneyAccount?->name}.";
        }

        foreach ($result['pending'] as $number) {
            $said[] = "A refund needs a second person's approval and waits as {$number}.";
        }

        return redirect()->route('purchasing.returns.show', $return)->with('success', implode(' ', $said));
    }
}
