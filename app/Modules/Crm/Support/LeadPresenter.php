<?php

namespace App\Modules\Crm\Support;

use App\Modules\Crm\Enums\ActivityType;
use App\Modules\Crm\Enums\LeadType;
use App\Modules\Crm\Enums\TaskStatus;
use App\Modules\Crm\Models\Lead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One lead as the screens read it: the lead list's row, the lead page's head
 * and the edit popup all take this shape (CRM redesign round 3, 16 Oct 2026).
 * Moved out of LeadController, which kept it private, so the lead page can
 * open the same edit and convert popups with the same data.
 */
final class LeadPresenter
{
    /** The relations the row reads; load them with the query, never per row. */
    public const RELATIONS = [
        'brand:id,name,code', 'assignedTo:id,name', 'convertedCustomer:id,name,code', 'convertedSupplier:id,name,code', 'duplicateOf:id,name',
        'verifiedBy:id,name', 'createdBy:id,name',
        'retailerProfile.shopType:id,name', 'distributorProfile', 'supplierProfile.supplierType:id,name',
        'competitorBrands:crm_lookups.id,crm_lookups.name', 'coverageAreas:id,lead_id,district,thana',
    ];

    /**
     * Adds the columns the row shows besides the lead's own: the pinned map
     * location (plan D14), the next open follow-up and the last thing recorded.
     * Correlated subqueries, so a page of twenty leads stays one query.
     *
     * ⚠ Unscoped on purpose: every subquery is tied to a lead this reader can
     * already see, so a visibility scope would only add a second copy of the
     * same condition.
     *
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    public static function withListColumns(Builder $query): Builder
    {
        $morph = (new Lead)->getMorphClass();

        $openTask = fn (string $column) => DB::table('crm_tasks')
            ->select($column)
            ->whereColumn('crm_tasks.subject_id', 'leads.id')
            ->where('crm_tasks.subject_type', $morph)
            ->where('crm_tasks.status', TaskStatus::Open->value)
            ->orderBy('crm_tasks.due_at')
            ->orderBy('crm_tasks.id')
            ->limit(1);

        $lastActivity = fn (string $column) => DB::table('crm_activities')
            ->select($column)
            ->whereColumn('crm_activities.subject_id', 'leads.id')
            ->where('crm_activities.subject_type', $morph)
            ->orderByDesc('crm_activities.occurred_at')
            ->orderByDesc('crm_activities.id')
            ->limit(1);

        return $query->addSelect([
            'pin_latitude' => self::firstLocatedVisit('latitude'),
            'pin_longitude' => self::firstLocatedVisit('longitude'),
            'next_follow_up_at' => $openTask('due_at'),
            'next_follow_up_title' => $openTask('title'),
            'last_contact_at' => $lastActivity('occurred_at'),
            'last_contact_type' => $lastActivity('type'),
        ]);
    }

    /**
     * One coordinate of a lead's first visit that carried GPS (plan D14).
     *
     * ⚠ Mirrors CrmActivity::scopeLocatedVisitsOf: a visit, both coordinates,
     * earliest by when it HAPPENED.
     */
    private static function firstLocatedVisit(string $column): QueryBuilder
    {
        return DB::table('crm_activities')
            ->select($column)
            ->whereColumn('crm_activities.subject_id', 'leads.id')
            ->where('crm_activities.subject_type', (new Lead)->getMorphClass())
            ->where('crm_activities.type', ActivityType::Visit->value)
            ->whereNotNull('crm_activities.latitude')
            ->whereNotNull('crm_activities.longitude')
            ->orderBy('crm_activities.occurred_at')
            ->orderBy('crm_activities.id')
            ->limit(1);
    }

