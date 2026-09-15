<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Support\IdentityDocuments;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A supplier's trade licence and NID scans, from the supplier screen (plan D11).
 * The rules live in IdentityDocuments; the stream's gate lives on the model.
 */
class SupplierDocumentController extends Controller
{
    public function store(Request $request, Supplier $supplier): RedirectResponse
    {
        $request->validate(IdentityDocuments::rules());

        IdentityDocuments::store($supplier, $request->file('documents', []), $request->user());

        return back()->with('success', 'Document saved.');
    }

    public function destroy(Request $request, Supplier $supplier, Attachment $attachment): RedirectResponse
    {
        IdentityDocuments::remove($supplier, $attachment, $request->user());

        return back()->with('success', 'Document removed.');
    }
}
