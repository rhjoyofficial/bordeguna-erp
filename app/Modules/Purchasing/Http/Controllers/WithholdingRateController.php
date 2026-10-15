<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Models\WithholdingRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Purchases > Payments > Tax rates (finance decision C4): the owner's own list
 * of TDS and VDS rates, dated, per supplier type.
 */
class WithholdingRateController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('purchasing/withholding-rates/index', [
            // PUR-007: the route already needs purchasing.suppliers.manage; the
            // page checks the same flag again before offering add, edit, delete.
            'canManageRates' => (bool) $request->user()?->can('purchasing.suppliers.manage'),
            'rates' => WithholdingRate::query()->orderBy('kind')->orderBy('supplier_type')->orderByDesc('effective_from')->get()
                ->map(fn (WithholdingRate $r) => [
                    'id' => $r->id,
                    'kind' => $r->kind,
                    'supplier_type' => $r->supplier_type,
                    'name' => $r->name,
                    'rate' => rtrim(rtrim((string) $r->rate, '0'), '.'),
                    'effective_from' => $r->effective_from?->toDateString(),
                    'effective_to' => $r->effective_to?->toDateString(),
                    'used' => SupplierPayment::query()->where('tds_rate_id', $r->id)->orWhere('vds_rate_id', $r->id)->exists(),
                ]),
            'kinds' => WithholdingRate::KINDS,
            'supplierTypes' => Supplier::TYPES,
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        WithholdingRate::create($this->validated($request));

        return back()->with('success', 'Rate added.');
    }

    public function update(Request $request, WithholdingRate $rate): RedirectResponse
    {
        $data = $this->validated($request);

        // ⚠ A rate a payment was made under keeps its percentage: change the
        // end date and add a new row instead, so the old payment still reads true.
        if ($this->isUsed($rate) && (float) $data['rate'] !== (float) $rate->rate) {
            throw ValidationException::withMessages(['rate' => 'Payments were made at this rate. End it and add a new rate instead.']);
        }

        $rate->update($data);

        return back()->with('success', 'Rate updated.');
    }

    public function destroy(WithholdingRate $rate): RedirectResponse
    {
        if ($this->isUsed($rate)) {
            throw ValidationException::withMessages(['rate' => 'Payments were made at this rate, so it cannot be deleted. Give it an end date.']);
        }

        $rate->delete();

        return back()->with('success', 'Rate deleted.');
    }

    private function isUsed(WithholdingRate $rate): bool
    {
        return SupplierPayment::query()->where('tds_rate_id', $rate->id)->orWhere('vds_rate_id', $rate->id)->exists();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'kind' => ['required', Rule::in(array_keys(WithholdingRate::KINDS))],
            'supplier_type' => ['nullable', Rule::in(array_keys(Supplier::TYPES))],
            'name' => ['required', 'string', 'max:255'],
            'rate' => ['required', 'numeric', 'gt:0', 'max:100'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);
    }
}
