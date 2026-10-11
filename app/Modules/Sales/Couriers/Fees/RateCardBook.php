<?php

namespace App\Modules\Sales\Couriers\Fees;

use App\Models\User;
use App\Modules\Sales\Couriers\Models\CourierRateCard;
use App\Modules\Sales\Couriers\Models\CourierRateCardLine;
use App\Modules\Sales\Couriers\Models\CourierZoneBand;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Everything that changes a rate card: a new draft, its lines (typed or from a
 * CSV), a calibrated proposal, approval with an effective date, retirement.
 *
 * ⚠⚠ ONLY A DRAFT IS EVER EDITED. An approved card is a record of the prices
 * estimates were made with; the model refuses any write to its lines, and
 * every method here checks first so the person gets a sentence, not an error.
 *
 * ⚠ Calibration PROPOSES (ruling 9). Ten clean parcels is the minimum to put a
 * new price forward, not proof of it: the Owner reads the before/after with
 * the sample size and approves with an effective date, or does not.
 */
class RateCardBook
{
    public const CSV_COLUMNS = [
        'from_band', 'to_band', 'service', 'weight_from_g', 'weight_to_g', 'price',
        'extra_per_kg_after_g', 'extra_per_kg_price', 'return_fee_percent', 'expected_discount',
    ];

    public function __construct(private readonly CourierFeeSamples $samples) {}

    /** A new draft: a copy of `$base`, or empty. */
    public function newDraft(string $provider, ?CourierRateCard $base, ?string $version, User $by): CourierRateCard
    {
        if ($base !== null && $base->provider !== $provider) {
            throw ValidationException::withMessages(['based_on_id' => 'That card is for another courier.']);
        }

        return DB::transaction(function () use ($provider, $base, $version, $by) {
            $card = CourierRateCard::create([
                'provider' => $provider,
                'version' => $this->versionLabel($provider, $version),
                'status' => CourierRateCard::DRAFT,
                'is_list_price' => $base?->is_list_price ?? false,
                'source' => CourierRateCard::SOURCE_MANUAL,
                'based_on_id' => $base?->id,
                'notes' => $base === null ? null : "Copied from {$base->version}.",
                'created_by' => $by->id,
            ]);

            foreach ($base?->lines ?? [] as $line) {
                $card->lines()->create($line->only(self::CSV_COLUMNS));
            }

            return $card;
        });
    }

    /**
     * Replaces a draft's lines and its header fields.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array{version?: ?string, notes?: ?string, is_list_price?: bool}  $header
     */
    public function saveDraft(CourierRateCard $card, array $lines, array $header = [], ?string $source = null): CourierRateCard
    {
        $this->assertDraft($card);
        $clean = $this->validateLines($card->provider, $lines);

        if (filled($header['version'] ?? null) && $header['version'] !== $card->version) {
            $taken = CourierRateCard::query()->where('provider', (string) $card->provider)
                ->where('version', (string) $header['version'])->whereKeyNot($card->id)->exists();

            if ($taken) {
                throw ValidationException::withMessages(['version' => 'Another card of this courier already has that version.']);
            }
        }

        return DB::transaction(function () use ($card, $clean, $header, $source) {
            $card->lines()->get()->each->delete();

            foreach ($clean as $line) {
                $card->lines()->create($line);
            }

            $card->fill(array_filter([
                'version' => filled($header['version'] ?? null) ? mb_substr((string) $header['version'], 0, 40) : null,
                'source' => $source,
            ], fn ($v) => $v !== null));

            if (array_key_exists('notes', $header)) {
                $card->notes = $header['notes'];
            }

            if (array_key_exists('is_list_price', $header)) {
                $card->is_list_price = (bool) $header['is_list_price'];
            }

            $card->save();

            return $card->fresh('lines');
        });
    }

