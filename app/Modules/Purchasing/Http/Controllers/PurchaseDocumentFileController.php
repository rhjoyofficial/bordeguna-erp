<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Actions\DeleteAttachment;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\SupplierBill;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ⭐ Initial plan B1 (R58, 25 Oct 2026): "File upload: supplier memo photo,
 * courier receipt, labour slip" on the goods receipt and the supplier bill,
 * through the existing attachments system (StoreAttachment / DeleteAttachment,
 * private disk, streamed by attachments.show under FileVisibility), exactly as
 * the purchase order already does.
 *
 * ⚠ A file is evidence beside the document, never part of its figures: adding
 * or removing one changes no stock, no journal and no status. Removing is
 * logged by DeleteAttachment.
 */
class PurchaseDocumentFileController extends Controller
{
    public const RULES = ['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,csv,xls,xlsx,doc,docx', 'max:5120']];

    public function storeForReceipt(Request $request, GoodsReceipt $goodsReceipt, StoreAttachment $store): RedirectResponse
    {
        return $this->store($request, $goodsReceipt, GoodsReceipt::ATTACHMENTS_COLLECTION, $store, "File attached to goods receipt #{$goodsReceipt->id}.");
    }

    public function destroyForReceipt(GoodsReceipt $goodsReceipt, Attachment $attachment, DeleteAttachment $delete): RedirectResponse
    {
        return $this->destroy($goodsReceipt, $attachment, $delete);
    }

    public function storeForBill(Request $request, SupplierBill $bill, StoreAttachment $store): RedirectResponse
    {
        return $this->store($request, $bill, SupplierBill::ATTACHMENTS_COLLECTION, $store, "File attached to bill {$bill->bill_number}.");
    }

    public function destroyForBill(SupplierBill $bill, Attachment $attachment, DeleteAttachment $delete): RedirectResponse
    {
        return $this->destroy($bill, $attachment, $delete);
    }

    /**
     * The files of a document, as its show page lists them.
     *
     * @return list<array{id: int, name: string, uploaded_by: ?string, url: string}>
     */
    public static function filesOf(Model $document): array
    {
        return $document->attachments()->with('uploadedBy:id,name')->orderBy('id')->get()->map(fn (Attachment $file) => [
            'id' => (int) $file->id,
            'name' => (string) ($file->original_name ?? 'file'),
            'uploaded_by' => $file->uploadedBy?->name,
            'url' => route('attachments.show', $file->id),
        ])->values()->all();
    }

    private function store(Request $request, Model $document, string $collection, StoreAttachment $store, string $message): RedirectResponse
    {
        $request->validate(self::RULES);

        $store->handle($document, $request->file('file'), $collection, $request->user());

        return back()->with('success', $message);
    }

    private function destroy(Model $document, Attachment $attachment, DeleteAttachment $delete): RedirectResponse
    {
        abort_unless($attachment->attachable_type === $document->getMorphClass() && (int) $attachment->attachable_id === (int) $document->getKey(), 404);

        $delete->handle($attachment, request()->user());

        return back()->with('success', 'File removed.');
    }
}
