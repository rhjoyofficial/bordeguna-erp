<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * ⭐ 27 Sep 2026 (Joy): a supplier created from INSIDE the purchase-order (or any
 * supplier picker), so a missing supplier no longer sends you off to another
 * page and back. Modelled on QuickAccountController: JSON in and out, so the
 * order being typed underneath is never lost.
 *
 * The full supplier screen wants a unique code; here the name is enough and the
 * code is generated from it, because "instant" is the whole point. The person
 * fills in the rest (BIN, bank, terms) later on the supplier profile. Same
 * permission as managing suppliers (`purchasing.suppliers.manage`), enforced on
 * the route and here.
 *
 * ⚠ The EntityLedgerObserver gives the new supplier its own ledger head on
 * create, exactly as the full screen does — nothing special is needed here.
 */
class SupplierQuickCreateController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $this->authorize('purchasing.suppliers.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            // Optional: if the person gives a code we honour it (and its
            // uniqueness), otherwise one is made from the name.
            'code' => [
                'nullable', 'string', 'max:50',
                Rule::unique('suppliers', 'code')->where('business_id', app(BusinessContext::class)->id()),
            ],
        ]);

        $code = trim((string) ($data['code'] ?? '')) !== ''
            ? $data['code']
            : $this->generateCode($data['name']);

        $supplier = Supplier::create([
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'code' => $code,
            'is_active' => true,
        ]);

        return response()->json([
            'id' => $supplier->id,
            'label' => "{$supplier->name} ({$supplier->code})",
        ], 201);
    }

    /**
     * A short, unique code from the name: the first letters/digits uppercased,
     * with a numeric suffix if that is already taken in this business. Never
     * collides, so the quick path never fails on the code the way the full
     * screen would if you typed a duplicate.
     */
    private function generateCode(string $name): string
    {
        $businessId = app(BusinessContext::class)->id();
        $base = strtoupper(Str::of($name)->ascii()->replaceMatches('/[^A-Za-z0-9]/', '')->substr(0, 8)->value());

        if ($base === '') {
            $base = 'SUP';
        }

        $candidate = $base;
        $suffix = 1;

        while (Supplier::withoutGlobalScopes()
            ->where('business_id', $businessId)
            ->where('code', $candidate)
            ->exists()) {
            $suffix++;
            $candidate = Str::of($base)->substr(0, 7)->append((string) $suffix)->value();
        }

        return $candidate;
    }
}
