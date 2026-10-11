<?php

namespace App\Modules\Sales\Couriers\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Couriers\Fees\RateCardBook;
use App\Modules\Sales\Couriers\Models\CourierRateCard;
use App\Modules\Sales\Couriers\Models\CourierRateCardLine;
use App\Modules\Sales\Couriers\Models\CourierZoneBand;
use App\Modules\Sales\Couriers\Providers\CourierProviderRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settings > Couriers > Rate cards (courier hub slice 5): every version of what
 * each courier charges, the drafts a Manager prepares (typed, from a CSV, or
 * proposed by calibration), and the Owner's approval with an effective date.
 *
 * ⚠ Provider-wide data, no business scope, like the zone bands. Gated by
 * `couriers.ratecards.propose`; approve and retire also need
 * `couriers.ratecards.approve` (Owner).
 */
class CourierRateCardController extends Controller
{
    public function index(Request $request, CourierProviderRegistry $registry): Response
    {
        $providers = collect($registry->connectedCodes())
            ->map(fn (string $code) => ['code' => $code, 'name' => $registry->get($code)->name()])
            ->values();
        $codes = $providers->pluck('code')->all();
        $provider = in_array($request->query('provider'), $codes, true) ? (string) $request->query('provider') : (string) ($codes[0] ?? 'pathao');

        $cards = CourierRateCard::query()->where('provider', $provider)
            ->with(['approver:id,name', 'creator:id,name', 'basedOn:id,version'])
            ->withCount('lines')
            ->orderByRaw("CASE WHEN status = 'draft' THEN 0 WHEN status = 'approved' THEN 1 ELSE 2 END")
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->get();

        $inForce = CourierRateCard::effectiveFor($provider);
        $selectedId = (int) $request->query('card', 0);
        $selected = $cards->firstWhere('id', $selectedId) ?? $cards->firstWhere('id', $inForce?->id) ?? $cards->first();
        $selected?->load('lines');

        return Inertia::render('settings/courier-rate-cards', [
            'providers' => $providers,
            'provider' => $provider,
            'cards' => $cards->map(fn (CourierRateCard $c) => [
                'id' => $c->id,
                'version' => $c->version,
                'status' => $c->status,
                'effective_from' => $c->effective_from?->toDateString(),
                'is_list_price' => $c->is_list_price,
                'source' => $c->source,
                'based_on' => $c->basedOn?->version,
                'lines' => $c->lines_count,
                'approved_by' => $c->approver?->name,
                'approved_at' => $c->approved_at?->timezone('Asia/Dhaka')->format('Y-m-d H:i'),
                'created_by' => $c->creator?->name,
                'in_force' => $c->id === $inForce?->id,
            ])->values(),
            'card' => $selected === null ? null : [
                'id' => $selected->id,
                'version' => $selected->version,
                'status' => $selected->status,
                'effective_from' => $selected->effective_from?->toDateString(),
                'is_list_price' => $selected->is_list_price,
                'source' => $selected->source,
                'notes' => $selected->notes,
                'in_force' => $selected->id === $inForce?->id,
                'lines' => $selected->lines->map(fn (CourierRateCardLine $l) => [
                    'id' => $l->id,
                    'from_band' => $l->from_band,
                    'to_band' => $l->to_band,
                    'service' => $l->service,
                    'weight_from_g' => $l->weight_from_g,
                    'weight_to_g' => $l->weight_to_g,
                    'price' => $l->price,
                    'extra_per_kg_after_g' => $l->extra_per_kg_after_g,
                    'extra_per_kg_price' => $l->extra_per_kg_price,
                    'return_fee_percent' => $l->return_fee_percent,
                    'expected_discount' => $l->expected_discount,
                    'sample_count' => $l->sample_count,
                    'previous_price' => $l->previous_price,
                ])->values(),
            ],
            'bands' => CourierZoneBand::query()->where('provider', $provider)->orderBy('sort_order')->get(['code', 'name']),
            'services' => CourierRateCardLine::SERVICES,
            'min_samples' => (int) config('couriers.min_samples', 10),
            'today' => now('Asia/Dhaka')->toDateString(),
            'can_approve' => $request->user()->can('couriers.ratecards.approve'),
        ]);
    }

    public function store(Request $request, RateCardBook $book, CourierProviderRegistry $registry): RedirectResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in($registry->connectedCodes())],
            'based_on_id' => ['nullable', 'integer'],
            'version' => ['nullable', 'string', 'max:36'],
        ]);

        $base = isset($data['based_on_id']) ? CourierRateCard::query()->findOrFail($data['based_on_id']) : null;
        $card = $book->newDraft($data['provider'], $base, $data['version'] ?? null, $request->user());

        return to_route('couriers.ratecards.index', ['provider' => $card->provider, 'card' => $card->id])
            ->with('success', "Draft {$card->version} made. Edit its prices, then ask the Owner to approve it.");
    }

    public function update(Request $request, CourierRateCard $rateCard, RateCardBook $book): RedirectResponse
    {
        $data = $request->validate([
            'version' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_list_price' => ['boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
        ]);

        $book->saveDraft($rateCard, array_values($data['lines']), [
            'version' => $data['version'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_list_price' => (bool) ($data['is_list_price'] ?? $rateCard->is_list_price),
        ]);

        return back()->with('success', "Draft {$rateCard->fresh()->version} saved.");
    }

    public function import(Request $request, CourierRateCard $rateCard, RateCardBook $book): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:512']]);

        $card = $book->importCsv($rateCard, (string) $request->file('file')->getRealPath());

        return back()->with('success', "{$card->lines->count()} prices read into draft {$card->version}.");
    }

    public function export(CourierRateCard $rateCard, RateCardBook $book): StreamedResponse
    {
        $rateCard->load('lines');
        $rows = $book->csvRows($rateCard);
        $name = sprintf('rate-card-%s-%s.csv', $rateCard->provider, preg_replace('/[^A-Za-z0-9._-]/', '-', $rateCard->version));

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function propose(Request $request, RateCardBook $book, CourierProviderRegistry $registry): RedirectResponse
    {
        $data = $request->validate(['provider' => ['required', 'string', Rule::in($registry->connectedCodes())]]);

        $card = $book->propose($data['provider'], $request->user());

        return to_route('couriers.ratecards.index', ['provider' => $card->provider, 'card' => $card->id])
            ->with('success', "Calibrated draft {$card->version} proposed. Compare before and after, then the Owner approves it with a date.");
    }

    public function approve(Request $request, CourierRateCard $rateCard, RateCardBook $book): RedirectResponse
    {
        $data = $request->validate(['effective_from' => ['required', 'date_format:Y-m-d']]);

        $book->approve($rateCard, $data['effective_from'], $request->user());

        return back()->with('success', "{$rateCard->version} approved. Estimates use it from {$data['effective_from']}.");
    }

    public function retire(CourierRateCard $rateCard, RateCardBook $book): RedirectResponse
    {
        $book->retire($rateCard);

        return back()->with('success', "{$rateCard->version} retired. Estimates already made keep it.");
    }

    public function destroy(CourierRateCard $rateCard, RateCardBook $book): RedirectResponse
    {
        $provider = $rateCard->provider;
        $version = $rateCard->version;
        $book->discard($rateCard);

        return to_route('couriers.ratecards.index', ['provider' => $provider])->with('success', "Draft {$version} discarded.");
    }
}
