<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Actions\UpdateChequeStatus;
use App\Modules\Accounting\Enums\VoucherStatus;
use App\Modules\Accounting\Models\ChequeStatus;
use App\Modules\Accounting\Models\Voucher;
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
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'direction' => ['nullable', Rule::in(['issued', 'received'])],
            'status' => ['nullable', Rule::in(array_keys(ChequeStatus::STATUSES))],
        ]);

        $statuses = ChequeStatus::query()->with('updatedBy:id,name')->get()->keyBy('voucher_id');

        $rows = Voucher::query()
            ->where('instrument_type', 'cheque')
            ->whereIn('status', [VoucherStatus::Posted->value, VoucherStatus::Reversed->value])
            ->when($filters['direction'] ?? null, fn ($q, $d) => $q->where('type', $d === 'issued' ? 'payment' : 'receipt'))
            ->with('moneyAccount:id,name')
            ->orderByDesc('voucher_date')->orderByDesc('id')
            ->get()
            ->map(function (Voucher $v) use ($statuses) {
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
}
