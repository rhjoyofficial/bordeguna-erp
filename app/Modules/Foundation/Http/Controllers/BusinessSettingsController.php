<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company profile for the current business — the name, contact details, and
 * the currency / timezone / fiscal-year settings that payroll and reporting
 * read later. Operates on the business resolved into the request context, so
 * switching business switches which profile is edited.
 */
class BusinessSettingsController extends Controller
{
    private const CURRENCIES = ['BDT', 'USD', 'EUR', 'GBP', 'INR', 'AED', 'SAR'];

    private const TIMEZONES = ['Asia/Dhaka', 'Asia/Kolkata', 'Asia/Karachi', 'Asia/Dubai', 'Asia/Riyadh', 'UTC'];

    public function edit(): Response
    {
        $this->authorize('foundation.organization.manage');

        $business = Business::findOrFail(app(BusinessContext::class)->id());

        return Inertia::render('settings/business', [
            'business' => $business->only([
                'id', 'name', 'legal_name', 'address', 'phone', 'email', 'currency', 'timezone', 'fiscal_year_start_month',
            ]),
            'currencies' => self::CURRENCIES,
            'timezones' => self::TIMEZONES,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('foundation.organization.manage');

        $business = Business::findOrFail(app(BusinessContext::class)->id());

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'currency' => ['required', Rule::in(self::CURRENCIES)],
            'timezone' => ['required', 'timezone'],
            'fiscal_year_start_month' => ['required', 'integer', 'between:1,12'],
        ]);

        $business->update($data);

        return back()->with('success', 'Business settings saved.');
    }
}
