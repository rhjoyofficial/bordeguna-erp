<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Support\PhoneIdentity;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Support\CustomerSavedPlaces;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ⭐ Joy, 26 Oct 2026: "while sell or others things, if i enter number or name
 * matches will auto fill the necessary fields." One JSON lookup for every
 * selling screen (order, counter, quotation, invoice, sample):
 *
 *  - `?phone=` a whole number, typed any way (+8801..., 01..., 1711...):
 *    the customer whose matching phone is exactly that. ONE such customer is
 *    `match`, and the screen selects them; two or more (a household, a shop
 *    and its owner share a number, see PhoneIdentity) come back as `matches`
 *    and the person picks, never the screen.
 *  - `?q=` part of a name (or of a phone): up to 8 `matches` to pick from.
 *    ⚠ A name alone never selects anybody: two Rahims are two people.
 *  - `?id=` one customer by id, so a customer picked from the ordinary list
 *    shows the same group, branch and saved place as one found here.
 *
 * ⚠ Only the customers the screens' own lists hold: this business (the global
 * scope), active, never the system walk-in row, and staff purchase accounts
 * only for whoever Customer::staffVisibleTo lets see them. A lookup that found
 * more than the picker lists would be a back door into the hidden ones.
 *
 * ⚠ Any of the selling permissions opens it, because each screen it serves
 * asks a different one: the counter and invoices sales.sell, orders and
 * quotations sales.orders.record, samples sales.samples.manage.
 */
class CustomerLookupController extends Controller
{
    public const PERMISSIONS = ['sales.sell', 'sales.orders.record', 'sales.samples.manage'];

    private const LIMIT = 8;

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()?->canAny(self::PERMISSIONS), 403);

        $request->validate([
            'phone' => ['nullable', 'string', 'max:40'],
            'q' => ['nullable', 'string', 'max:100'],
            'id' => ['nullable', 'integer'],
        ]);

        if ($request->filled('id')) {
            $found = $this->pickable()->whereKey((int) $request->integer('id'))->get();

            return response()->json(['match' => $this->rows($found)->first(), 'matches' => []]);
        }

        if ($request->filled('phone')) {
            $key = PhoneIdentity::normalize($request->string('phone')->toString());

            // A whole Bangladeshi mobile only: half a number is a search, not a person.
            if ($key === null || strlen($key) < 11) {
                return response()->json(['match' => null, 'matches' => []]);
            }

            $found = $this->pickable()->where('phone_normalized', $key)->orderBy('name')->limit(self::LIMIT)->get();
            $rows = $this->rows($found);

            return response()->json($rows->count() === 1
                ? ['match' => $rows->first(), 'matches' => []]
                : ['match' => null, 'matches' => $rows->all()]);
        }

        $typed = trim($request->string('q')->toString());

        if (mb_strlen($typed) < 2) {
            return response()->json(['match' => null, 'matches' => []]);
        }

        $query = $this->pickable();
        $digits = preg_replace('/\D+/', '', $typed) ?? '';

        if (preg_match('/^[+\d\s-]+$/', $typed) === 1 && $digits !== '') {
            // Part of a phone: the matching form drops +880, so drop it here too.
            $query->where('phone_normalized', 'like', '%'.PhoneIdentity::normalize($digits).'%');
        } else {
            // ⚠ No LIKE escaping: SQLite and MySQL disagree on the escape
            // character, and a % in a customer's name is not worth the split.
            $like = mb_strtolower($typed);
            $query->where(fn (Builder $where) => $where
                ->whereRaw('LOWER(name) LIKE ?', ['%'.$like.'%'])
                ->orWhereRaw('LOWER(code) = ?', [mb_strtolower($typed)]));
            // A name that STARTS with what was typed leads.
            $query->orderByRaw('CASE WHEN LOWER(name) LIKE ? THEN 0 ELSE 1 END', [$like.'%']);
        }

        $found = $query->orderBy('name')->limit(self::LIMIT)->get();

        return response()->json(['match' => null, 'matches' => $this->rows($found)->all()]);
    }

    /** The customers a selling screen may pick: the same rule as its list. */
    private function pickable(): Builder
    {
        return Customer::query()
            ->listable()
            ->active()
            ->where('is_system', false)
            ->with(['priceGroup:id,name', 'priceGroupBranch:id,name']);
    }

    /**
     * @param  Collection<int, Customer>  $customers
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function rows(Collection $customers): \Illuminate\Support\Collection
    {
        $places = CustomerSavedPlaces::for($customers->modelKeys());

        return $customers->map(fn (Customer $customer) => [
            'id' => $customer->id,
            'code' => $customer->code,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'type' => $customer->type->value,
            'type_label' => $customer->type->label(),
            'address' => $customer->address,
            'price_group_id' => $customer->price_group_id,
            'price_group' => $customer->priceGroup?->name,
            'branch' => $customer->priceGroupBranch?->name,
            'saved_place' => $places->get($customer->id),
        ])->values();
    }
}
