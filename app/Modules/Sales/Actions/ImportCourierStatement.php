<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Sales\Models\CourierStatement;
use App\Modules\Sales\Support\CourierStatements\CourierStatementFormats;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A courier's payout statement read into a DRAFT (courier slice C2).
 *
 * ⚠ It moves nothing. The draft is where a person sees which parcels are this
 * business's, decides the ones the ERP cannot place, and enters the money that
 * arrived; PostCourierStatement is what touches the books.
 *
 * ⚠ The courier's invoice id and date are typed, because the file carries
 * neither (Pathao's CSV, research doc §5).
 */
class ImportCourierStatement
{
    /**
     * @throws ValidationException
     */
    public function handle(
        MoneyAccount $courier,
        string $reference,
        mixed $statementDate,
        string $contents,
        ?string $filename,
        User $createdBy,
    ): CourierStatement {
        $reference = trim($reference);

        if (! $courier->isCourier() || ! $courier->is_active) {
            throw ValidationException::withMessages(['money_account_id' => 'Choose an active courier account.']);
        }

        $format = CourierStatementFormats::for($courier);

        if ($format === null) {
            throw ValidationException::withMessages([
                'money_account_id' => "The ERP cannot read {$courier->name}'s statements yet. Set its courier to Pathao under Bank & cash if that is who it is.",
            ]);
        }

        if ($reference === '') {
            throw ValidationException::withMessages(['statement_reference' => "Enter the courier's invoice id, as its panel shows it."]);
        }

        if (CourierStatement::query()->where('money_account_id', $courier->id)->where('statement_reference', $reference)->exists()) {
            throw ValidationException::withMessages([
                'statement_reference' => "{$courier->name} statement {$reference} has already been imported.",
            ]);
        }

        $lines = $format->parse($contents);

        return DB::transaction(function () use ($courier, $reference, $statementDate, $contents, $filename, $createdBy, $lines) {
            $statement = CourierStatement::create([
                'business_id' => $courier->business_id,
                'money_account_id' => $courier->id,
                'statement_reference' => $reference,
                'statement_date' => Carbon::parse($statementDate)->startOfDay(),
                'status' => CourierStatement::DRAFT,
                'source_filename' => $filename,
                'source_sha256' => hash('sha256', $contents),
                'created_by' => $createdBy->id,
            ]);

            foreach ($lines as $line) {
                $statement->lines()->create([
                    'business_id' => $statement->business_id,
                    'row_number' => $line->rowNumber,
                    'consignment_number' => $line->consignmentNumber,
                    'merchant_reference' => $line->merchantReference,
                    'booked_at' => $line->bookedAt,
                    'collectable_amount' => $line->collectable->toTaka(),
                    'collected_amount' => $line->collected->toTaka(),
                    'delivery_fee' => $line->deliveryFee->toTaka(),
                    'discount' => $line->discount->toTaka(),
                    'cod_fee' => $line->codFee->toTaka(),
                    'additional_charge' => $line->additionalCharge->toTaka(),
                    'final_fee' => $line->finalFee->toTaka(),
                    'payout' => $line->payout->toTaka(),
                ]);
            }

            return $statement->fresh('lines');
        });
    }
}
