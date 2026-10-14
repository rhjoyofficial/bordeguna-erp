<?php

namespace App\Modules\Accounting\Actions\Loans;

use App\Models\User;
use App\Modules\Accounting\Enums\LoanDayCount;
use App\Modules\Accounting\Enums\LoanDirection;
use App\Modules\Accounting\Enums\LoanFrequency;
use App\Modules\Accounting\Enums\LoanRepaymentType;
use App\Modules\Accounting\Enums\LoanWaterfall;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Counterparty;
use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Models\LoanScheduleRow;
use App\Modules\Accounting\Support\CounterpartyIdentity;
use App\Modules\Accounting\Support\CounterpartyRules;
use App\Modules\Accounting\Support\Loans\LoanHeads;
use App\Modules\Accounting\Support\Loans\LoanMath;
use App\Modules\Accounting\Support\Loans\LoanTerms;
use App\Modules\Accounting\Support\Loans\ScheduleBuilder;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Support\BusinessContext;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Creates or rewrites a DRAFT loan agreement and its schedule (loans plan v2,
 * phase 2). Nothing here reaches the ledger.
 *
 * ⚠ Refused here, before anybody is asked to approve it:
 *  - one of our own businesses as the counterparty (internal money moves by
 *    Inter-business transfers, 1195/2190), or a deactivated counterparty;
 *  - a principal head that does not fit the direction, the term and the
 *    counterparty (LoanHeads), or a related-party mismatch (CounterpartyRules);
 *  - an interest-free loan with no reason written down;
 *  - a custom schedule whose principal does not add up to the loan.
 *
 * ⚠ Loans from Owners is allowed with a WARNING, never a block: owner money is
 * normally capital (3100), and the accountant decides.
 */
