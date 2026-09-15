<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Actions\DeleteAttachment;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Purchasing\Enums\CertificationType;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierCertification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * A supplier's certifications, from the supplier screen (Joy's answer 18).
 *
 * ⚠ `{certification}` is bound SCOPED to `{supplier}` in the routes, so a
 * certification id from another supplier 404s instead of being edited through
 * the wrong record.
 */
class SupplierCertificationController extends Controller
{
    private const MAX_SCAN_FILES = 4;

    public function store(Request $request, Supplier $supplier, StoreAttachment $store): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($request, $supplier, $data, $store) {
            $certification = SupplierCertification::create([
                'business_id' => $supplier->business_id,
                'supplier_id' => $supplier->id,
                'type' => $data['type'],
                'name' => $data['type'] === CertificationType::Other->value ? ($data['name'] ?? null) : null,
                'number' => $data['number'] ?? null,
                'expires_on' => $data['expires_on'] ?? null,
                'recorded_by' => $request->user()->id,
            ]);

            foreach ($request->file('scan', []) as $file) {
                $store->handle($certification, $file, SupplierCertification::SCAN, $request->user());
            }
        });

        return back()->with('success', 'Certification added.');
    }

    public function update(Request $request, Supplier $supplier, SupplierCertification $certification, StoreAttachment $store): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($request, $certification, $data, $store) {
            $certification->update([
                'type' => $data['type'],
                'name' => $data['type'] === CertificationType::Other->value ? ($data['name'] ?? null) : null,
                'number' => $data['number'] ?? null,
                'expires_on' => $data['expires_on'] ?? null,
            ]);

            // ⚠ A renewed certificate's new scan is ADDED; the old one is removed
            // only by pressing Remove on it, never silently by an edit.
            foreach ($request->file('scan', []) as $file) {
                $store->handle($certification, $file, SupplierCertification::SCAN, $request->user());
            }
        });

        return back()->with('success', 'Certification saved.');
    }

    /**
     * ⚠ Removing a certification removes its scans from the disk too. Nothing is
     * left behind that no screen can reach.
     */
    public function destroy(Supplier $supplier, SupplierCertification $certification, DeleteAttachment $delete): RedirectResponse
    {
        DB::transaction(function () use ($certification, $delete) {
            $certification->attachments()->get()->each(fn (Attachment $file) => $delete->handle($file));
            $certification->delete();
        });

        return back()->with('success', 'Certification removed.');
    }

    /** One scan of a certification, removed without removing the entry. */
    public function destroyScan(Supplier $supplier, SupplierCertification $certification, Attachment $attachment, DeleteAttachment $delete): RedirectResponse
    {
        abort_unless(
            $attachment->attachable_type === $certification->getMorphClass()
            && (int) $attachment->attachable_id === (int) $certification->id,
            404,
        );

        $delete->handle($attachment);

        return back()->with('success', 'Scan removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::enum(CertificationType::class)],
            'name' => ['nullable', 'string', 'max:120'],
            'number' => ['nullable', 'string', 'max:100'],
            'expires_on' => ['nullable', 'date'],
            'scan' => ['nullable', 'array', 'max:'.self::MAX_SCAN_FILES],
            'scan.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ]);
    }
}
