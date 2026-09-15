<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Support\IdentityDocuments;
use App\Modules\Sales\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A customer's trade licence and NID scans, from the customer screen (plan D11).
 * The rules live in IdentityDocuments; the stream's gate lives on the model.
 */
class CustomerDocumentController extends Controller
{
    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $request->validate(IdentityDocuments::rules());

        IdentityDocuments::store($customer, $request->file('documents', []), $request->user());

        return back()->with('success', 'Document saved.');
    }

    public function destroy(Request $request, Customer $customer, Attachment $attachment): RedirectResponse
    {
        IdentityDocuments::remove($customer, $attachment, $request->user());

        return back()->with('success', 'Document removed.');
    }
}