    /**
     * The row. Reads the list columns when the query added them
     * (withListColumns); without them the follow-up and contact facts are
     * simply absent.
     *
     * @return array<string, mixed>
     */
    public static function row(Lead $lead): array
    {
        $now = Carbon::now();
        $nextAt = $lead->getAttribute('next_follow_up_at') !== null ? Carbon::parse($lead->getAttribute('next_follow_up_at')) : null;
        $lastAt = $lead->getAttribute('last_contact_at') !== null ? Carbon::parse($lead->getAttribute('last_contact_at')) : null;
        $lastType = ActivityType::tryFrom((string) $lead->getAttribute('last_contact_type'));
        // Quiet since the last thing recorded, or since the lead was added.
        $quietFrom = $lastAt ?? $lead->created_at;
        $quietDays = $quietFrom === null ? 0 : (int) floor($quietFrom->diffInDays($now, true));

        return [
            'id' => $lead->id,
            'lead_type' => $lead->lead_type->value,
            'lead_type_label' => $lead->lead_type->label(),
            'name' => $lead->name,
            'contact_name' => $lead->contact_name,
            'phone' => $lead->phone,
            'secondary_phone' => $lead->secondary_phone,
            'email' => $lead->email,
            'address' => $lead->address,
            'district' => $lead->district,
            'thana' => $lead->thana,
            'area' => $lead->area,
            'market' => $lead->market,
            'landmark' => $lead->landmark,
            // ⭐ Slice 1's lifecycle, finally on the screen. Unverified is
            // a normal, working state (Joy's decision 1), so it is shown as
            // a fact about the lead, never as an error.
            'is_verified' => $lead->isVerified(),
            'verified_by' => $lead->verifiedBy?->name,
            // ⚠ A date string built on the server, in the app's own Asia/Dhaka
            // clock (C12). A timestamp sliced in the browser printed dates a
            // day early on 13 Sep.
            'verified_on' => $lead->verified_at?->toDateString(),
            'is_archived' => $lead->isArchived(),
            'created_by' => $lead->createdBy?->name,
            'potential' => $lead->potential?->value,
            'importance' => $lead->importance?->value,
            'profile' => self::profileRow($lead),
            // The place as one line, carried into the customer's address.
            'full_address' => $lead->fullAddress(),
            'map_url' => $lead->getAttribute('pin_latitude') !== null && $lead->getAttribute('pin_longitude') !== null
                ? MapLink::to($lead->getAttribute('pin_latitude'), $lead->getAttribute('pin_longitude'))
                : null,
            'status' => $lead->status->value,
            'status_label' => $lead->status->label(),
            'brand_id' => $lead->brand_id,
            'brand' => $lead->brand?->name,
            'assigned_to_user_id' => $lead->assigned_to_user_id,
            'assigned_to' => $lead->assignedTo?->name,
            'qualification_note' => $lead->qualification_note,
            'lost_reason_id' => $lead->lost_reason_id,
            'note' => $lead->note,
            // ⚠ Shown so a converted lead is obviously not editable back into
            // the pipeline, and so the link is visible rather than implied.
            'converted_customer' => $lead->convertedCustomer?->name,
            'converted_customer_id' => $lead->converted_customer_id,
            // ⭐ Plan D12: the supplier a supplier lead became.
            'converted_supplier' => $lead->convertedSupplier?->name,
            'converted_supplier_id' => $lead->converted_supplier_id,
            // ⚠ Shown for the same reason as the conversion above: a lead
            // that points at another is not the one to work, and saying so
            // on the row is cheaper than someone chasing the wrong person.
            'duplicate_of_lead_id' => $lead->duplicate_of_lead_id,
            'duplicate_of' => $lead->duplicateOf?->name,
            'created_at' => $lead->created_at?->toDateString(),

            // ⭐ Round 3: the two columns an officer plans his day by. Dates as
            // server-built strings in the app's own clock, for the reason above.
            'next_follow_up' => $nextAt === null ? null : [
                'at' => $nextAt->toDateTimeString(),
                'title' => $lead->getAttribute('next_follow_up_title'),
                'overdue' => $nextAt->isBefore($now),
                'today' => $nextAt->isSameDay($now),
            ],
            'last_contact' => $lastAt === null ? null : [
                'at' => $lastAt->toDateTimeString(),
                'type' => $lastType?->value,
                'label' => $lastType?->label(),
            ],
            'quiet_days' => $quietDays,
            'is_cold' => GoingCold::isCold($lead, $quietDays, $nextAt !== null),
        ];
    }

    /**
     * The profile of the lead's CURRENT type, flat, in the shape the form posts.
     *
     * ⚠ Only the current type's: a retailer corrected to a distributor keeps its
     * shop details in the database, but the edit form must not post them back
     * under the distributor's name.
     *
     * @return array<string, mixed>
     */
    public static function profileRow(Lead $lead): array
    {
        return match ($lead->lead_type) {
            LeadType::Retailer => [
                'shop_type_id' => $lead->retailerProfile?->shop_type_id,
                'shop_type' => $lead->retailerProfile?->shopType?->name,
                'current_supplier' => $lead->retailerProfile?->current_supplier,
                'wants_credit' => match ($lead->retailerProfile?->wants_credit) {
                    true => 'yes',
                    false => 'no',
                    default => null,
                },
                'competitor_brand_ids' => $lead->competitorBrands->pluck('id')->values()->all(),
                'competitor_brands' => $lead->competitorBrands->pluck('name')->values()->all(),
            ],
            LeadType::Distributor => [
                'business_nature' => $lead->distributorProfile?->business_nature,
                'other_companies' => $lead->distributorProfile?->other_companies,
                'warehouse_count' => $lead->distributorProfile?->warehouse_count,
                'warehouse_ownership' => $lead->distributorProfile?->warehouse_ownership?->value,
                'vehicle_count' => $lead->distributorProfile?->vehicle_count,
                'sales_staff_count' => $lead->distributorProfile?->sales_staff_count,
                'coverage' => $lead->coverageAreas->map(fn ($a) => ['district' => $a->district, 'thana' => $a->thana])->values()->all(),
            ],
            LeadType::Supplier => [
                'supplier_type_id' => $lead->supplierProfile?->supplier_type_id,
                'supplier_type' => $lead->supplierProfile?->supplierType?->name,
                'supply_categories' => $lead->supplierProfile?->supply_categories,
                'origin_district' => $lead->supplierProfile?->origin_district,
            ],
            LeadType::Customer => [],
        };
    }
}
