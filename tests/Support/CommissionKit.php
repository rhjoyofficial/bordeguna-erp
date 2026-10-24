<?php

namespace Tests\Support;

use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionInput;
use App\Modules\Foundation\Corrections\CorrectionService;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Actions\Commission\PayCommission;
use App\Modules\Sales\Actions\Commission\SaveCommissionAgreement;
use App\Modules\Sales\Corrections\CommissionPaymentActionPolicy;
use App\Modules\Sales\Corrections\CommissionSettlementActionPolicy;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\Models\CommissionAgreement;
use App\Modules\Sales\Models\CommissionSettlement;
use App\Modules\Sales\Support\CommissionHeads;
use Illuminate\Support\Str;

/**
 * ⭐ Commission settlements and payments for the policy kit (25 Oct 2026):
 * built straight on the posting engine, without sales, because the kit only
 * asks the policy about records in each state.
 */
final class CommissionKit
{
    /** @return array<string, list<array{0: mixed, 1: string}>> */
    public static function records(CorrectionWorld $w): array
    {
        (new SalesPermissionsSeeder)->run();
        $w->enter($w->business);

        $agent = Supplier::create(['business_id' => $w->business->id, 'name' => 'Kit Agent', 'is_active' => true]);
        $agreement = app(SaveCommissionAgreement::class)->create([
            'agreed_on' => '2026-10-01', 'party_type' => 'supplier', 'party_id' => $agent->id,
            'valid_from' => '2026-10-01', 'deal_type' => 'per_unit', 'rate' => '10',
            'counts_credited_sales' => true, 'product_ids' => [], 'category_ids' => [], 'tiers' => [],
        ], $w->owner);

        $owed = self::posted($w, $agreement, 'CS-K001', '300');
        $paidOff = self::posted($w, $agreement, 'CS-K002', '200');
        $payment = app(PayCommission::class)->handle($paidOff, '2026-10-12', '200', $w->cash, null, $w->owner);
        $reversed = self::posted($w, $agreement, 'CS-K003', '100');
        $pending = CommissionSettlement::create([
            'business_id' => $w->business->id, 'number' => 'CS-K004', 'commission_agreement_id' => $agreement->id,
            'settled_on' => '2026-10-10', 'from_date' => '2026-10-01', 'through_date' => '2026-10-10',
            'amount' => '50', 'method' => 'payable', 'status' => CommissionSettlement::PENDING, 'created_by' => $w->owner->id,
        ]);

        auth()->setUser($w->owner);
        $service = app(CorrectionService::class);
        $input = new CorrectionInput(CommissionSettlementActionPolicy::TYPE, $reversed->id, DocumentAction::Reverse, new ActionReason(null, 'Typed on the wrong record'), null, ReverseDateChoice::OriginalDate, (string) Str::uuid());
        $service->submit($input, $w->owner, $service->preview($input, $w->owner)->previewToken);

        return [
            CommissionSettlementActionPolicy::TYPE => [
                [$owed->fresh(), 'owed'], [$paidOff->fresh(), 'paid off'], [$reversed->fresh(), 'reversed'], [$pending->fresh(), 'waiting'],
            ],
            CommissionPaymentActionPolicy::TYPE => [[$payment->fresh(), 'live']],
        ];
    }

    private static function posted(CorrectionWorld $w, CommissionAgreement $agreement, string $number, string $amount): CommissionSettlement
    {
        $settlement = CommissionSettlement::create([
            'business_id' => $w->business->id, 'number' => $number, 'commission_agreement_id' => $agreement->id,
            'settled_on' => '2026-10-10', 'from_date' => '2026-10-01', 'through_date' => '2026-10-10',
            'amount' => $amount, 'method' => 'payable', 'status' => CommissionSettlement::POSTED, 'created_by' => $w->owner->id,
        ]);
        $money = Money::ofTaka($amount);
        $journal = app(PostJournal::class)->handle(new JournalDraft(
            date: $settlement->settled_on->copy(),
            lines: [
                JournalLineDraft::debit(CommissionHeads::expense()->id, $money, 'Kit', $agreement->supplier),
                JournalLineDraft::credit(CommissionHeads::payable()->id, $money, 'Kit', $agreement->supplier),
            ],
            source: $settlement,
        ), $w->owner);
        $settlement->forceFill(['journal_id' => $journal->id])->save();

        return $settlement;
    }
}
