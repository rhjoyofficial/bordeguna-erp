<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Crm\Enums\TaskStatus;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\CrmTask;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Support\PhoneIdentity;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Foundation\Corrections\SecondPersonRule;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\StockValue;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SampleIssue;
use App\Support\DocumentNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Give a sample (Samples, Joy 25 Oct 2026). Records the hand-out, sends it
 * for approval, and posts it at once when it may approve itself.
 *
 *   recipient  a customer, a lead, a phone book contact or an employee; or a
 *              new name and phone, saved to the phone book first (an existing
 *              contact with the same phone is used instead of a second one)
 *   approval   the existing approvals engine, workflow `sample_issue` (Owner
 *              or Manager). What it is worth (goods at cost, estimated from the
 *              chosen batch or the FEFO batches, plus the delivery charge) at
 *              or below the finance policy's second-person limit approves
 *              itself, with that sentence as its note; above it, it waits and
 *              the approvers are told.
 *   follow-up  an optional "ask for feedback on" date becomes a CRM follow-up
 *              for the giver, when the recipient is a lead or a customer (the
 *              only subjects a CRM follow-up has); for a contact or an
 *              employee the date is kept on the sample only.
 *
 * ⚠ Every refusal is a sentence on the field it is about. Nothing here moves
 * stock or posts: PostSampleIssue does, once approved.
 */
class RecordSampleIssue
{
    public function __construct(
        private readonly ApproveSampleIssue $approve,
        private readonly SecondPersonRule $secondPerson,
    ) {}

    /**
     * @param  array{kind: string, id?: int|null, name?: string|null, phone?: string|null}  $recipient
     * @param  list<array{product_id: int, stock_batch_id?: int|null, quantity: string}>  $lines
     * @param  array{method: string, charge?: string|null, money_account_id?: int|null, courier_account_id?: int|null, district?: string|null, zone?: string|null}  $delivery
     *
     * @throws ValidationException
     */
    public function handle(
        mixed $givenOn,
        array $recipient,
        string $purpose,
        Warehouse $warehouse,
        array $lines,
        array $delivery,
        User $givenBy,
        ?string $purposeNote = null,
        ?string $feedbackOn = null,
        ?string $note = null,
    ): SampleIssue {
        $date = Carbon::parse($givenOn ?? 'today')->startOfDay();

        if ($date->isFuture()) {
            throw ValidationException::withMessages(['given_on' => 'That date has not happened yet.']);
        }

        AccountingPeriod::assertOpenFor($date);

        if (! array_key_exists($purpose, SampleIssue::PURPOSES)) {
            throw ValidationException::withMessages(['purpose' => 'Choose why the sample is given: tasting, gift, marketing or other.']);
        }

        if ($purpose === 'other' && trim((string) $purposeNote) === '') {
            throw ValidationException::withMessages(['purpose_note' => 'Say what the sample is for.']);
        }

        if ($warehouse->not_for_sale) {
            throw ValidationException::withMessages(['warehouse_id' => "{$warehouse->name} is a Not for sale warehouse, so no samples are given from it."]);
        }

        $feedback = $feedbackOn === null || trim($feedbackOn) === '' ? null : Carbon::parse($feedbackOn)->startOfDay();

        if ($feedback !== null && $feedback->lt($date)) {
            throw ValidationException::withMessages(['feedback_on' => 'Ask for feedback on or after the day the sample is given.']);
        }

        $checked = $this->lines($lines, $warehouse);
        $deliveryFacts = $this->delivery($delivery, (int) $warehouse->business_id);

        return DB::transaction(function () use ($date, $recipient, $purpose, $purposeNote, $warehouse, $checked, $deliveryFacts, $givenBy, $feedback, $note) {
            [$who, $name, $phone] = $this->recipient($recipient, $givenBy);

            $issue = DocumentNumber::claim(
                'number',
                fn () => DocumentNumber::next(SampleIssue::query(), 'number', RecordCustomerAdvance::prefix('SMP', (int) $warehouse->business_id, $date)),
                fn (string $number) => SampleIssue::create([
                    'business_id' => $warehouse->business_id,
                    'number' => $number,
                    'given_on' => $date->toDateString(),
                    'recipient_type' => $who?->getMorphClass(),
                    'recipient_id' => $who?->getKey(),
                    'recipient_name' => $name,
                    'recipient_phone' => $phone,
                    'purpose' => $purpose,
                    'purpose_note' => self::text($purposeNote),
                    'warehouse_id' => $warehouse->id,
                    'delivery_method' => $deliveryFacts['method'],
                    'delivery_charge' => $deliveryFacts['charge']->toTaka(),
                    'delivery_money_account_id' => $deliveryFacts['money_account_id'],
                    'courier_money_account_id' => $deliveryFacts['courier_account_id'],
                    'delivery_district' => $deliveryFacts['district'],
                    'delivery_zone' => $deliveryFacts['zone'],
                    'feedback_on' => $feedback?->toDateString(),
                    'note' => self::text($note),
                    'given_by' => $givenBy->id,
                ]),
            );

            foreach ($checked as $index => $line) {
                $issue->lines()->create([
                    'product_id' => $line['product']->id,
                    'stock_batch_id' => $line['batch']?->id,
                    'quantity' => $line['quantity'],
                    'sort' => $index,
                ]);
            }

            if ($feedback !== null && ($who instanceof Lead || $who instanceof Customer)) {
                $task = CrmTask::create([
                    'business_id' => $issue->business_id,
                    'subject_type' => $who->getMorphClass(),
                    'subject_id' => $who->getKey(),
                    'title' => "Ask {$name} about the sample ({$issue->number})",
                    'note' => 'Given for '.strtolower(SampleIssue::PURPOSES[$purpose]).'.',
                    'due_at' => $feedback->copy()->setTime(10, 0),
                    'assigned_to_user_id' => $givenBy->id,
                    'status' => TaskStatus::Open,
                    'created_by' => $givenBy->id,
                ]);
                $issue->update(['crm_task_id' => $task->id]);
            }

            $issue->submitForApproval($givenBy);

            $worth = $this->estimate($checked, $warehouse)->plus($deliveryFacts['charge']);

            if ($givenBy->can('sales.samples.manage') && ! $this->secondPerson->applies((int) $issue->business_id, $worth->minor)) {
                return $this->approve->approve($issue->fresh('approvalRequest'), $givenBy, 'Within the approval limit, so approved as it was given.');
            }

            // After commit, so a hand-out that rolls back rings nobody. The
            // workflow's own stage (Owner or Manager) decides who is told.
            $approval = $issue->approvalRequest()->first();
            DB::afterCommit(fn () => app(NotifyStageApprovers::class)->handle(
                $approval,
                "Sample {$issue->number} to {$name}, worth Tk {$worth->format()}, waits for approval",
            ));

            return $issue->fresh(['lines', 'approvalRequest']);
        });
    }

