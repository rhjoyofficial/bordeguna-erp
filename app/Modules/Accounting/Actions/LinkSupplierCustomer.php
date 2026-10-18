<?php

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Models\Counterparty;
use App\Modules\Accounting\Models\SetOff;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ "Some of our suppliers are also our customers" (Joy, 18 Oct 2026, D-F):
 * one firm, two roles, two ledgers, one link.
 *
 *   link()             an existing supplier and an existing customer
 *   unlink()           only while no set-off is waiting or live between them
 *   customerFor()      creates the customer side from a supplier (name,
 *                      phone, address, contact, email copied) and links it
 *   supplierFor()      the mirror
 *
 * ⚠⚠ LINKING NEVER MERGES LEDGERS. AP 2110 stays per supplier and AR 1140 per
 * customer; nothing moves between them except an approved set-off.
 *
 * ⚠ ONE IDENTITY (D-F: "do not create a second identity system"). Where a
 * lender counterparty already names the supplier or the customer, the link
 * must agree with it: a counterparty holding this supplier with ANOTHER
 * customer (or the other way round) is refused, with its name.
 *
 * ⚠ Same business only: both rows are found through the tenant scope, and a
 * staff purchase account or the walk-in row is never a trading partner.
 */
class LinkSupplierCustomer
{
    public function link(Supplier $supplier, Customer $customer, User $by): Customer
    {
        if ((int) $supplier->business_id !== (int) $customer->business_id) {
            throw ValidationException::withMessages(['customer_id' => 'The supplier and the customer belong to different businesses, so they cannot be one party.']);
        }

        if ($customer->isStaff() || $customer->isWalkIn()) {
            throw ValidationException::withMessages(['customer_id' => "{$customer->name} is a staff or walk-in account and cannot be linked to a supplier."]);
        }

        return DB::transaction(function () use ($supplier, $customer, $by) {
            $customer = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();

            if ($customer->supplier_id !== null && (int) $customer->supplier_id !== (int) $supplier->id) {
                throw ValidationException::withMessages(['customer_id' => "{$customer->name} is already linked to another supplier. Unlink it there first."]);
            }

            $taken = Customer::query()->where('supplier_id', $supplier->id)->whereKeyNot($customer->id)->first();

            if ($taken !== null) {
                throw ValidationException::withMessages(['supplier_id' => "{$supplier->name} is already linked to the customer {$taken->name}. Unlink it there first."]);
            }

            $this->assertCounterpartiesAgree($supplier, $customer);

            if ((int) $customer->supplier_id === (int) $supplier->id) {
                return $customer;
            }

            $customer->forceFill(['supplier_id' => $supplier->id])->save();

            activity()->performedOn($customer)->causedBy($by)
                ->withProperties(['supplier_id' => $supplier->id])
                ->log("Linked customer {$customer->name} to supplier {$supplier->name} (same business party)");

            return $customer;
        });
    }

    public function unlink(Customer $customer, User $by): void
    {
        DB::transaction(function () use ($customer, $by) {
            $customer = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();

            if ($customer->supplier_id === null) {
                return;
            }

            // ⚠ A set-off waiting or live between them is the reason the link
            // exists; unlinking under it would orphan the document's party.
            $live = SetOff::query()->where('customer_id', $customer->id)->where('supplier_id', $customer->supplier_id)
                ->whereIn('status', [SetOff::PENDING, SetOff::POSTED])->whereNull('reversed_at')->value('number');

            if ($live !== null) {
                throw ValidationException::withMessages(['customer_id' => "Set-off {$live} stands between these two. Reverse it (or have it rejected) before unlinking."]);
            }

            $supplierId = $customer->supplier_id;
            $customer->forceFill(['supplier_id' => null])->save();

            activity()->performedOn($customer)->causedBy($by)
                ->withProperties(['supplier_id' => $supplierId])
                ->log("Unlinked customer {$customer->name} from its supplier");
        });
    }

    /** The customer side, made from the supplier's own details, and linked. */
    public function customerFor(Supplier $supplier, User $by): Customer
    {
        return DB::transaction(function () use ($supplier, $by) {
            if (($existing = Customer::query()->where('supplier_id', $supplier->id)->first()) !== null) {
                throw ValidationException::withMessages(['supplier_id' => "{$supplier->name} is already our customer {$existing->name}."]);
            }

            // ⚠ Corporate, not Retail: a supplier is a firm, and Retail would
            // price it at MRP. The type can be changed on the customer page.
            $customer = Customer::create([
                'business_id' => $supplier->business_id,
                'name' => $supplier->name,
                'type' => CustomerType::Corporate->value,
                'contact_name' => $supplier->contact_name,
                'phone' => $supplier->phone,
                'email' => $supplier->email,
                'address' => $supplier->address,
                'is_active' => true,
            ]);

            return $this->link($supplier, $customer, $by);
        });
    }

    /** The supplier side, made from the customer's own details, and linked. */
    public function supplierFor(Customer $customer, User $by): Supplier
    {
        return DB::transaction(function () use ($customer, $by) {
            if ($customer->supplier_id !== null) {
                throw ValidationException::withMessages(['customer_id' => "{$customer->name} is already linked to a supplier."]);
            }

            if ($customer->isStaff() || $customer->isWalkIn()) {
                throw ValidationException::withMessages(['customer_id' => "{$customer->name} is a staff or walk-in account and cannot be a supplier."]);
            }

            $supplier = Supplier::create([
                'business_id' => $customer->business_id,
                'code' => $this->supplierCode((int) $customer->business_id, $customer->name),
                'name' => $customer->name,
                'contact_name' => $customer->contact_name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'address' => $customer->address,
                'is_active' => true,
            ]);

            $this->link($supplier, $customer, $by);

            return $supplier;
        });
    }

    /**
     * ⚠ One identity: a lender counterparty that already names one side must
     * not name a DIFFERENT other side.
     */
    private function assertCounterpartiesAgree(Supplier $supplier, Customer $customer): void
    {
        $bySupplier = Counterparty::query()->where('supplier_id', $supplier->id)->first();
        $byCustomer = Counterparty::query()->where('customer_id', $customer->id)->first();

        foreach ([[$bySupplier, 'customer_id', $customer->id], [$byCustomer, 'supplier_id', $supplier->id]] as [$party, $column, $expected]) {
            if ($party !== null && $party->{$column} !== null && (int) $party->{$column} !== (int) $expected) {
                throw ValidationException::withMessages(['customer_id' => "The counterparty {$party->legal_name} already pairs this ".($column === 'customer_id' ? 'supplier with another customer' : 'customer with another supplier').'. One firm has one identity: fix the counterparty first.']);
            }
        }
    }

    /** The supplier quick-create rule: letters and digits of the name, made unique. */
    private function supplierCode(int $businessId, string $name): string
    {
        $base = strtoupper(Str::of($name)->ascii()->replaceMatches('/[^A-Za-z0-9]/', '')->substr(0, 8)->value()) ?: 'SUP';
        $candidate = $base;
        $suffix = 1;

        while (Supplier::withoutGlobalScopes()->where('business_id', $businessId)->where('code', $candidate)->exists()) {
            $suffix++;
            $candidate = Str::of($base)->substr(0, 7)->append((string) $suffix)->value();
        }

        return $candidate;
    }
}
