<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Group partner links + the group 360 (Module 6, Slice 1; design doc
 * BDG-ERP-M6-GROUP-LAYER-DESIGN.md).
 *
 * Partners STAY per-business. A link says "these rows are the same
 * real-world party" - reversible, touching no document. The group screen
 * reads THROUGH links, and a viewer sees a member row only when they belong
 * to that member's business: membership is the natural gate, so a total
 * always follows the eyes.
 */
class GroupPartnerController extends Controller
{
    public function index(Request $request): Response
    {
        // The businesses this viewer belongs to - the whole visible world.
        $businessIds = $request->user()->businesses()->pluck('businesses.id');
        $businessNames = DB::table('businesses')->whereIn('id', $businessIds)->pluck('name', 'id');

        $links = DB::table('group_partner_links')->orderBy('name')->get()
            ->map(function ($link) use ($businessIds, $businessNames) {
                $members = DB::table('group_partner_link_members')
                    ->where('link_id', $link->id)
                    ->whereIn('business_id', $businessIds)
                    ->get()
                    ->map(fn ($member) => [
                        'business_id' => $member->business_id,
                        'business' => $businessNames[$member->business_id] ?? '?',
                        'partner_id' => $member->partner_id,
                    ] + $this->partnerFacts($link->kind, $member->business_id, $member->partner_id))
                    ->values();

                return [
                    'id' => $link->id,
                    'name' => $link->name,
                    'kind' => $link->kind,
                    'members' => $members,
                    'total_outstanding' => round($members->sum('outstanding'), 2),
                ];
            })
            // A link whose members are all invisible to this viewer is noise.
            ->filter(fn ($link) => $link['members']->isNotEmpty())
            ->values();

        return Inertia::render('group/partners', [
            'links' => $links,
            'suggestions' => $this->suggestions($businessIds, $businessNames),
        ]);
    }

    public function link(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'kind' => ['required', Rule::in(['customer', 'supplier'])],
            'members' => ['required', 'array', 'min:2'],
            'members.*.business_id' => ['required', 'integer', 'exists:businesses,id'],
            'members.*.partner_id' => ['required', 'integer'],
        ]);

        // Linking is a statement about companies the linker can see.
        $visible = $request->user()->businesses()->pluck('businesses.id')->flip();
        foreach ($data['members'] as $member) {
            abort_unless(isset($visible[$member['business_id']]), 403);
        }

        DB::transaction(function () use ($data, $request) {
            $linkId = DB::table('group_partner_links')->insertGetId([
                'name' => $data['name'],
                'kind' => $data['kind'],
                'created_by' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach ($data['members'] as $member) {
                DB::table('group_partner_link_members')->insert([
                    'link_id' => $linkId,
                    'business_id' => $member['business_id'],
                    'partner_id' => $member['partner_id'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        return back()->with('success', 'Linked as one party. No document was touched.');
    }

    public function unlink(int $link): RedirectResponse
    {
        DB::table('group_partner_links')->where('id', $link)->delete();

        return back()->with('success', 'Unlinked. Every company row stands as before.');
    }

    /**
     * Balance facts for one member, computed from raw tables (no scopes to
     * fight): the ageing identity restated per party.
     *
     * @return array{name: string, code: ?string, outstanding: float}
     */
    private function partnerFacts(string $kind, int $businessId, int $partnerId): array
    {
        if ($kind === 'customer') {
            $row = DB::table('customers')->where('business_id', $businessId)->where('id', $partnerId)->first();

            $invoiced = (float) DB::table('sales_invoices')
                ->where('business_id', $businessId)->where('customer_id', $partnerId)->sum('total');
            $received = (float) DB::table('customer_receipt_allocations')
                ->join('sales_invoices', 'sales_invoices.id', '=', 'customer_receipt_allocations.sales_invoice_id')
                ->where('sales_invoices.business_id', $businessId)
                ->where('sales_invoices.customer_id', $partnerId)
                ->sum('customer_receipt_allocations.amount');
            $credited = (float) DB::table('sales_return_allocations')
                ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_return_allocations.sales_invoice_id')
                ->where('sales_invoices.business_id', $businessId)
                ->where('sales_invoices.customer_id', $partnerId)
                ->sum('sales_return_allocations.amount');

            return [
                'name' => $row->name ?? '?',
                'code' => $row->code ?? null,
                'outstanding' => round($invoiced - $received - $credited - (float) DB::table('party_adjustment_allocations')
                    ->join('sales_invoices', 'sales_invoices.id', '=', 'party_adjustment_allocations.sales_invoice_id')
                    ->where('sales_invoices.business_id', $businessId)
                    ->where('sales_invoices.customer_id', $partnerId)
                    ->sum('party_adjustment_allocations.amount'), 2),
            ];
        }

        $row = DB::table('suppliers')->where('business_id', $businessId)->where('id', $partnerId)->first();

        $billed = (float) DB::table('supplier_bills')
            ->where('business_id', $businessId)->where('supplier_id', $partnerId)
            ->whereNull('reversed_at')->sum('total');
        $paid = (float) DB::table('supplier_payment_allocations')
            ->join('supplier_bills', 'supplier_bills.id', '=', 'supplier_payment_allocations.supplier_bill_id')
            ->where('supplier_bills.business_id', $businessId)
            ->where('supplier_bills.supplier_id', $partnerId)
            ->sum('supplier_payment_allocations.amount');
        $credited = (float) DB::table('supplier_credit_note_allocations')
            ->join('supplier_bills', 'supplier_bills.id', '=', 'supplier_credit_note_allocations.supplier_bill_id')
            ->where('supplier_bills.business_id', $businessId)
            ->where('supplier_bills.supplier_id', $partnerId)
            ->sum('supplier_credit_note_allocations.amount');

        return [
            'name' => $row->name ?? '?',
            'code' => $row->code ?? null,
            'outstanding' => round($billed - $paid - $credited - (float) DB::table('party_adjustment_allocations')
                ->join('supplier_bills', 'supplier_bills.id', '=', 'party_adjustment_allocations.supplier_bill_id')
                ->where('supplier_bills.business_id', $businessId)
                ->where('supplier_bills.supplier_id', $partnerId)
                ->sum('party_adjustment_allocations.amount'), 2),
        ];
    }

    /**
     * Unlinked partners sharing one normalized phone across companies -
     * the same identity key the CRM already derives in ONE place.
     */
    private function suggestions($businessIds, $businessNames)
    {
        $linked = DB::table('group_partner_link_members')->get()
            ->map(fn ($member) => $member->business_id.':'.$member->partner_id)
            ->flip();

        return DB::table('customers')
            ->whereIn('business_id', $businessIds)
            ->whereNotNull('phone_normalized')
            ->where('phone_normalized', '!=', '')
            ->get(['id', 'business_id', 'name', 'code', 'phone_normalized'])
            ->filter(fn ($customer) => ! isset($linked[$customer->business_id.':'.$customer->id]))
            ->groupBy('phone_normalized')
            // A suggestion is one phone in at least two DIFFERENT companies.
            ->filter(fn ($group) => $group->pluck('business_id')->unique()->count() > 1)
            ->map(fn ($group, $phone) => [
                'phone' => $phone,
                'kind' => 'customer',
                'members' => $group->sortBy('business_id')->map(fn ($customer) => [
                    'business_id' => $customer->business_id,
                    'business' => $businessNames[$customer->business_id] ?? '?',
                    'partner_id' => $customer->id,
                    'name' => $customer->name,
                    'code' => $customer->code,
                ])->values(),
            ])
            ->values();
    }
}