class SaveLoanContract
{
    /** @var list<string> sentences the screen shows after saving */
    public array $warnings = [];

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function handle(array $data, User $by, ?LoanContract $loan = null): LoanContract
    {
        $this->warnings = [];

        if ($loan !== null && $loan->status->value !== 'draft') {
            throw ValidationException::withMessages(['loan' => "{$loan->loan_number} is {$loan->status->label()}. Only a draft is edited; an approved loan changes by an amendment."]);
        }

        $direction = LoanDirection::tryFrom((string) ($data['direction'] ?? '')) ?? throw ValidationException::withMessages(['direction' => 'Say whether the loan was given or taken.']);
        $counterparty = $this->counterparty($data['counterparty_id'] ?? null);
        $principal = $this->principal($data['principal'] ?? null);
        [$start, $maturity] = $this->dates($data['start_date'] ?? null, $data['maturity_date'] ?? null);
        $rateE4 = $this->rate($data['annual_rate'] ?? '0');
        $interestFreeReason = $this->text($data['interest_free_reason'] ?? null);

        if ($rateE4 === 0 && $interestFreeReason === null) {
            throw ValidationException::withMessages(['interest_free_reason' => 'An interest-free loan needs its reason written down (who decided, and why). Related-party loans without interest are reviewed for tax.']);
        }

        $repaymentType = LoanRepaymentType::tryFrom((string) ($data['repayment_type'] ?? '')) ?? throw ValidationException::withMessages(['repayment_type' => 'Choose how the principal is repaid.']);
        $frequency = LoanFrequency::tryFrom((string) ($data['frequency'] ?? '')) ?? throw ValidationException::withMessages(['frequency' => 'Choose how often installments fall due.']);
        $dayCount = LoanDayCount::tryFrom((string) ($data['day_count'] ?? 'actual_365')) ?? LoanDayCount::Actual365;
        $waterfall = LoanWaterfall::tryFrom((string) ($data['waterfall'] ?? 'interest_first')) ?? LoanWaterfall::InterestFirst;
        $grace = (int) ($data['grace_months'] ?? 0);

        if ($repaymentType === LoanRepaymentType::EqualInstallments && $frequency === LoanFrequency::AtMaturity) {
            throw ValidationException::withMessages(['frequency' => 'Equal installments fall due monthly or quarterly. A loan repaid in one go at maturity is a bullet loan.']);
        }

        if ($grace < 0 || ($grace > 0 && ! $start->copy()->addMonthsNoOverflow($grace)->lessThan($maturity))) {
            throw ValidationException::withMessages(['grace_months' => 'The grace period must end before the loan matures.']);
        }

        $principalHead = $this->principalHead($direction, $counterparty, (string) ($data['principal_head'] ?? ''), $start, $maturity);

        $certificateRate = $this->text(isset($data['tds_certificate_rate']) ? (string) $data['tds_certificate_rate'] : null);
        $certificateRef = $this->text($data['tds_certificate_ref'] ?? null);

        if ($certificateRate !== null) {
            if ($direction !== LoanDirection::Taken) {
                throw ValidationException::withMessages(['tds_certificate_rate' => 'A TDS certificate applies to interest the business pays, so only to a loan taken.']);
            }

            $certificateE4 = $this->rate($certificateRate, 'tds_certificate_rate');

            if ($certificateRef === null) {
                throw ValidationException::withMessages(['tds_certificate_ref' => 'Give the certificate number for the reduced or nil rate.']);
            }

            $certificateRate = LoanMath::rateString($certificateE4);
        }

        $terms = new LoanTerms($rateE4, $dayCount, $repaymentType, $frequency, $grace, $maturity, $waterfall, $interestFreeReason);
        $rows = $repaymentType === LoanRepaymentType::Custom
            ? $this->customRows($data['custom_rows'] ?? [], $terms, $principal->minor, $start, $maturity)
            : ScheduleBuilder::build($terms, $principal->minor, $start);

        $attributes = [
            'direction' => $direction,
            'counterparty_id' => $counterparty->id,
            'principal_minor' => $principal->minor,
            'start_date' => $start->toDateString(),
            'maturity_date' => $maturity->toDateString(),
            'annual_rate' => LoanMath::rateString($rateE4),
            'interest_free_reason' => $rateE4 === 0 ? $interestFreeReason : null,
            'day_count' => $dayCount,
            'repayment_type' => $repaymentType,
            'frequency' => $frequency,
            'grace_months' => $grace,
            'waterfall' => $waterfall,
            'collateral' => $this->text($data['collateral'] ?? null),
            'purpose' => $this->text($data['purpose'] ?? null),
            'principal_account_id' => $principalHead->id,
            'current_portion_account_id' => ($slug = LoanHeads::currentPortionSlug((string) $principalHead->system_slug)) === null ? null : Account::bySlug($slug)?->id,
            'interest_accrual_account_id' => $this->head(LoanHeads::accrualSlug($direction))->id,
            'interest_pl_account_id' => $this->head(LoanHeads::profitAndLossSlug($direction))->id,
            'tds_certificate_ref' => $certificateRate === null ? null : $certificateRef,
            'tds_certificate_rate' => $certificateRate,
            'updated_by' => $by->id,
        ];

        if ($principalHead->system_slug === LoanHeads::OWNER_LOANS) {
            $this->warnings[] = LoanHeads::OWNER_WARNING;
        }

        return DB::transaction(function () use ($loan, $attributes, $rows, $by) {
            if ($loan === null) {
                $businessId = app(BusinessContext::class)->id();
                $prefix = 'LN-'.Carbon::today()->year.'-';

                $loan = DocumentNumber::claim(
                    'loan_number',
                    fn () => DocumentNumber::next(LoanContract::query(), 'loan_number', $prefix),
                    fn (string $number) => LoanContract::query()->create($attributes + [
                        'business_id' => $businessId,
                        'loan_number' => $number,
                        'status' => 'draft',
                        'created_by' => $by->id,
                    ]),
                );
            } else {
                $loan = LoanContract::query()->lockForUpdate()->findOrFail($loan->id);

                if ($loan->status->value !== 'draft') {
                    throw ValidationException::withMessages(['loan' => "{$loan->loan_number} was sent for approval while you were editing it, so your changes were not saved."]);
                }

                $loan->update($attributes);
                LoanScheduleRow::query()->where('loan_contract_id', $loan->id)->delete();
            }

            foreach ($rows as $index => $row) {
                LoanScheduleRow::query()->create([
                    'business_id' => $loan->business_id,
                    'loan_contract_id' => $loan->id,
                    'version' => 1,
                    'sequence' => $index + 1,
                    'due_date' => $row['due_date'],
                    'principal_due_minor' => $row['principal_minor'],
                    'interest_due_minor' => $row['interest_minor'],
                ]);
            }

            return $loan->fresh(['counterparty', 'scheduleRows']);
        });
    }

    private function counterparty(mixed $id): Counterparty
    {
        $counterparty = ($id === null || $id === '') ? null : Counterparty::query()->find((int) $id);

        if ($counterparty === null) {
            throw ValidationException::withMessages(['counterparty_id' => 'Choose the counterparty, or add it with "New counterparty".']);
        }

        if (! $counterparty->is_active) {
            throw ValidationException::withMessages(['counterparty_id' => "{$counterparty->legal_name} is deactivated. Reactivate it in Accounting > Counterparties first."]);
        }

        // ⚠ Internal funding is never a loan (ChatGPT confirmation, point 5).
        if (($business = CounterpartyIdentity::ownBusinessNamed($counterparty->legal_name)) !== null) {
            throw ValidationException::withMessages(['counterparty_id' => CounterpartyIdentity::ownBusinessRefusal($counterparty->legal_name, $business)]);
        }

        return $counterparty;
    }

    private function principal(mixed $value): Money
    {
        try {
            $money = Money::ofTaka((string) $value);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['principal' => 'Enter the principal in taka.']);
        }

