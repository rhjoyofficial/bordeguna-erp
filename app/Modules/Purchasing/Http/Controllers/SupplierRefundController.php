<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Actions\RecordSupplierRefund;
use App\Modules\Purchasing\Actions\ReverseSupplierRefund;
use App\Modules\Purchasing\Exceptions\PurchaseApprovalRequired;
use App\Modules\Purchasing\Http\Controllers\Concerns\ReversesDocuments;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierRefund;
use App\Modules\Purchasing\Support\PurchasingApprovalGate;
use App\Modules\Purchasing\Support\SupplierCreditBalance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Ruling P5 (19 Oct 2026): a supplier paying back what they owe us on
 * Accounts Payable. Recorded and reversed from the supplier's Advances page,
 * beside the advance refunds, by whoever may record supplier advances
 * (`purchasing.advance.record`: Owner, Manager, Finance; not the Accountant).
 */
class SupplierRefundController extends Controller
{
    use ReversesDocuments;

    public function store(Request $request, RecordSupplierRefund $action): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('business_id', $businessId)],
            'money_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')->where('business_id', $businessId)],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
        ], [
            'received_on.before_or_equal' => 'A refund cannot be dated in the future.',
        ]);

        try {
            $refund = $action->handle(
                supplier: Supplier::findOrFail($data['supplier_id']),
                moneyAccount: MoneyAccount::findOrFail($data['money_account_id']),
                receivedOn: $data['received_on'],
                amount: (string) $data['amount'],
                by: $request->user(),
                reference: $data['reference'] ?? null,
                note: $data['note'] ?? null,
            );
        } catch (PurchaseApprovalRequired $e) {
            // ⭐ Purchasing approvals (ruling P7): nothing posted; saved as pending.
            $approval = app(PurchasingApprovalGate::class)->hold($e, $request->user());

            return redirect()->route('purchasing.approvals.show', $approval)
                ->with('success', "This refund needs a second person's approval, so it is saved as {$approval->number()} and posts when they approve.");
        } catch (PeriodClosedException|MappingNotFound $e) {
            throw ValidationException::withMessages(['received_on' => $e->getMessage()]);
        }

        return back()->with('success', "Refund {$refund->number} recorded.");
    }

    public function reverse(Request $request, SupplierRefund $refund, ReverseSupplierRefund $action): RedirectResponse
    {
        return $this->reverseFromForm(
            $request,
            fn (string $reason, bool $onOriginalDate) => $action->handle($refund, $request->user(), $reason, $onOriginalDate),
            "Refund {$refund->number} reversed.",
        );
    }

    /**
     * What the supplier page shows: the balance they owe us back and the
     * refunds so far.
     *
     * @return array<string, mixed>
     */
    public static function sectionFor(Supplier $supplier): array
    {
        return [
            'balance' => SupplierCreditBalance::of($supplier)->toTaka(),
            'refunds' => SupplierRefund::query()->where('supplier_id', $supplier->id)
                ->with(['moneyAccount:id,name', 'reversedBy:id,name', 'reversalJournal:id,journal_number'])
                ->orderByDesc('received_on')->orderByDesc('id')->limit(100)->get()
                ->map(fn (SupplierRefund $refund) => [
                    'id' => $refund->id,
                    'number' => $refund->number,
                    'received_on' => $refund->received_on?->toDateString(),
                    'amount' => $refund->money()->toTaka(),
                    'money_account' => $refund->moneyAccount?->name,
                    'reference' => $refund->reference,
                    'is_reversed' => $refund->isReversed(),
                    'reversal' => $refund->reversalSummary(),
                ])->values(),
        ];
    }
}
