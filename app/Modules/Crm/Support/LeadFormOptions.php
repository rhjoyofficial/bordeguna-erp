<?php

namespace App\Modules\Crm\Support;

use App\Models\User;
use App\Modules\Crm\Actions\ConvertLead;
use App\Modules\Crm\Enums\CrmLookupList;
use App\Modules\Crm\Enums\LeadImportance;
use App\Modules\Crm\Enums\LeadPotential;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Enums\LeadType;
use App\Modules\Crm\Enums\WarehouseOwnership;
use App\Modules\Crm\Models\Campaign;
use App\Modules\Crm\Models\CrmLookup;
use App\Modules\Crm\Models\LeadSource;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use App\Support\BdGeo;
use Illuminate\Support\Collection;

/**
 * Everything the add, edit and convert popups of a lead pick from, for one
 * reader. Moved out of LeadController::index() in round 3 (16 Oct 2026) so
 * the lead page opens the very same popups as the lead list.
 */
final class LeadFormOptions
{
    /**
     * @return array<string, mixed>
     */
    public static function for(User $user, int $businessId): array
    {
        return [
            // ⚠⚠ C-CRM-9: the FORM's list excludes `converted`. It asserts a
            // customer exists and is set only by the Phase 3 conversion action.
            'selectable_statuses' => collect(LeadStatus::selectable())
                ->map(fn (LeadStatus $s) => ['value' => $s->value, 'label' => $s->label()])->values(),
            'brands' => Brand::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'sources' => LeadSource::active()->orderBy('name')->get(['id', 'name', 'code']),
            'campaigns' => Campaign::active()->orderBy('name')->get(['id', 'name', 'code', 'lead_source_id']),
            'lost_reasons' => Reason::forContext('lead_lost')->active()->orderBy('name')->get(['id', 'name']),
            'agents' => self::agents($businessId, $user),
            'can_assign' => $user->can('crm.leads.assign'),
            'lead_types' => collect(LeadType::selectable())
                ->map(fn (LeadType $type) => ['value' => $type->value, 'label' => $type->label(), 'is_shop' => $type->isShop()])
                ->values(),
            // ⚠ The dataset's own spellings, so the filter and the form agree
            // with what validation will accept.
            'districts' => collect(BdGeo::districts())->pluck('name')->values(),
            'potentials' => collect(LeadPotential::cases())->map(fn (LeadPotential $p) => ['value' => $p->value, 'label' => $p->label()])->values(),
            'importances' => collect(LeadImportance::cases())->map(fn (LeadImportance $i) => ['value' => $i->value, 'label' => $i->label()])->values(),
            'warehouse_ownerships' => collect(WarehouseOwnership::cases())->map(fn (WarehouseOwnership $w) => ['value' => $w->value, 'label' => $w->label()])->values(),
            // ⚠ Retired entries are sent too, flagged, so a lead that still holds
            // one shows it on edit instead of an empty picker; the form offers
            // only active ones for a new choice.
            'lookups' => collect(CrmLookupList::cases())->mapWithKeys(fn (CrmLookupList $list) => [
                $list->value => CrmLookup::query()->ofList($list)->ordered()->get(['id', 'name', 'is_active']),
            ]),
            // ⚠⚠ Without this prop the convert endpoint had NO caller. The route,
            // the action, the permission and seventeen tests all shipped on
            // 9 August; the screen was never brought forward, so no lead in this
            // system could become a customer. `ConvertLead` is the only writer of
            // `converted_customer_id`, so the funnel simply had no exit.
            'can_convert' => $user->can('crm.leads.convert'),
            // ⭐ Plan D12: a supplier is a Purchasing record with bank details, so
            // converting into one also needs purchasing.suppliers.manage.
            'can_convert_supplier' => self::mayConvertSuppliers($user),
            // ⚠ Only to someone who may convert suppliers: the supplier list is
            // not an officer's business.
            'suppliers' => self::mayConvertSuppliers($user)
                ? Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'phone'])
                : [],
            // ⚠ Duplicate linking needs it since 15 Sep 2026; without the prop
            // an officer would see a button the route refuses.
            'can_verify' => $user->can('crm.leads.verify'),
            // ⚠ System and inactive customers are excluded because `ConvertLead`
            // REFUSES both - offering a choice the action will reject would be a
            // form that fails on submit for reasons the user cannot see.
            'customers' => Customer::where('is_system', false)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'phone']),
            // ⚠⚠ Straight from the enum, never typed by hand. A case mismatch
            // here - `Distributor` against `distributor` - once charged a
            // distributor the retail price without anyone noticing.
            'customer_types' => collect(CustomerType::cases())
                ->map(fn (CustomerType $type) => ['value' => $type->value, 'label' => $type->label()])
                ->values(),
            // ⭐ Plan D6: the conversion form preselects the group whose code
            // matches the type (DISTRIBUTOR for a distributor lead). Active only:
            // a retired group is not a choice for a new customer.
            'price_groups' => PriceGroup::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            // Plan D7: which customer types may carry credit, from the one list.
            'credit_types' => collect(ConvertLead::CREDIT_TYPES)->map(fn (CustomerType $type) => $type->value)->values(),
        ];
    }

    /** A supplier is a Purchasing record with bank details (plan D12). */
    public static function mayConvertSuppliers(?User $user): bool
    {
        return $user !== null && $user->can('crm.leads.convert') && $user->can('purchasing.suppliers.manage');
    }

    /**
     * The people this reader may hand a lead to, or filter the list by.
     *
     * ⭐ Everybody in the business for somebody who sees every lead; otherwise
     * themself and their team (15 Sep 2026). Two reasons, one rule: an ASM who
     * hands a lead to somebody outside his team would watch it vanish from his
     * own list, and an officer's filter listing every login in the business is a
     * staff directory he has no use for.
     *
     * @return Collection<int, array{id:int, name:string}>
     */
    public static function agents(int $businessId, User $reader): Collection
    {
        $visibility = app(LeadVisibility::class);

        return User::whereHas('businesses', fn ($q) => $q->where('businesses.id', $businessId))
            ->when(! $visibility->seesEverything($reader), fn ($q) => $q->whereIn('users.id', $visibility->userIdsFor($reader)))
            ->orderBy('name')
            ->get(['users.id', 'users.name'])
            ->map(fn ($user) => ['id' => $user->id, 'name' => $user->name]);
    }
}
