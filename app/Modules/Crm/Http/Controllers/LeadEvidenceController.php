<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Enums\LeadType;
use App\Modules\Crm\Enums\SampleResult;
use App\Modules\Crm\Models\DistributorInspection;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\SupplierLeadSample;
use App\Modules\Foundation\Actions\StoreAttachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What was SEEN at a distributor and what a supplier's sample turned out to be
 * (CRM lead slice 2c; Joy's answers 15 and 17, plan D13).
 *
 * ⚠ Both are append-only. There is no edit or delete route, and the models
 * refuse it as well. A later visit is another inspection; a re-test is another
 * sample.
 *
 * ⚠⚠ `{lead}` and `{sample}` are bound through their visibility scopes, so a
 * lead outside the reader's team 404s here exactly as its timeline does.
 */
class LeadEvidenceController extends Controller
{
    /** Phone photos, re-encoded smaller by StoreAttachment before they are kept. */
    private const MAX_PHOTOS = 6;

    public function storeInspection(Request $request, Lead $lead, StoreAttachment $store): RedirectResponse
    {
        $this->guardType($lead, LeadType::Distributor, 'Only a distributor lead can be inspected.');

        $data = $request->validate([
            'inspected_on' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'photos' => ['nullable', 'array', 'max:'.self::MAX_PHOTOS],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ], [
            'inspected_on.before_or_equal' => 'That is in the future. An inspection records a visit that happened.',
        ]);

        // ⚠ An inspection with nothing in it says nobody saw anything, which is
        // not what the person meant to record.
        if (blank($data['notes'] ?? null) && $request->file('photos', []) === []) {
            throw ValidationException::withMessages(['notes' => 'Write what you saw, or add a photo.']);
        }

        DB::transaction(function () use ($request, $lead, $data, $store) {
            $inspection = DistributorInspection::create([
                'business_id' => $lead->business_id,
                'lead_id' => $lead->id,
                'inspected_on' => $data['inspected_on'],
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $request->user()->id,
            ]);

            foreach ($request->file('photos', []) as $photo) {
                $store->handle($inspection, $photo, DistributorInspection::PHOTOS, $request->user());
            }
        });

        return back()->with('success', 'Inspection recorded.');
    }

    public function storeSample(Request $request, Lead $lead): RedirectResponse
    {
        $this->guardType($lead, LeadType::Supplier, 'Samples are recorded on a supplier lead.');

        $data = $request->validate([
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'item' => ['required', 'string', 'max:160'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [
            'received_on.before_or_equal' => 'That is in the future. Record a sample once it has arrived.',
        ]);

        SupplierLeadSample::create([
            'business_id' => $lead->business_id,
            'lead_id' => $lead->id,
            'received_on' => $data['received_on'],
            'item' => $data['item'],
            'note' => $data['note'] ?? null,
            'recorded_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Sample recorded.');
    }

    /**
     * The Owner's verdict, written once.
     *
     * ⚠⚠ Locked and re-read inside the transaction: two verdicts submitted at
     * once must not both find the sample unjudged.
     */
    public function judgeSample(Request $request, SupplierLeadSample $sample): RedirectResponse
    {
        $data = $request->validate([
            'result' => ['required', Rule::enum(SampleResult::class)],
            'result_note' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $sample, $data) {
            $locked = SupplierLeadSample::query()->whereKey($sample->id)->lockForUpdate()->firstOrFail();

            if ($locked->isJudged()) {
                throw ValidationException::withMessages([
                    'result' => 'This sample has already been judged. A re-test is a new sample.',
                ]);
            }

            $locked->forceFill([
                'result' => $data['result'],
                'result_note' => $data['result_note'] ?? null,
                'judged_by' => $request->user()->id,
                'judged_at' => now(),
            ])->save();
        });

        return back()->with('success', 'Verdict recorded.');
    }

    /**
     * ⚠ A lead whose type is corrected later keeps what was recorded under the
     * old type; this only stops a NEW record landing on the wrong kind of lead.
     */
    private function guardType(Lead $lead, LeadType $type, string $message): void
    {
        if ($lead->lead_type !== $type) {
            throw ValidationException::withMessages(['lead_type' => $message]);
        }
    }
}
