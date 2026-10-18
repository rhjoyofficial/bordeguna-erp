<?php

use App\Modules\Accounting\Corrections\IncomeVoucherActionPolicy;
use App\Modules\Accounting\Corrections\SetOffActionPolicy;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Checks\BankMatchBlocker;
use App\Modules\Foundation\Corrections\Checks\LedgerEntryCheck;
use App\Modules\Foundation\Corrections\Contracts\CorrectionDatePolicy;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Foundation\Corrections\SecondPersonRule;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Purchasing\Corrections\SupplierDiscountActionPolicy;
use App\Modules\Purchasing\Models\SupplierDiscount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Tests\Support\CorrectionWorld;
use Tests\Support\DiscountWorld;
use Tests\Support\PartyWorld;

/**
 * The policy test kit (PHASE-0-CONTRACT v2, 1.2 and 3.7 test 40; ChatGPT's 49).
 * Every registered DocumentActionPolicy is walked against a real record of its
 * type, in each state the slice can reach. A module adds its type to the
 * `records` map below when it registers a policy.
 */
beforeEach(function () {
    $this->w = CorrectionWorld::make();
});

afterEach(fn () => Carbon::setTestNow());

/** @return list<array{0: Model, 1: string}> */
function kitRecords(CorrectionWorld $w): array
{
    $live = $w->record('5000', '2026-10-15');
    $reversed = $w->record('700', '2026-10-15');
    $w->reverse($reversed, $w->financeB);
    $matched = $w->record('900', '2026-10-15');
    $w->matchOnBankStatement($matched);

    return [[$live, 'live'], [$reversed->fresh(), 'reversed'], [$matched, 'bank-matched']];
}

/**
 * ⭐ Set-offs (D-F, 18 Oct 2026): waiting, posted and reversed. Built in their
 * own world (PartyWorld), whose users the four rules hold for just the same.
 *
 * @return list<array{0: Model, 1: string}>
 */
function setOffKitRecords(CorrectionWorld $w): array
{
    $p = PartyWorld::make($w);
    $bill = $p->bill('5000');
    $invoice = $p->invoice('5000');
    $pending = $p->raise([$bill->id => '100'], [$invoice->id => '100']);
    $posted = $p->posted([$bill->id => '200'], [$invoice->id => '200']);
    $reversed = $p->posted([$bill->id => '300'], [$invoice->id => '300']);
    $p->reverse($reversed, $p->w->financeB);

    return [[$pending->fresh(), 'pending'], [$posted->fresh(), 'posted'], [$reversed->fresh(), 'reversed']];
}

/**
 * ⭐ Supplier discounts (ruling P3, 18 Oct 2026): live, reversed, and live
 * with its batch moved since (Reverse blocked by the stock check).
 *
 * @return list<array{0: Model, 1: string}>
 */
function supplierDiscountKitRecords(CorrectionWorld $w): array
{
    $d = DiscountWorld::make($w);
    $live = $d->bill($d->order([[$d->salt, '25', '20.00']]), ['mode' => 'amount', 'value' => '50', 'reference' => 'INV-K1'], 'INV-K1');
    $reversed = $d->bill($d->order([[$d->sugar, '10', '10.00']]), ['mode' => 'amount', 'value' => '10', 'reference' => 'INV-K2'], 'INV-K2');
    $d->reverse(SupplierDiscount::where('supplier_bill_id', $reversed->id)->sole(), $w->financeB);
    app(RecordStockOut::class)->handle(product: $d->salt, warehouse: $d->warehouse, quantity: '1', performedBy: $w->owner, occurredAt: '2026-10-16');
    $moved = $d->bill($d->order([[$d->salt, '5', '20.00']]), ['mode' => 'amount', 'value' => '5', 'reference' => 'INV-K3'], 'INV-K3');
    app(RecordStockOut::class)->handle(product: $d->salt, warehouse: $d->warehouse, quantity: '25', performedBy: $w->owner, occurredAt: '2026-10-17');

    return [
        [SupplierDiscount::where('supplier_bill_id', $live->id)->sole(), 'live'],
        [SupplierDiscount::where('supplier_bill_id', $reversed->id)->sole(), 'reversed'],
        [SupplierDiscount::where('supplier_bill_id', $moved->id)->sole(), 'stock-moved'],
    ];
}

