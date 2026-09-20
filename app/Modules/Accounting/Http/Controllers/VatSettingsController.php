<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\TaxClass;
use App\Modules\Accounting\Models\TaxRate;
use App\Modules\Accounting\Support\TaxCalculator;
use App\Modules\Accounting\Support\VatRegime;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accounting > VAT settings: the switch, and everything it needs to be true.
 *
 * ⚠⚠ C-VAT-7: turning VAT on changes what every future invoice charges a
 * customer. It is an Owner-only act (`accounting.chart.manage`), it takes an
 * EFFECTIVE DATE rather than happening "now", and it is written to the audit
 * log with who did it and from when.
 *
 * ⚠⚠ THE SWITCH REFUSES TO LIE. It will not turn on with no standard rate in
 * force (the first invoice would be refused), and it will not take an effective
 * date on or before a sale or a bill that was already recorded without VAT -
 * that would leave a month that is half taxed and half not, with no document
 * saying which half is which.
 *
 * Rates are effective-dated and never edited (C-VAT-3): a new row supersedes.
 */
class VatSettingsController extends Controller
{
    /** The class that carries the turnover-tax percentage. Not a VAT class. */
    public const TURNOVER = 'TURNOVER';

    public function index(): Response
    {
        $business = $this->business();
        $today = Carbon::today();

        $classes = TaxClass::query()
            ->with(['rates' => fn ($query) => $query->orderByDesc('effective_from')->orderByDesc('id')])
            ->orderBy('id')
            ->get();

        return Inertia::render('accounting/vat-settings/index', [
            'settings' => [
                'regime' => $business->vat_regime ?? VatRegime::NotRegistered->value,
                'vat_enabled' => (bool) $business->vat_enabled,
                'applies_today' => $business->vatAppliesOn(Carbon::today()),
                'vat_effective_from' => $business->vat_effective_from?->toDateString(),
                'vat_bin' => $business->vat_bin,
                'prices_include_tax' => (bool) $business->prices_include_tax,
                'delivery_tax_class_id' => $business->delivery_tax_class_id,
            ],
            'regimes' => collect(VatRegime::cases())->map(fn (VatRegime $regime) => [
                'value' => $regime->value,
                'label' => $regime->label(),
                'help' => $regime->help(),
            ])->all(),
            'classes' => $classes->map(fn (TaxClass $class) => [
                'id' => $class->id,
                'code' => $class->code,
                'name' => $class->name,
                'is_turnover' => $class->code === self::TURNOVER,
                'fixed_zero' => in_array($class->code, [TaxCalculator::ZERO, TaxCalculator::EXEMPT], true),
                'current_rate' => $class->rateOn($today)?->label(),
                'rates' => $class->rates->map(fn (TaxRate $rate) => [
                    'id' => $rate->id,
                    'rate' => $rate->label(),
                    'effective_from' => $rate->effective_from->toDateString(),
                    'note' => $rate->note,
                ])->all(),
            ])->all(),
            'documents' => [
                'taxed_invoices' => SalesInvoice::query()->where('tax_total', '>', 0)->count(),
                'taxed_bills' => SupplierBill::query()->where('tax_total', '>', 0)->count(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $business = $this->business();

        $data = $request->validate([
            'regime' => ['required', Rule::enum(VatRegime::class)],
            'vat_effective_from' => ['nullable', 'date', 'required_unless:regime,'.VatRegime::NotRegistered->value],
            'vat_bin' => ['nullable', 'string', 'max:40'],
            'prices_include_tax' => ['required', 'boolean'],
            'delivery_tax_class_id' => [
                'nullable', 'integer',
                Rule::exists('tax_classes', 'id')->where('business_id', $business->id),
            ],
        ], [
            'vat_effective_from.required_unless' => 'Say the date this starts from. Nothing dated before it is ever touched.',
        ]);

        $regime = VatRegime::from($data['regime']);
        $from = isset($data['vat_effective_from']) ? Carbon::parse($data['vat_effective_from'])->startOfDay() : null;

        if ($regime !== VatRegime::NotRegistered) {
            $this->assertBinGiven($data['vat_bin'] ?? null);
            $this->assertRateInForce($business, $regime, $from);
        }

        if ($regime === VatRegime::VatStandard) {
            $this->assertNothingUntaxedFrom($business, $from);
        }

        $before = $business->only(['vat_regime', 'vat_enabled', 'vat_effective_from', 'vat_bin', 'prices_include_tax', 'delivery_tax_class_id']);

        // ⚠ forceFill: these four are deliberately NOT fillable on Business, so
        // no settings form anywhere else can ever mass-assign them.
        $business->forceFill([
            'vat_regime' => $regime->value,
            // VAT is CHARGED ON DOCUMENTS only under the standard regime.
            // Turnover tax is a period charge on turnover and adds nothing to
            // an invoice, so the document engine stays off for it.
            'vat_enabled' => $regime === VatRegime::VatStandard,
            'vat_effective_from' => $regime === VatRegime::NotRegistered ? null : $from,
            'vat_bin' => $data['vat_bin'] ?? null,
            'prices_include_tax' => (bool) $data['prices_include_tax'],
            'delivery_tax_class_id' => $data['delivery_tax_class_id'] ?? null,
        ])->save();

        activity('vat')
            ->performedOn($business)
            ->causedBy($request->user())
            ->withProperties(['before' => $before, 'after' => $business->fresh()->only(array_keys($before))])
            ->log("VAT settings changed: {$regime->label()}".($from ? ' from '.$from->format('j M Y') : ''));

        return back()->with('success', match ($regime) {
            VatRegime::NotRegistered => 'VAT is off. No document will carry VAT.',
            VatRegime::TurnoverTax => 'Turnover tax is on from '.$from->format('j M Y').'. Invoices stay as they are; the tax is worked out on the VAT return.',
            VatRegime::VatStandard => 'VAT is on from '.$from->format('j M Y').'. Every invoice and bill dated from that day carries VAT.',
        });
    }

    public function storeRate(Request $request): RedirectResponse
    {
        $business = $this->business();

        $data = $request->validate([
            'tax_class_id' => ['required', 'integer', Rule::exists('tax_classes', 'id')->where('business_id', $business->id)],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'effective_from' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $class = TaxClass::query()->findOrFail($data['tax_class_id']);

        if (in_array($class->code, [TaxCalculator::ZERO, TaxCalculator::EXEMPT], true)) {
            throw ValidationException::withMessages([
                'tax_class_id' => "{$class->name} is zero by definition and takes no rate.",
            ]);
        }

        $from = Carbon::parse($data['effective_from'])->startOfDay();

        // ⚠ A rate dated on or before a document already taxed at the old one
        // would make that document's frozen rate look wrong to a reader, though
        // nothing would recompute it. Refused so the history reads straight.
        if ($class->code !== self::TURNOVER && $this->taxedDocumentsFrom($business, $from) > 0) {
            throw ValidationException::withMessages([
                'effective_from' => 'Documents dated on or after that day have already been taxed. A new rate must start after the last of them.',
            ]);
        }

        TaxRate::create([
            'business_id' => $business->id,
            'tax_class_id' => $class->id,
            'rate' => $data['rate'],
            'effective_from' => $from,
            'note' => $data['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', sprintf('%s is %s%% from %s.', $class->name, rtrim(rtrim(number_format((float) $data['rate'], 4, '.', ''), '0'), '.'), $from->format('j M Y')));
    }

    private function business(): Business
    {
        $business = Business::query()->findOrFail(app(BusinessContext::class)->id());

        // The turnover-tax percentage needs somewhere to live, and a business
        // created before this screen has no such class.
        TaxClass::firstOrCreate(
            ['business_id' => $business->id, 'code' => self::TURNOVER],
            ['name' => 'Turnover tax', 'note' => 'The percentage of turnover paid under the turnover-tax regime. Not a VAT class: it is never put on an invoice line.'],
        );

        return $business;
    }

    private function assertBinGiven(?string $bin): void
    {
        if ($bin === null || trim($bin) === '') {
            throw ValidationException::withMessages([
                'vat_bin' => 'Enter the BIN from the registration certificate. It is printed on every tax invoice.',
            ]);
        }
    }

    private function assertRateInForce(Business $business, VatRegime $regime, Carbon $from): void
    {
        $code = $regime === VatRegime::TurnoverTax ? self::TURNOVER : TaxCalculator::STANDARD;
        $class = TaxClass::query()->where('code', $code)->first();

        if ($class?->rateOn($from) === null) {
            throw ValidationException::withMessages([
                'regime' => sprintf(
                    'Set the %s rate first, effective on or before %s. Nothing can be switched on without it.',
                    $regime === VatRegime::TurnoverTax ? 'turnover tax' : 'standard VAT',
                    $from->format('j M Y'),
                ),
            ]);
        }
    }

    private function assertNothingUntaxedFrom(Business $business, Carbon $from): void
    {
        $invoices = SalesInvoice::query()->whereDate('invoice_date', '>=', $from)->where('tax_total', 0)->count();
        $bills = SupplierBill::query()->whereDate('bill_date', '>=', $from)->where('tax_total', 0)->count();

        // ⚠ Zero-rated and exempt sales are legitimately untaxed after the
        // switch, so this only guards the moment of switching ON: once VAT
        // already applies from this date, the question has been answered.
        if ($business->vat_enabled && $business->vat_effective_from?->equalTo($from)) {
            return;
        }

        if ($invoices + $bills > 0) {
            throw ValidationException::withMessages([
                'vat_effective_from' => sprintf(
                    '%d invoice(s) and %d bill(s) dated on or after %s were already recorded without VAT. Choose a date after the last of them.',
                    $invoices, $bills, $from->format('j M Y'),
                ),
            ]);
        }
    }

    private function taxedDocumentsFrom(Business $business, Carbon $from): int
    {
        return SalesInvoice::query()->whereDate('invoice_date', '>=', $from)->where('tax_total', '>', 0)->count()
            + SupplierBill::query()->whereDate('bill_date', '>=', $from)->where('tax_total', '>', 0)->count();
    }
}
