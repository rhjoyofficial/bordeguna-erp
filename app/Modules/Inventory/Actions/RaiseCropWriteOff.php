<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Scopes\BusinessScope;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\CropWriteOff;
use App\Modules\Inventory\Support\CropHeads;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * ⭐ Farm harvest (B9, 24 Oct 2026): a crop failure, raised for the Owner's
 * approval (the farm guide, step 8). Nothing posts here: the write-off waits,
 * with its reason, until ApproveCropWriteOff.
 *
 * The same checks as approval, so a raise that cannot be approved is refused
 * now with its sentence rather than at the Owner's desk: a Crops in Progress
 * head, an active expense head for the loss, an open month, an amount above
 * zero and no more than the head holds from that day on. Approval asks the
 * balance again, under a lock: a harvest may have taken it meanwhile.
 */
class RaiseCropWriteOff
{
    public function __construct(private readonly NotifyStageApprovers $notify) {}

    /** @throws ValidationException */
    public function handle(
        string $writtenOffOn,
        Account $cropAccount,
        Account $lossAccount,
        string $amount,
        string $reason,
        User $raisedBy,
        ?string $idempotencyKey = null,
    ): CropWriteOff {
        if ($idempotencyKey !== null && ($done = CropWriteOff::query()->where('idempotency_key', $idempotencyKey)->first()) !== null) {
            return $done;
        }

        $date = Carbon::parse($writtenOffOn)->startOfDay();
        $money = self::check($date, $cropAccount, $lossAccount, $amount);

        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason_text' => 'Say why the crop failed, in at least 5 characters.']);
        }

        $businessId = (int) app(BusinessContext::class)->id();

        $workflow = ApprovalWorkflow::withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $businessId)->where('key', 'crop_write_off')->where('is_active', true)
            ->whereHas('stages')->exists();

        if (! $workflow) {
            throw ValidationException::withMessages([
                'amount' => 'Nobody is set up to approve a crop write-off yet. Ask the administrator to run the write-off approval setup.',
            ]);
        }

        $writeOff = DB::transaction(function () use ($businessId, $date, $cropAccount, $lossAccount, $money, $reason, $raisedBy, $idempotencyKey) {
            $writeOff = DocumentNumber::claim('number', fn () => $this->nextNumber($businessId, $date), fn (string $number) => CropWriteOff::create([
                'business_id' => $businessId,
                'number' => $number,
                'written_off_on' => $date->toDateString(),
                'crop_account_id' => $cropAccount->id,
                'loss_account_id' => $lossAccount->id,
                'amount_paisa' => $money->minor,
                'reason_text' => trim($reason),
                'status' => CropWriteOff::WAITING,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $raisedBy->id,
            ]));

            $writeOff->submitForApproval($raisedBy);

            return $writeOff;
        });

        $this->notify->handle($writeOff->approvalRequest()->first(), "Crop write-off {$writeOff->number} of Tk {$money->format()} is waiting for your approval");

        return $writeOff->fresh();
    }

    /**
     * Every rule a write-off must still meet when it posts. Public: approval
     * asks the same questions again, under the head's lock.
     *
     * @throws ValidationException
     */
    public static function check(Carbon $date, Account $cropAccount, Account $lossAccount, string $amount): Money
    {
        if ($date->isFuture()) {
            throw ValidationException::withMessages(['written_off_on' => 'A write-off cannot be dated in the future.']);
        }

        try {
            AccountingPeriod::assertOpenFor($date);
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['written_off_on' => $e->getMessage()]);
        }

        if (! CropHeads::allows($cropAccount)) {
            throw ValidationException::withMessages([
                'crop_account_id' => 'Choose a Crops in Progress head (code '.CropHeads::CODE.' or under it) that takes entries.',
            ]);
        }

        if ($lossAccount->type !== AccountType::Expense || ! $lossAccount->is_postable || ! $lossAccount->is_active) {
            throw ValidationException::withMessages(['loss_account_id' => 'Choose an expense head that takes entries for the loss.']);
        }

        try {
            $money = Money::ofTaka(trim($amount));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['amount' => 'Type the amount in taka.']);
        }

        if ($money->minor <= 0) {
            throw ValidationException::withMessages(['amount' => 'The amount must be more than zero.']);
        }

        $available = CropHeads::availableFromMinor($cropAccount, $date);

        if ($money->minor > $available) {
            throw ValidationException::withMessages([
                'amount' => "{$cropAccount->label()} holds only Tk ".Money::ofMinor(max(0, $available))->format().' from '.$date->format('d/m/Y').' on, so no more than that can be written off.',
            ]);
        }

        return $money;
    }

    private function nextNumber(int $businessId, Carbon $date): string
    {
        $business = Business::withoutGlobalScopes()->findOrFail($businessId);
        $prefix = 'CWO-'.FiscalYear::containing($date, $business)->label().'-';

        return DocumentNumber::next(CropWriteOff::query(), 'number', $prefix);
    }
}
