<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Actions\UndoChequeStatus;
use App\Modules\Accounting\Actions\UpdateChequeStatus;
use App\Modules\Accounting\Enums\VoucherStatus;
use App\Modules\Accounting\Models\ChequeStatus;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Foundation\Corrections\Owners;
use App\Modules\Foundation\Corrections\ValidatesActionReason;
use App\Modules\Purchasing\Support\CorrectionScreen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance & Accounts > Cheques (finance decision C5): every cheque written on
 * or received by a posted voucher, and whether it has cleared.
 */
class ChequeRegisterController extends Controller
{
    use ValidatesActionReason;

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'direction' => ['nullable', Rule::in(['issued', 'received'])],
            'status' => ['nullable', Rule::in(array_keys(ChequeStatus::STATUSES))],
        ]);

        // ⭐ Corrections wave 2: an undone status reads as pending again.
        $statuses = ChequeStatus::query()->current()->with('updatedBy:id,name')->get()->keyBy('voucher_id');
        $owner = Owners::is($request->user());

        $rows = Voucher::query()
            ->where('instrument_type', 'cheque')
            ->whereIn('status', [VoucherStatus::Posted->value, VoucherStatus::Reversed->value])
            ->when($filters['direction'] ?? null, fn ($q, $d) => $q->where('type', $d === 'issued' ? 'payment' : 'receipt'))
            ->with('moneyAccount:id,name')
            ->orderByDesc('voucher_date')->orderByDesc('id')
            ->get()
            ->map(function (Voucher $v) use ($statuses, $owner) {
                $status = $statuses->get($v->id);

                return [
                    'voucher_id' => $v->id,
                    'voucher' => $v->voucher_number,
                    'direction' => $v->type->value === 'receipt' ? 'received' : 'issued',
                    'number' => $v->instrument_number,
                    'cheque_date' => $v->instrument_date?->toDateString(),
                    'bank' => $v->instrument_bank,
                    'account' => $v->moneyAccount?->name,
                    'party' => $v->counterparty,
                    'amount' => $v->total()->format(),
                    'status' => $status?->status ?? ($v->status === VoucherStatus::Reversed ? 'cancelled' : 'pending'),
                    'status_on' => $status?->status_on?->toDateString(),
                    'note' => $status?->note ?? ($status === null && $v->status === VoucherStatus::Reversed ? 'Voucher reversed' : null),
                    'can_update' => $status === null && $v->status === VoucherStatus::Posted,
                    // Owner only (FINAL-PLAN 3.1): Undo status on a cleared, bounced or cancelled cheque.
                    'can_undo' => $owner && $status !== null,
                ];
            })
            ->when($filters['status'] ?? null, fn ($c, $s) => $c->where('status', $s))
            ->values();

        return Inertia::render('accounting/cheques/index', [
            'cheques' => $rows,
            'filters' => ['direction' => $filters['direction'] ?? '', 'status' => $filters['status'] ?? ''],
            'statuses' => ChequeStatus::STATUSES,
            'canUpdate' => $request->user()->can('accounting.voucher.post'),
            'today' => now()->toDateString(),
            'undoReasons' => CorrectionScreen::reasons('reversal'),
        ]);
    }

    public function update(Request $request, Voucher $voucher, UpdateChequeStatus $action): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['cleared', 'bounced', 'cancelled'])],
            'status_on' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $action->handle($voucher, $data['status'], $data['status_on'], $data['note'] ?? null, $request->user());

        return back()->with('success', $data['status'] === 'cleared' ? 'Cheque marked cleared.' : "Cheque marked {$data['status']} and its voucher reversed.");
    }

    /**
     * ⭐ Corrections wave 2 (24 Oct 2026): Undo status, Owner only (the action
     * checks). A bounced or cancelled cheque's payment is re-posted properly.
     */
    public function undo(Request $request, Voucher $voucher, UndoChequeStatus $action): RedirectResponse
    {
        $request->validate($this->actionReasonRules('reversal'), $this->actionReasonMessages());

        $copy = $action->handle($voucher, $this->actionReason($request), $request->user());

        return back()->with('success', $copy === null
            ? 'Cheque status undone. It is pending again.'
            : "Cheque status undone. The payment is posted again as {$copy->voucher_number}; its cheque is pending.");
    }
}