        if ($money->minor <= 0) {
            throw ValidationException::withMessages(['principal' => 'The principal must be more than zero.']);
        }

        return $money;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function dates(mixed $start, mixed $maturity): array
    {
        try {
            $from = Carbon::parse((string) $start)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['start_date' => 'Enter the day the loan starts.']);
        }

        try {
            $to = Carbon::parse((string) $maturity)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['maturity_date' => 'Enter the day the loan matures.']);
        }

        if (! $to->greaterThan($from)) {
            throw ValidationException::withMessages(['maturity_date' => 'The loan must mature after it starts.']);
        }

        return [$from, $to];
    }

    private function rate(mixed $value, string $field = 'annual_rate'): int
    {
        try {
            $rate = LoanMath::rateE4((string) $value);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => 'Enter the rate as a percentage a year, like 12 or 12.5 (four decimal places at most).']);
        }

        if ($rate > 100 * LoanMath::PERCENT) {
            throw ValidationException::withMessages([$field => 'A rate above 100% a year is not a loan this register keeps.']);
        }

        return $rate;
    }

    private function principalHead(LoanDirection $direction, Counterparty $counterparty, string $slug, Carbon $start, Carbon $maturity): Account
    {
        $allowed = LoanHeads::allowedPrincipalSlugs($direction, $counterparty, $start, $maturity);
        $slug = $slug === '' ? $allowed[0] : $slug;

        if (($refusal = LoanHeads::refusal($direction, $counterparty, $slug, $start, $maturity)) !== null) {
            throw ValidationException::withMessages(['principal_head' => $refusal]);
        }

        $account = $this->head($slug, 'principal_head');

        if (($refusal = CounterpartyRules::refusal($account, $counterparty)) !== null) {
            throw ValidationException::withMessages(['principal_head' => $refusal]);
        }

        return $account;
    }

    private function head(string $slug, string $field = 'loan'): Account
    {
        $account = Account::bySlug($slug);

        if ($account === null || ! $account->is_postable || ! $account->is_active) {
            throw ValidationException::withMessages([$field => "The chart has no usable head for {$slug}. Run the loan heads migration first."]);
        }

        return $account;
    }

    /**
     * @param  mixed  $input  list of {due_date, principal, interest?}
     * @return list<array{due_date: string, principal_minor: int, interest_minor: int}>
     */
    private function customRows(mixed $input, LoanTerms $terms, int $principal, Carbon $start, Carbon $maturity): array
    {
        $rows = [];

        foreach (is_array($input) ? array_values($input) : [] as $index => $row) {
            $date = $row['due_date'] ?? null;

            if (($date === null || $date === '') && ($row['principal'] ?? '') === '' && ($row['interest'] ?? '') === '') {
                continue;
            }

            try {
                $due = Carbon::parse((string) $date)->startOfDay();
            } catch (\Throwable) {
                throw ValidationException::withMessages(["custom_rows.{$index}.due_date" => 'Enter the due date.']);
            }

            if (! $due->greaterThan($start) || $due->greaterThan($maturity)) {
                throw ValidationException::withMessages(["custom_rows.{$index}.due_date" => 'Each installment falls due after the start and on or before maturity.']);
            }

            try {
                $principalPart = Money::ofTaka((string) (($row['principal'] ?? '') === '' ? '0' : $row['principal']))->minor;
                $interestPart = ($row['interest'] ?? '') === '' ? null : Money::ofTaka((string) $row['interest'])->minor;
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(["custom_rows.{$index}.principal" => 'Enter amounts in taka.']);
            }

            if ($principalPart < 0 || ($interestPart !== null && $interestPart < 0)) {
                throw ValidationException::withMessages(["custom_rows.{$index}.principal" => 'An installment cannot be negative.']);
            }

            $rows[] = ['due_date' => $due->toDateString(), 'principal_minor' => $principalPart, 'interest_minor' => $interestPart];
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['custom_rows' => 'A custom schedule needs at least one installment.']);
        }

        usort($rows, fn (array $a, array $b) => $a['due_date'] <=> $b['due_date']);

        $total = array_sum(array_column($rows, 'principal_minor'));

        if ($total !== $principal) {
            $gap = Money::ofMinor(abs($principal - $total))->format();

            throw ValidationException::withMessages(['custom_rows' => "The installments' principal differs from the loan by {$gap}. They must add up to the principal exactly."]);
        }

        // Interest left blank is worked out on the declining balance.
        $computed = ScheduleBuilder::interestFor($terms, $principal, $start, $rows);

        return array_map(fn (array $row, int $interest) => [
            'due_date' => $row['due_date'],
            'principal_minor' => $row['principal_minor'],
            'interest_minor' => $row['interest_minor'] ?? $interest,
        ], $rows, $computed);
    }

    private function text(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