it('40. every registered policy follows the four rules for every user and every record state', function () {
    $registry = app(DocumentPolicyRegistry::class);
    $users = [$this->w->owner, $this->w->financeB, $this->w->manager, $this->w->sales];
    $problems = [];

    foreach ($registry->types() as $type) {
        $policy = $registry->for($type);
        $records = match ($type) {
            IncomeVoucherActionPolicy::TYPE => kitRecords($this->w),
            SetOffActionPolicy::TYPE => setOffKitRecords($this->w),
            SupplierDiscountActionPolicy::TYPE => supplierDiscountKitRecords($this->w),
            default => [],
        };

        expect($records)->not->toBeEmpty("The policy kit has no record for {$type}: add one.");

        foreach ($records as [$record, $state]) {
            foreach ($users as $user) {
                $set = $policy->actionsFor($record, $user);
                $primary = 0;

                foreach ($set->actions as $availability) {
                    $where = "{$type} {$state} for {$user->name}: {$availability->action->value}";

                    // 1. Edit is never offered on a Done record.
                    if ($set->stage === DocumentStage::Done && $availability->action === DocumentAction::Edit) {
                        $problems[] = "{$where}: Edit offered on a Done record";
                    }

                    // 2. Delete is never offered when the type has an effect.
                    if ($availability->action === DocumentAction::Delete && $policy->effectProfile()->effects !== []) {
                        $problems[] = "{$where}: Delete offered on a type with effects";
                    }

                    // 3. A blocked action keeps its place, with its blockers.
                    if (! $availability->allowed && $availability->blockers === []) {
                        $problems[] = "{$where}: blocked with no reason";
                    }

                    // 4. Every blocker is one plain sentence.
                    foreach ($availability->blockers as $blocker) {
                        if (! preg_match('/^[A-Z0-9].*[.]$/s', $blocker->message) || preg_match('/[A-Z][a-z]+\\\\|::|_[a-z]+_|\bnull\b/', $blocker->message) || substr_count(rtrim($blocker->message, '.'), '. ') > 1) {
                            $problems[] = "{$where}: blocker is not one plain sentence: {$blocker->message}";
                        }
                    }

                    $primary += $availability->primary ? 1 : 0;
                }

                if ($primary > 2) {
                    $problems[] = "{$type} {$state} for {$user->name}: more than two primary buttons";
                }
            }
        }
    }

    expect($problems)->toBe([]);
});

it('49. an effect with no check covering it fails closed', function () {
    $profile = new EffectProfile([EffectKind::Ledger, EffectKind::BankMatch, EffectKind::Stock], [BankMatchBlocker::class]);

    expect($profile->uncoveredEffects())->toBe([EffectKind::Ledger, EffectKind::Stock]);

    // A policy that declares Stock without a stock check blocks every money action.
    $policy = new class(app(CorrectionDatePolicy::class), app(SecondPersonRule::class)) extends IncomeVoucherActionPolicy
    {
        public function effectProfile(): EffectProfile
        {
            return new EffectProfile([EffectKind::Ledger, EffectKind::Stock], [LedgerEntryCheck::class]);
        }
    };

    $voucher = $this->w->record();
    $availability = $policy->availability($voucher, $this->w->owner, DocumentAction::Reverse);

    expect($availability->allowed)->toBeFalse()
        ->and(collect($availability->blockers)->pluck('code')->all())->toContain('missing_dependency_check');

    // The real Other income profile is fully covered.
    expect(app(DocumentPolicyRegistry::class)->for(IncomeVoucherActionPolicy::TYPE)->effectProfile()->uncoveredEffects())->toBe([]);
});
