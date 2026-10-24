<?php

namespace App\Modules\Assets\Console\Commands;

use App\Modules\Assets\Enums\AssetMovementStatus;
use App\Modules\Assets\Enums\AssetTracking;
use App\Modules\Assets\Models\AssetHolding;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\AssetMovementLine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prove the holdings of every counted group against its movements (asset
 * management slice A2).
 *
 * What the movements can prove: how many pieces each EMPLOYEE and each OUTSIDE
 * PARTY holds (they only ever receive by an issue or a send-out and give back
 * by a return or a receive-back), and therefore how many are at the business's
 * places in total (the group's count less what people hold). The split of those
 * between places is not rebuilt: a group's count is corrected by hand and its
 * home moved by hand, neither of which is a movement.
 *
 * ⚠ `--check` (the default) only REPORTS drift. `--write` replaces the table
 * with the proven rows, the place total on the group's own place.
 */
class RebuildAssetHoldings extends Command
{
    protected $signature = 'assets:rebuild-holdings {--write : Replace the holdings table with the proven rows}';

    protected $description = 'Prove what people hold of every counted group against the movements, and report or fix drift';

    public function handle(): int
    {
        $drift = 0;

        foreach (AssetItem::query()->withoutGlobalScopes()->whereNull('deleted_at')->where('status', '!=', 'disposed')->where('tracking', AssetTracking::Quantity->value)->orderBy('id')->get() as $item) {
            $people = [];
            $holders = [];

            $lines = AssetMovementLine::query()->where('asset_item_id', $item->id)
                ->join('asset_movements', 'asset_movements.id', '=', 'asset_movement_lines.asset_movement_id')
                ->orderBy('asset_movements.id')
                ->get(['asset_movement_lines.quantity', 'asset_movements.status', 'asset_movements.from_employee_id', 'asset_movements.from_party_id', 'asset_movements.to_employee_id', 'asset_movements.to_party_id']);

            foreach ($lines as $line) {
                if ($line->from_employee_id !== null || $line->from_party_id !== null) {
                    $key = AssetHolding::keyFor(null, null, $line->from_employee_id, $line->from_party_id);
                    $people[$key] = ($people[$key] ?? 0) - $line->quantity;
                    $holders[$key] = [$line->from_employee_id, $line->from_party_id];
                }

                if ($line->status === AssetMovementStatus::Completed->value && ($line->to_employee_id !== null || $line->to_party_id !== null)) {
                    $key = AssetHolding::keyFor(null, null, $line->to_employee_id, $line->to_party_id);
                    $people[$key] = ($people[$key] ?? 0) + $line->quantity;
                    $holders[$key] = [$line->to_employee_id, $line->to_party_id];
                }
            }

            $people = array_filter($people, fn (int $n) => $n !== 0);
            ksort($people);
            $placeTotal = $item->quantity - array_sum($people);

            $actual = AssetHolding::query()->withoutGlobalScopes()->where('asset_item_id', $item->id)->pluck('quantity', 'holder_key')->all();
            $actualPeople = array_filter($actual, fn (int $n, string $key) => $key[0] === 'e' || $key[0] === 'p', ARRAY_FILTER_USE_BOTH);
            ksort($actualPeople);
            $actualPlaces = array_sum(array_filter($actual, fn (int $n, string $key) => $key[0] === 'o' || $key[0] === 'w', ARRAY_FILTER_USE_BOTH));

            if ($people === $actualPeople && $placeTotal === $actualPlaces) {
                continue;
            }

            $drift++;
            $this->warn(sprintf('%s: holdings say people %s and %d at places; movements say people %s and %d at places',
                $item->tag, json_encode($actualPeople), $actualPlaces, json_encode($people), $placeTotal));

            if ($this->option('write')) {
                DB::transaction(function () use ($item, $people, $holders, $placeTotal) {
                    AssetHolding::query()->withoutGlobalScopes()->where('asset_item_id', $item->id)->delete();

                    foreach ($people as $key => $quantity) {
                        [$employeeId, $partyId] = $holders[$key];
                        AssetHolding::create([
                            'business_id' => $item->business_id, 'asset_item_id' => $item->id,
                            'employee_id' => $employeeId, 'party_id' => $partyId, 'holder_key' => $key, 'quantity' => $quantity,
                        ]);
                    }

                    if ($placeTotal > 0) {
                        AssetHolding::create([
                            'business_id' => $item->business_id, 'asset_item_id' => $item->id,
                            'office_id' => $item->office_id, 'warehouse_id' => $item->warehouse_id,
                            'holder_key' => AssetHolding::keyFor($item->office_id, $item->warehouse_id, null, null), 'quantity' => $placeTotal,
                        ]);
                    }
                });

                $this->line("  {$item->tag}: rewritten from the movements.");
            }
        }

        $this->line($drift === 0 ? 'Every counted group agrees with its movements.' : "{$drift} group(s) drifted.");

        return $drift === 0 || $this->option('write') ? self::SUCCESS : self::FAILURE;
    }
}