    /**
     * @param  list<array{product_id: int, stock_batch_id?: int|null, quantity: string}>  $lines
     * @return list<array{product: Product, batch: ?StockBatch, quantity: string}>
     */
    private function lines(array $lines, Warehouse $warehouse): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Add at least one item.']);
        }

        $checked = [];

        foreach (array_values($lines) as $index => $line) {
            $product = Product::query()->find($line['product_id'] ?? 0);

            if ($product === null || ! $product->type->isStocked()) {
                throw ValidationException::withMessages(["lines.{$index}.product_id" => 'Choose a product that is kept in stock.']);
            }

            $quantity = trim((string) ($line['quantity'] ?? ''));

            if (preg_match('/^\d+(\.\d{1,4})?$/', $quantity) !== 1 || (float) $quantity <= 0) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => "Enter how much {$product->name} is given."]);
            }

            $batch = null;

            if (! empty($line['stock_batch_id'])) {
                $batch = StockBatch::query()->find($line['stock_batch_id']);

                if ($batch === null || (int) $batch->product_id !== (int) $product->id || (int) $batch->warehouse_id !== (int) $warehouse->id) {
                    throw ValidationException::withMessages(["lines.{$index}.stock_batch_id" => "That batch is not {$product->name} in {$warehouse->name}."]);
                }

                if ((float) $batch->current_quantity + 0.00001 < (float) $quantity) {
                    throw ValidationException::withMessages(["lines.{$index}.quantity" => "Batch {$batch->batch_number} holds only ".rtrim(rtrim((string) $batch->current_quantity, '0'), '.').'.']);
                }
            }

            // ⚠ Oldest expiry first: refused now when the warehouse cannot cover
            // it, rather than waiting for an approver only to fail at posting.
            // PostSampleIssue asks again under the lock.
            if ($batch === null) {
                $onHand = (float) StockBatch::query()->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->consumable()->sum('current_quantity');

                if ($onHand + 0.00001 < (float) $quantity) {
                    throw ValidationException::withMessages(["lines.{$index}.quantity" => "{$warehouse->name} holds only ".rtrim(rtrim(number_format($onHand, 4, '.', ''), '0'), '.')." of {$product->name} that can be given."]);
                }
            }

            $checked[] = ['product' => $product, 'batch' => $batch, 'quantity' => number_format((float) $quantity, 4, '.', '')];
        }

        return $checked;
    }

    /**
     * @param  array<string, mixed>  $delivery
     * @return array{method: string, charge: Money, money_account_id: ?int, courier_account_id: ?int, district: ?string, zone: ?string}
     */
    private function delivery(array $delivery, int $businessId): array
    {
        $method = (string) ($delivery['method'] ?? 'hand');

        if (! array_key_exists($method, SampleIssue::METHODS)) {
            throw ValidationException::withMessages(['delivery_method' => 'Choose how it is delivered: by hand, courier or rider.']);
        }

        $typed = trim((string) ($delivery['charge'] ?? ''));

        if ($typed !== '' && preg_match('/^\d+(\.\d{1,2})?$/', $typed) !== 1) {
            throw ValidationException::withMessages(['delivery_charge' => 'Enter the delivery charge in taka (for example 60 or 60.50).']);
        }

        $charge = $typed === '' ? Money::zero() : Money::ofTaka($typed);
        $moneyAccountId = null;
        $courierAccountId = null;

        if (! $charge->isZero()) {
            $courier = empty($delivery['courier_account_id']) ? null : MoneyAccount::query()->find($delivery['courier_account_id']);
            $paidFrom = empty($delivery['money_account_id']) ? null : MoneyAccount::query()->find($delivery['money_account_id']);

            if ($courier !== null) {
                if ($method !== 'courier' || ! $courier->isCourier() || (int) $courier->business_id !== $businessId) {
                    throw ValidationException::withMessages(['courier_account_id' => 'Choose the courier the charge is owed to.']);
                }
                $courierAccountId = $courier->id;
            } elseif ($paidFrom !== null) {
                if ((int) $paidFrom->business_id !== $businessId || $paidFrom->isCourier() || $paidFrom->isGateway() || ! $paidFrom->is_active || $paidFrom->account_id === null) {
                    throw ValidationException::withMessages(['delivery_money_account_id' => "{$paidFrom->name} cannot pay a delivery charge. Choose a cash, bank, wallet or employee cash account."]);
                }
                $moneyAccountId = $paidFrom->id;
            } else {
                throw ValidationException::withMessages(['delivery_money_account_id' => 'Say which account paid the delivery charge, or which courier it is owed to.']);
            }
        }

        return [
            'method' => $method,
            'charge' => $charge,
            'money_account_id' => $moneyAccountId,
            'courier_account_id' => $courierAccountId,
            'district' => self::text($delivery['district'] ?? null),
            'zone' => self::text($delivery['zone'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $recipient
     * @return array{0: ?Model, 1: string, 2: ?string}
     */
    private function recipient(array $recipient, User $by): array
    {
        $kind = (string) ($recipient['kind'] ?? '');

        if ($kind === 'new') {
            $name = trim((string) ($recipient['name'] ?? ''));
            $phone = trim((string) ($recipient['phone'] ?? ''));

            if ($name === '') {
                throw ValidationException::withMessages(['recipient_name' => 'Enter the name of the person the sample goes to.']);
            }

            if (PhoneIdentity::normalize($phone) === null) {
                throw ValidationException::withMessages(['recipient_phone' => 'Enter their phone number, so the sample can be followed up.']);
            }

            // ⚠ One person, one phone book row: the same phone finds them again.
            $contact = Contact::query()->where('phone_normalized', PhoneIdentity::normalize($phone))->first()
                ?? Contact::create(['name' => $name, 'phone' => $phone, 'source' => 'Sample', 'created_by' => $by->id]);

            return [$contact, $name, $phone];
        }

        $class = SampleIssue::RECIPIENTS[$kind] ?? null;
        // ⚠ An employee is a central record: only one with an assignment in
        // this business may be named here (EmployeeController::show's rule).
        $model = $class === null || empty($recipient['id']) ? null : $class::query()
            ->when($class === Employee::class, fn ($q) => $q->whereHas('assignments'))
            ->find($recipient['id']);

        if ($model === null) {
            throw ValidationException::withMessages(['recipient_id' => 'Choose who gets the sample, or enter a new name and phone.']);
        }

        $name = match (true) {
            $model instanceof Employee => $model->fullName(),
            default => (string) $model->name,
        };

        return [$model, $name, $model->phone ?: null];
    }

    /**
     * What the goods are worth for ROUTING the approval: the chosen batch's
     * cost, or the FEFO batches walked in the order they will be taken. The
     * posted cost is the movements' own, at approval.
     *
     * @param  list<array{product: Product, batch: ?StockBatch, quantity: string}>  $lines
     */
    private function estimate(array $lines, Warehouse $warehouse): Money
    {
        $total = Money::zero();

        foreach ($lines as $line) {
            if ($line['batch'] !== null) {
                $total = $total->plus($this->share($line['batch'], $line['quantity']));

                continue;
            }

            $remaining = (float) $line['quantity'];

            foreach (StockBatch::query()->where('product_id', $line['product']->id)->where('warehouse_id', $warehouse->id)->consumable()->get() as $batch) {
                if ($remaining <= 0) {
                    break;
                }

                $take = min($remaining, (float) $batch->current_quantity);
                $total = $total->plus($this->share($batch, number_format($take, 4, '.', '')));
                $remaining -= $take;
            }
        }

        return $total;
    }

    /**
     * ⭐ 26 Oct 2026: `$quantity` of a batch at its exact value (the share the
     * stock-out will take), quantity x unit cost for a batch holding nothing.
     */
    private function share(StockBatch $batch, string $quantity): Money
    {
        $held = StockValue::scaled((string) $batch->current_quantity);

        return $held > 0 && $batch->current_value !== null
            ? Money::ofMinor(StockValue::share((int) $batch->current_value, StockValue::scaled($quantity), $held))
            : Money::ofProduct($quantity, (string) $batch->unit_cost);
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
