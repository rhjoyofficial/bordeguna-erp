<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Sales\Actions\BookConsignment;
use App\Modules\Sales\Actions\RecordConsignmentOutcome;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Exceptions\NoSellingPriceException;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\Delivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Parcels handed to couriers: booking one against a challan, recording what the
 * courier reported, and the list of what is still out.
 *
 * ⚠ The list exists so the courier's answers have somewhere to be entered from.
 * A parcel out for three days is found here, not by opening orders one by one.
 */
class ConsignmentController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->toString(),
            'status' => $request->string('status')->toString() ?: ConsignmentStatus::Booked->value,
        ];

        $consignments = Consignment::query()
            ->with(['courier:id,name', 'delivery:id,delivery_number,delivered_at,customer_id', 'delivery.customer:id,name', 'delivery.lines.orderLine:id,sales_order_id', 'invoice:id,invoice_number'])
            ->when($filters['status'] !== 'all', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['search'], fn ($query, $search) => $query->where(
                fn ($q) => $q->where('consignment_number', 'like', "%{$search}%")
                    ->orWhereHas('delivery', fn ($d) => $d->where('delivery_number', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")))
            ))
            // The oldest parcel still out is the one somebody should be chasing.
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Consignment $consignment) => [
                'id' => $consignment->id,
                'courier' => $consignment->courier?->name,
                'consignment_number' => $consignment->consignment_number,
                'delivery_number' => $consignment->delivery->delivery_number,
                'sent_on' => $consignment->delivery->delivered_at->toDateString(),
                'customer' => $consignment->delivery->customer?->name,
                'order_id' => $consignment->delivery->lines->first()?->orderLine?->sales_order_id,
                'cod_amount' => $consignment->codAmount()->toTaka(),
                'status' => $consignment->status->value,
                'status_label' => $consignment->status->label(),
                'outcome_on' => $consignment->outcome_on?->toDateString(),
                'collected_amount' => $consignment->collectedAmount()?->toTaka(),
                'invoice' => $consignment->invoice?->only(['id', 'invoice_number']),
            ]);

        return Inertia::render('sales/consignments/index', [
            'consignments' => $consignments,
            'filters' => $filters,
            'statuses' => collect(ConsignmentStatus::cases())->map(fn (ConsignmentStatus $status) => [
                'value' => $status->value,
                'label' => $status->label(),
            ]),
        ]);
    }

    public function store(Request $request, Delivery $delivery, BookConsignment $action): RedirectResponse
    {
        $this->authorize('sales.sell');

        $data = $request->validate([
            'money_account_id' => ['required', 'integer', 'exists:money_accounts,id'],
            'consignment_number' => ['required', 'string', 'max:100'],
            'cod_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $consignment = $action->handle(
            delivery: $delivery,
            courier: MoneyAccount::query()->findOrFail($data['money_account_id']),
            consignmentNumber: $data['consignment_number'],
            codAmount: (string) $data['cod_amount'],
            createdBy: $request->user(),
        );

        return back()->with('success', "Challan {$delivery->delivery_number} handed to {$consignment->courier->name} as {$consignment->consignment_number}.");
    }

    public function outcome(Request $request, Consignment $consignment, RecordConsignmentOutcome $action): RedirectResponse
    {
        $this->authorize('sales.sell');

        $data = $request->validate([
            'outcome' => ['required', Rule::in(array_map(fn (ConsignmentStatus $status) => $status->value, ConsignmentStatus::outcomes()))],
            'outcome_on' => ['required', 'date'],
            'collected_amount' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['nullable', 'array'],
            'lines.*.delivery_line_id' => ['required', 'integer'],
            'lines.*.taken' => ['nullable', 'numeric', 'min:0'],
            'lines.*.written_off' => ['nullable', 'numeric', 'min:0'],
        ]);

        $outcome = ConsignmentStatus::from($data['outcome']);
        $lines = collect($data['lines'] ?? [])->keyBy('delivery_line_id')
            ->map(fn (array $line) => [
                'taken' => (string) ($line['taken'] ?? '0'),
                'written_off' => (string) ($line['written_off'] ?? '0'),
            ])
            ->all();

        // ⚠ The outcome writes the documents those permissions guard, so it asks
        // for them: money collected is a receipt, goods back are a return.
        if ((float) $data['collected_amount'] > 0) {
            $this->authorize('sales.receipt.record');
        }

        if ($outcome !== ConsignmentStatus::Delivered) {
            $this->authorize('sales.returns.record');
        }

        try {
            $action->handle(
                consignment: $consignment,
                outcome: $outcome,
                on: $data['outcome_on'],
                collectedAmount: (string) $data['collected_amount'],
                lines: $lines,
                recordedBy: $request->user(),
                note: $data['note'] ?? null,
            );
        } catch (PeriodClosedException|MappingNotFound|NoSellingPriceException|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['outcome_on' => $e->getMessage()]);
        }

        return back()->with('success', "{$consignment->consignment_number} recorded as {$outcome->label()}.");
    }
}
