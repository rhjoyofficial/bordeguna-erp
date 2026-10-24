<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Sales\Models\CourierStatement;
use App\Modules\Sales\Support\CourierStatements\CourierStatementFormats;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A courier's payout statement read into a DRAFT (courier slice C2, v2 11 Oct
 * 2026).
 *
 * ⚠ It moves nothing. The draft is where a person sees which parcels are this
 * business's, decides the ones the ERP cannot place, and enters the money that
 * arrived; PostCourierStatement is what touches the books.
 *
 * ⭐ v2 (courier hub plan F.4):
 * - The original bytes are kept on the private disk, path on the statement
 *   (ChatGPT risk 7: source evidence immutable).
 * - The same bytes twice for one courier account are refused, naming the first
 *   statement (SHA-256 unique per courier money account).
 * - A row that cannot be read is kept as a row error and the rest is imported;
 *   such a statement cannot be posted until the file is fixed.
 * - CarryBee's file carries its own invoice id, which fills the reference when
 *   none is typed. Pathao's does not, so it is typed.
 */
class ImportCourierStatement
{
    /**
     * @throws ValidationException
     */
    public function handle(
        MoneyAccount $courier,
        ?string $reference,
        mixed $statementDate,
        string $contents,
        ?string $filename,
        User $createdBy,
    ): CourierStatement {
        $reference = trim((string) $reference);

        if (! $courier->isCourier() || ! $courier->is_active) {
            throw ValidationException::withMessages(['money_account_id' => 'Choose an active courier account.']);
        }

        $provider = CourierStatementFormats::provider($courier);
        $format = CourierStatementFormats::forProvider($provider);

        if ($format === null) {
            throw ValidationException::withMessages([
                'money_account_id' => "The ERP cannot read {$courier->name}'s statements yet. Link it to its courier under Settings, Couriers (Pathao and CarryBee are read).",
            ]);
        }

        $sha256 = hash('sha256', $contents);
        // ⭐ B13 (24 Oct 2026): a REVERSED statement no longer blocks importing
        // the right one again (FINAL-PLAN: reverse posting, then re-import).
        $first = CourierStatement::query()->where('money_account_id', $courier->id)->where('source_sha256', $sha256)->whereNull('reversed_at')->first();

        if ($first !== null) {
            throw ValidationException::withMessages([
                'file' => "This exact file was already imported as {$courier->name} statement {$first->statement_reference} (#{$first->id}).",
            ]);
        }

        $parsed = $format->parse($contents);

        if ($parsed->reference !== null && $reference !== '' && strcasecmp($parsed->reference, $reference) !== 0) {
            throw ValidationException::withMessages([
                'statement_reference' => "The file is invoice {$parsed->reference}, not {$reference}.",
            ]);
        }

        $reference = $reference !== '' ? $reference : (string) $parsed->reference;

        if ($reference === '') {
            throw ValidationException::withMessages(['statement_reference' => "Enter the courier's invoice id, as its panel shows it."]);
        }

        if (CourierStatement::query()->where('money_account_id', $courier->id)->where('statement_reference', $reference)->whereNull('reversed_at')->exists()) {
            throw ValidationException::withMessages([
                'statement_reference' => "{$courier->name} statement {$reference} has already been imported.",
            ]);
        }

        // ⚠ Written before the transaction, removed if it fails: a file on disk
        // with no statement is harmless, a statement pointing at no file is not.
        $path = "courier-statements/{$courier->business_id}/".now()->format('Ymd').'-'.Str::uuid().'.csv';
        Storage::disk(CourierStatement::SOURCE_DISK)->put($path, $contents);

        try {
            return DB::transaction(function () use ($courier, $provider, $format, $reference, $statementDate, $filename, $createdBy, $parsed, $sha256, $path) {
                $statement = CourierStatement::create([
                    'business_id' => $courier->business_id,
                    'money_account_id' => $courier->id,
                    'provider_code' => $provider,
                    'format' => $format->code(),
                    'statement_reference' => $reference,
                    'statement_date' => Carbon::parse($statementDate)->startOfDay(),
                    'status' => CourierStatement::DRAFT,
                    'source_filename' => $filename,
                    'source_sha256' => $sha256,
                    'source_path' => $path,
                    'row_errors' => $parsed->errors === [] ? null : $parsed->errors,
                    'created_by' => $createdBy->id,
                ]);

                foreach ($parsed->lines as $line) {
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
                        'net_fee' => $line->netFee()->toTaka(),
                        'promo_discount' => ($line->promoDiscount?->toTaka()) ?? '0',
                        'cod_fee' => $line->codFee->toTaka(),
                        'additional_charge' => $line->additionalCharge->toTaka(),
                        'final_fee' => $line->finalFee->toTaka(),
                        'compensation' => ($line->compensation?->toTaka()) ?? '0',
                        'adjustment' => ($line->adjustment?->toTaka()) ?? '0',
                        'payout' => $line->payout->toTaka(),
                        'hub_weight_grams' => $line->hubWeightGrams,
                        'delivery_status' => $line->deliveryStatus,
                        'delivered_on' => $line->deliveredOn,
                    ]);
                }

                return $statement->fresh('lines');
            });
        } catch (UniqueConstraintViolationException) {
            Storage::disk(CourierStatement::SOURCE_DISK)->delete($path);

            throw ValidationException::withMessages(['file' => 'This file or invoice was imported a moment ago by somebody else.']);
        } catch (\Throwable $e) {
            Storage::disk(CourierStatement::SOURCE_DISK)->delete($path);

            throw $e;
        }
    }
}