    /**
     * A CSV with the CSV_COLUMNS header into a draft, replacing its lines.
     */
    public function importCsv(CourierRateCard $card, string $path): CourierRateCard
    {
        $this->assertDraft($card);
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'The file could not be read.']);
        }

        $header = fgetcsv($handle);
        $header = is_array($header) ? array_map(fn ($h) => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header) : [];

        if (array_diff(['from_band', 'to_band', 'weight_from_g', 'price'], $header) !== []) {
            fclose($handle);

            throw ValidationException::withMessages(['file' => 'The first row must name the columns: '.implode(', ', self::CSV_COLUMNS).'.']);
        }

        $lines = [];

        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $values = [];

            foreach ($header as $i => $column) {
                if (in_array($column, self::CSV_COLUMNS, true)) {
                    $value = trim((string) ($row[$i] ?? ''));
                    $values[$column] = $value === '' ? null : $value;
                }
            }

            $lines[] = $values;
        }

        fclose($handle);

        if ($lines === []) {
            throw ValidationException::withMessages(['file' => 'The file has no price rows.']);
        }

        return $this->saveDraft($card, $lines, [], CourierRateCard::SOURCE_IMPORT);
    }

    /**
     * @return list<list<string>>
     */
    public function csvRows(CourierRateCard $card): array
    {
        $rows = [self::CSV_COLUMNS];

        foreach ($card->lines as $line) {
            $rows[] = array_map(fn (string $column) => $line->{$column} === null ? '' : (string) $line->{$column}, self::CSV_COLUMNS);
        }

        return $rows;
    }

    /**
     * A calibrated DRAFT from the card in force: for each line, the median net
     * fee of at least `min_samples` clean parcels in its band, service and base
     * weight range; otherwise the old price, kept. On a list-price card the
     * list price stays and the difference becomes the expected discount.
     */
    public function propose(string $provider, User $by): CourierRateCard
    {
        $base = CourierRateCard::effectiveFor($provider);

        if ($base === null) {
            throw ValidationException::withMessages(['provider' => 'There is no approved rate card in force for this courier to calibrate.']);
        }

        $minimum = (int) config('couriers.min_samples', 10);
        $pickup = CourierZoneBand::for($provider, (string) config('couriers.pickup_district', 'Dhaka'), (string) config('couriers.pickup_thana', ''))?->code;
        $samples = $this->samples->clean($provider);
        $proposed = [];
        $changed = 0;

        foreach ($base->lines as $line) {
            $matching = $samples->filter(fn (array $row) => ($row['from_band'] ?? $pickup) === $line->from_band
                && $row['to_band'] === $line->to_band
                && $row['service'] === $line->service
                && $line->covers((int) $row['declared_grams'])
                && $line->isBaseWeight((int) $row['declared_grams']));

            $count = $matching->count();
            $before = round((float) $line->price - (float) $line->expected_discount, 2);
            $values = $line->only(self::CSV_COLUMNS);
            $values['sample_count'] = $count;
            $values['previous_price'] = $before;

            if ($count >= $minimum) {
                $median = (float) FeeBrackets::median($matching->map(fn (array $row) => (float) $row['actual']['delivery'])->values()->all());

                if ($base->is_list_price && $median <= (float) $line->price) {
                    $values['expected_discount'] = round((float) $line->price - $median, 2);
                } else {
                    $values['price'] = $median;
                    $values['expected_discount'] = null;
                }

                $changed++;
            }

            $proposed[] = $values;
        }

        if ($changed === 0) {
            throw ValidationException::withMessages(['provider' => "No line of {$base->version} has {$minimum} clean parcels yet. Nothing to propose."]);
        }

        return DB::transaction(function () use ($provider, $base, $proposed, $changed, $minimum, $by) {
            $card = CourierRateCard::create([
                'provider' => $provider,
                'version' => $this->versionLabel($provider, null),
                'status' => CourierRateCard::DRAFT,
                'is_list_price' => $base->is_list_price,
                'source' => CourierRateCard::SOURCE_CALIBRATION,
                'based_on_id' => $base->id,
                'notes' => "Calibrated from {$base->version}: {$changed} line(s) moved to the median of at least {$minimum} clean parcels; the rest keep the old price.",
                'created_by' => $by->id,
            ]);

            foreach ($proposed as $values) {
                $card->lines()->create($values);
            }

            return $card;
        });
    }

    /**
     * Makes a draft the price the ERP estimates with from `$effectiveFrom`
     * (today or later). Earlier estimates keep the version they used.
     */
    public function approve(CourierRateCard $card, string $effectiveFrom, User $by): CourierRateCard
    {
        $this->assertDraft($card);

        $validator = Validator::make(['effective_from' => $effectiveFrom], ['effective_from' => ['required', 'date_format:Y-m-d']]);

        if ($validator->fails()) {
            throw ValidationException::withMessages(['effective_from' => 'Choose the date it takes effect.']);
        }

        if (Carbon::parse($effectiveFrom, 'Asia/Dhaka')->lt(now('Asia/Dhaka')->startOfDay())) {
            throw ValidationException::withMessages(['effective_from' => 'A card takes effect today or later. Past estimates keep the card they used.']);
        }

        if (! $card->lines()->exists()) {
            throw ValidationException::withMessages(['effective_from' => 'A card with no prices cannot be approved.']);
        }

        $card->forceFill([
            'status' => CourierRateCard::APPROVED,
            'effective_from' => $effectiveFrom,
            'approved_by' => $by->id,
            'approved_at' => now(),
        ])->save();

        return $card;
    }

    public function retire(CourierRateCard $card): CourierRateCard
    {
        if ($card->status !== CourierRateCard::APPROVED) {
            throw ValidationException::withMessages(['card' => 'Only an approved card can be retired.']);
        }

        $card->forceFill(['status' => CourierRateCard::RETIRED])->save();

        return $card;
    }

    public function discard(CourierRateCard $card): void
    {
        $this->assertDraft($card);

        DB::transaction(function () use ($card) {
            $card->lines()->get()->each->delete();
            $card->delete();
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    public function validateLines(string $provider, array $lines): array
    {
        $bands = CourierZoneBand::query()->where('provider', (string) $provider)->pluck('code')->map(fn ($c) => (string) $c)->all();

        $validator = Validator::make(['lines' => $lines], [
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.from_band' => ['required', 'string', 'in:'.implode(',', $bands)],
            'lines.*.to_band' => ['required', 'string', 'in:'.implode(',', $bands)],
            'lines.*.service' => ['nullable', 'string', 'in:'.implode(',', CourierRateCardLine::SERVICES)],
            'lines.*.weight_from_g' => ['required', 'integer', 'min:0', 'max:100000'],
            'lines.*.weight_to_g' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'lines.*.price' => ['required', 'numeric', 'min:0', 'max:100000'],
            'lines.*.extra_per_kg_after_g' => ['nullable', 'integer', 'min:0', 'max:100000', 'required_with:lines.*.extra_per_kg_price'],
            'lines.*.extra_per_kg_price' => ['nullable', 'numeric', 'min:0', 'max:10000', 'required_with:lines.*.extra_per_kg_after_g'],
            'lines.*.return_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.expected_discount' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ], [
            'lines.*.from_band.in' => 'Line :position: the "from" band is not one of this courier\'s bands.',
            'lines.*.to_band.in' => 'Line :position: the "to" band is not one of this courier\'s bands.',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $clean = [];
        $errors = [];

        foreach (array_values($lines) as $i => $line) {
            $from = (int) $line['weight_from_g'];
            $to = isset($line['weight_to_g']) && $line['weight_to_g'] !== '' && $line['weight_to_g'] !== null ? (int) $line['weight_to_g'] : null;
            $price = round((float) $line['price'], 2);
            $discount = isset($line['expected_discount']) && $line['expected_discount'] !== '' && $line['expected_discount'] !== null ? round((float) $line['expected_discount'], 2) : null;
            $n = $i + 1;

            if ($to !== null && $to <= $from) {
                $errors["lines.{$i}.weight_to_g"] = "Line {$n}: the top weight must be above the bottom weight.";
            }

            if ($discount !== null && $discount > $price) {
                $errors["lines.{$i}.expected_discount"] = "Line {$n}: the expected discount is more than the price.";
            }

            $clean[] = [
                'from_band' => (string) $line['from_band'],
                'to_band' => (string) $line['to_band'],
                'service' => (string) ($line['service'] ?? 'normal') ?: 'normal',
                'weight_from_g' => $from,
                'weight_to_g' => $to,
                'price' => $price,
                'extra_per_kg_after_g' => isset($line['extra_per_kg_after_g']) && $line['extra_per_kg_after_g'] !== '' && $line['extra_per_kg_after_g'] !== null ? (int) $line['extra_per_kg_after_g'] : null,
                'extra_per_kg_price' => isset($line['extra_per_kg_price']) && $line['extra_per_kg_price'] !== '' && $line['extra_per_kg_price'] !== null ? round((float) $line['extra_per_kg_price'], 2) : null,
                'return_fee_percent' => isset($line['return_fee_percent']) && $line['return_fee_percent'] !== '' && $line['return_fee_percent'] !== null ? round((float) $line['return_fee_percent'], 2) : null,
                'expected_discount' => $discount,
                'sample_count' => isset($line['sample_count']) && is_numeric($line['sample_count']) ? (int) $line['sample_count'] : null,
                'previous_price' => isset($line['previous_price']) && is_numeric($line['previous_price']) ? round((float) $line['previous_price'], 2) : null,
            ];
        }

        // Two brackets of one route and service may not overlap: a weight
        // would have two prices.
        foreach (collect($clean)->groupBy(fn ($l) => $l['from_band'].'|'.$l['to_band'].'|'.$l['service']) as $group) {
            $sorted = $group->sortBy('weight_from_g')->values();

            for ($i = 1; $i < $sorted->count(); $i++) {
                $previous = $sorted[$i - 1];

                if ($previous['weight_to_g'] === null || $previous['weight_to_g'] > $sorted[$i]['weight_from_g']) {
                    $errors['lines'] = sprintf('Two lines for %s -> %s (%s) overlap at %d g.', $previous['from_band'], $previous['to_band'], $previous['service'], $sorted[$i]['weight_from_g']);
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    private function assertDraft(CourierRateCard $card): void
    {
        if (! $card->isDraft()) {
            throw ValidationException::withMessages(['card' => "{$card->version} is {$card->status}. Only a draft changes; make a new version."]);
        }
    }

    private function versionLabel(string $provider, ?string $wanted): string
    {
        $base = filled($wanted) ? mb_substr(trim((string) $wanted), 0, 36) : 'v'.now('Asia/Dhaka')->toDateString();
        $label = $base;
        $n = 2;

        while (CourierRateCard::query()->where('provider', (string) $provider)->where('version', (string) $label)->exists()) {
            if (filled($wanted)) {
                throw ValidationException::withMessages(['version' => 'Another card of this courier already has that version.']);
            }

            $label = $base.'-'.$n++;
        }

        return $label;
    }
}
