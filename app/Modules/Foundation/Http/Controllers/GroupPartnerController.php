<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessesWithPermission;
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
 *
 * ⚠⚠ MEMBERSHIP ALONE WAS THE GATE until the 13 Oct 2026 audit, so a
 * self-service login read every linked party's outstanding balance, and any
 * member could delete any link by id. Reading now needs the permission that
 * reads that kind of party's money in THAT business (sales or purchasing
 * reports); linking and unlinking need the permission that manages that kind
 * of party in every business the link touches.
 */
class GroupPartnerController extends Controller
{
    /** Reading a party's balance, per kind: the same permission as its reports. */
    private const READ = ['customer' => 'sales.reports.view', 'supplier' => 'purchasing.reports.view'];

    /** Saying two rows are one party is managing that party. */
    private const MANAGE = ['customer' => 'sales.customers.manage', 'supplier' => 'purchasing.suppliers.manage'];

    public function index(Request $request): Response
    {
        // The businesses whose customers' / suppliers' balances this viewer may
        // read. Roles are per business, so each is asked separately.
        $readable = [
            'customer' => BusinessesWithPermission::of($request->user(), self::READ['customer']),
            'supplier' => BusinessesWithPermission::of($request->user(), self::READ['supplier']),
        ];
        abort_if($readable['customer']->isEmpty() && $readable['supplier']->isEmpty(), 403);

        $businessNames = DB::table('businesses')
            ->whereIn('id', $readable['customer']->merge($readable['supplier'])->unique())
            ->pluck('name', 'id');

        $links = DB::table('group_partner_links')->orderBy('name')->get()
            ->map(function ($link) use ($readable, $businessNames) {
                $members = DB::table('group_partner_link_members')
                    ->where('link_id', $link->id)
                    ->whereIn('business_id', $readable[$link->kind] ?? collect())
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
            // Suggestions are customers only, so they follow the customer gate.
            'suggestions' => $this->suggestions($readable['customer'], $businessNames),
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

        // Linking is a statement about parties the linker may manage, in every
        // company named.
        $visible = BusinessesWithPermission::of($request->user(), self::MANAGE[$data['kind']])->flip();
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

    public function unlink(Request $request, int $link): RedirectResponse
    {
        $row = DB::table('group_partner_links')->where('id', $link)->first();
        abort_if($row === null, 404);

        // ⚠ Every company the link touches must be one this user manages that
        // kind of party in. A link half in somebody else's company is not
        // theirs to undo.
        $mine = BusinessesWithPermission::of($request->user(), self::MANAGE[$row->kind] ?? self::MANAGE['customer']);
        $touches = DB::table('group_partner_link_members')->where('link_id', $link)->pluck('business_id');
        abort_unless($mine->isNotEmpty() && $touches->diff($mine)->isEmpty(), 403);

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
