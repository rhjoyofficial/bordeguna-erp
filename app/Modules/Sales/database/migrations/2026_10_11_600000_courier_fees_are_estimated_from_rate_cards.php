<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Courier hub slice 5 (11 Oct 2026): what a parcel will cost, said before it is
 * booked and kept, beside what the courier quoted and what it charged (ChatGPT
 * ruling 8: three separate immutable facts).
 *
 * ⚠ Rate cards are PROVIDER-WIDE like the zone bands: one Pathao card serves
 * every business booking through Pathao. Estimates are business-scoped.
 *
 * ⚠ The seeded cards come from the courier's own pages (pathao-api-notes.md,
 * carrybee-api-notes.md, 11 Oct 2026). CarryBee's is its LIST price: real
 * charges are lower, and the discount is left blank for the owner to fill
 * from calibration, never invented.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_rate_cards', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->string('version', 40);
            // Null on a draft nobody has approved yet.
            $table->date('effective_from')->nullable();
            // draft | approved | retired
            $table->string('status', 10)->default('draft');
            // A list price (CarryBee's calculator): real charges are lower.
            $table->boolean('is_list_price')->default(false);
            // published | calibration | manual | import
            $table->string('source', 20)->default('manual');
            $table->foreignId('based_on_id')->nullable()->constrained('courier_rate_cards')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'version']);
            $table->index(['provider', 'status', 'effective_from']);
        });

        Schema::create('courier_rate_card_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courier_rate_card_id')->constrained('courier_rate_cards')->cascadeOnDelete();
            // The courier's own band codes (courier_zone_bands.code).
            $table->string('from_band', 40);
            $table->string('to_band', 40);
            // normal | express | same_day
            $table->string('service', 10)->default('normal');
            // Above weight_from_g, up to and including weight_to_g (null = no top).
            $table->unsignedInteger('weight_from_g')->default(0);
            $table->unsignedInteger('weight_to_g')->nullable();
            $table->decimal('price', 12, 2);
            // Per started kilogram above this weight.
            $table->unsignedInteger('extra_per_kg_after_g')->nullable();
            $table->decimal('extra_per_kg_price', 12, 2)->nullable();
            // A scenario shown beside the estimate, never inside it.
            $table->decimal('return_fee_percent', 5, 2)->nullable();
            // Filled by the owner from calibration; null means not known.
            $table->decimal('expected_discount', 12, 2)->nullable();
            // On a calibrated draft: the clean samples behind the price, and the
            // price it replaces.
            $table->unsignedInteger('sample_count')->nullable();
            $table->decimal('previous_price', 12, 2)->nullable();
            $table->timestamps();

            $table->index(['courier_rate_card_id', 'from_band', 'to_band', 'service'], 'courier_rate_card_lines_lookup');
        });

        Schema::create('courier_fee_estimates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_id')->constrained()->restrictOnDelete();
            $table->foreignId('consignment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('courier_account_id')->nullable()->constrained('courier_accounts')->nullOnDelete();
            $table->string('provider', 40);
            // provider_quote | rate_card | historical | insufficient
            $table->string('method', 20);
            $table->foreignId('courier_rate_card_id')->nullable()->constrained('courier_rate_cards')->nullOnDelete();
            $table->string('rate_card_version', 40)->nullable();
            $table->string('from_band', 40)->nullable();
            $table->string('to_band', 40)->nullable();
            $table->string('service', 10)->default('normal');
            $table->unsignedInteger('weight_grams')->nullable();
            $table->string('weight_bracket', 20)->nullable();
            $table->decimal('cod_amount', 15, 4)->default(0);
            $table->decimal('delivery_fee', 12, 2)->nullable();
            $table->decimal('cod_fee', 12, 2)->nullable();
            $table->decimal('total', 12, 2)->nullable();
            // high | medium | low | none
            $table->string('confidence', 10);
            $table->unsignedInteger('sample_size')->nullable();
            $table->string('basis', 500);
            $table->json('inputs')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // ⚠ Written once. No updated_at: nothing may change it.
            $table->timestamp('created_at')->nullable();

            $table->index(['business_id', 'consignment_id']);
            $table->index(['business_id', 'provider', 'to_band']);
        });

        $this->seedCards();
    }

    private function seedCards(): void
    {
        $now = now();
        $card = fn (string $provider, bool $list, string $notes) => DB::table('courier_rate_cards')->insertGetId([
            'provider' => $provider,
            'version' => 'v2026-10-11',
            'effective_from' => '2026-10-11',
            'status' => 'approved',
            'is_list_price' => $list,
            'source' => 'published',
            'notes' => $notes,
            'approved_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $line = fn (int $cardId, string $from, string $to, string $service, int $wFrom, ?int $wTo, float $price, ?int $after = null, ?float $extra = null, ?float $return = null) => [
            'courier_rate_card_id' => $cardId,
            'from_band' => $from,
            'to_band' => $to,
            'service' => $service,
            'weight_from_g' => $wFrom,
            'weight_to_g' => $wTo,
            'price' => $price,
            'extra_per_kg_after_g' => $after,
            'extra_per_kg_price' => $extra,
            'return_fee_percent' => $return,
            'expected_discount' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // Pathao Pricing Plan page, standard delivery, parcel (11 Oct 2026).
        $pathao = $card('pathao', false, 'Pathao Pricing Plan page (merchant.pathao.com, 11 Oct 2026). Standard delivery, parcel. Over 2 kg +15 per kg to Inside Dhaka, +25 per kg elsewhere; Same Day +15 per kg. Return fee 50% may apply. "Same city" rows for Suburbs and Outside are seeded at the "different city" price (bands cannot tell them apart).');
        $pathaoRows = [
            // from, to, 0-500, 500-1000, 1000-2000, extra per kg after 2 kg
            ['inside_dhaka', 'inside_dhaka', 60, 70, 90, 15],
            ['inside_dhaka', 'suburbs', 80, 100, 130, 25],
            ['inside_dhaka', 'outside_dhaka', 110, 130, 170, 25],
            ['suburbs', 'suburbs', 80, 100, 130, 25],
            ['suburbs', 'inside_dhaka', 80, 100, 130, 15],
            ['suburbs', 'outside_dhaka', 110, 130, 170, 25],
            ['outside_dhaka', 'inside_dhaka', 110, 130, 170, 15],
            ['outside_dhaka', 'suburbs', 110, 130, 170, 25],
            ['outside_dhaka', 'outside_dhaka', 120, 145, 180, 25],
        ];
        $rows = [];

        foreach ($pathaoRows as [$from, $to, $a, $b, $c, $extra]) {
            $rows[] = $line($pathao, $from, $to, 'normal', 0, 500, $a, null, null, 50);
            $rows[] = $line($pathao, $from, $to, 'normal', 500, 1000, $b, null, null, 50);
            $rows[] = $line($pathao, $from, $to, 'normal', 1000, null, $c, 2000, $extra, 50);
        }

        // Same Day, Dhaka metro only.
        $rows[] = $line($pathao, 'inside_dhaka', 'inside_dhaka', 'same_day', 0, 500, 120, null, null, 50);
        $rows[] = $line($pathao, 'inside_dhaka', 'inside_dhaka', 'same_day', 500, null, 150, 2000, 15, 50);

        // CarryBee merchant calculator, from Dhaka, Normal, Parcel (11 Oct 2026).
        // ⚠ Sampled at 0.5 / 1 / 2 / 3 / 5 kg only: nothing above 5 kg.
        $carrybee = $card('carrybee', true, 'CarryBee merchant calculator LIST price, from Dhaka, Normal, Parcel, sampled at 0.5/1/2/3/5 kg (11 Oct 2026). Real charges are lower (per-parcel Discount on the invoice); expected discount left blank for the owner to fill from calibration. Nothing above 5 kg was sampled.');
        $brackets = [[0, 500], [500, 1000], [1000, 2000], [2000, 3000], [3000, 5000]];
        $carrybeeRows = [
            'dhaka' => [60, 70, 90, 110, 150],
            'gazipur_narayanganj' => [85, 100, 125, 150, 190],
            'outside_dhaka' => [105, 125, 150, 170, 220],
        ];

        foreach ($carrybeeRows as $to => $prices) {
            foreach ($brackets as $i => [$wFrom, $wTo]) {
                $rows[] = $line($carrybee, 'dhaka', $to, 'normal', $wFrom, $wTo, $prices[$i]);
            }
        }

        DB::table('courier_rate_card_lines')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_fee_estimates');
        Schema::dropIfExists('courier_rate_card_lines');
        Schema::dropIfExists('courier_rate_cards');
    }
};
