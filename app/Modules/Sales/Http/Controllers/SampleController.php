<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Exceptions\JournalException;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Support\Money;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\Lead;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\ApproveSampleIssue;
use App\Modules\Sales\Actions\RecordSampleIssue;
use App\Modules\Sales\Actions\RecordSampleMake;
use App\Modules\Sales\Corrections\SampleIssueActionPolicy;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SampleIssue;
use App\Modules\Sales\Models\SampleMake;
use App\Modules\Sales\Support\CourierZoneCharge;
use App\Modules\Sales\Support\SampleBook;
use App\Support\BdGeo;
use App\Support\PerPage;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * ⭐ SAMPLES (Joy, 25 Oct 2026): Sales > Sell > Samples.
 *
 *   index    every sample given (date, recipient, purpose, items, cost,
 *            delivery, given by), filtered by period, purpose, recipient and
 *            product; Reverse on each (the corrections engine); Give sample
 *            and Make samples open in a popup, date first; `?give={product}`
 *            (the product page's "Give as sample") opens Give with it
 *   store    Give sample (RecordSampleIssue)
 *   make     Make samples from raw materials (RecordSampleMake)
 *   approve  the approvals inbox's yes and no (ApproveSampleIssue)
 *   zone     the courier zone charge for a district (CourierZoneCharge), so
 *            the popup can fill the delivery charge
 *
 * ⚠ Every refusal is a sentence on the field it is about; nothing here posts
 * a journal by hand.
 */
class SampleController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = [
            'from' => $request->string('from')->toString(),
            'to' => $request->string('to')->toString(),
            'purpose' => $request->string('purpose')->toString(),
            'recipient_type' => $request->string('recipient_type')->toString(),
            'recipient_id' => $request->string('recipient_id')->toString(),
            'search' => $request->string('search')->toString(),
            'product_id' => $request->string('product_id')->toString(),
        ];

        $policy = app(SampleIssueActionPolicy::class);
        $morph = SampleIssue::morphFor($filters['recipient_type']);

        $query = SampleIssue::query()
            ->when($filters['from'] !== '', fn ($q) => $q->whereDate('given_on', '>=', $filters['from']))
            ->when($filters['to'] !== '', fn ($q) => $q->whereDate('given_on', '<=', $filters['to']))
            ->when(array_key_exists($filters['purpose'], SampleIssue::PURPOSES), fn ($q) => $q->where('purpose', $filters['purpose']))
            ->when($morph !== null, fn ($q) => $q->where('recipient_type', $morph))
            ->when($morph !== null && $filters['recipient_id'] !== '', fn ($q) => $q->where('recipient_id', (int) $filters['recipient_id']))
            ->when($filters['search'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('recipient_name', 'like', "%{$filters['search']}%")
                ->orWhere('recipient_phone', 'like', "%{$filters['search']}%")
                ->orWhere('number', 'like', "%{$filters['search']}%")))
            ->when($filters['product_id'] !== '', fn ($q) => $q->whereHas('lines', fn ($l) => $l->where('product_id', (int) $filters['product_id'])));

        $samples = SampleBook::with((clone $query)->orderByDesc('given_on')->orderByDesc('id'))
            ->paginate(PerPage::from())->withQueryString()
            ->through(fn (SampleIssue $issue) => SampleBook::row($issue, $user) + [
                'actions' => $policy->actionsFor($issue, $user)->toArray()['actions'],
            ]);

        $live = (clone $query)->where('status', SampleIssue::POSTED)->whereNull('reversed_at');
        $canManage = $user->can('sales.samples.manage');

        return Inertia::render('sales/samples/index', [
            'filters' => $filters,
            'samples' => $samples,
            'totals' => [
                'count' => (clone $live)->count(),
                'cost' => SampleBook::seesCost($user) ? Money::ofMinor((int) (clone $live)->sum('cost_total_paisa'))->format() : null,
                'delivery' => Money::ofTaka((string) ((clone $live)->sum('delivery_charge') ?: '0'))->format(),
            ],
            'purposes' => SampleIssue::PURPOSES,
            'methods' => SampleIssue::METHODS,
            'canManage' => $canManage,
            'seesCost' => SampleBook::seesCost($user),
            'options' => $canManage ? fn () => $this->options() : null,
            'products' => Product::query()->orderBy('name')->get(['id', 'name']),
            'makes' => SampleMake::query()->with(['product:id,name', 'warehouse:id,name', 'batch:id,batch_number,current_quantity', 'createdBy:id,name'])
                ->orderByDesc('made_on')->orderByDesc('id')->limit(20)->get()
                ->map(fn (SampleMake $make) => [
                    'id' => $make->id,
                    'number' => $make->number,
                    'made_on' => $make->made_on->toDateString(),
                    'product' => $make->product?->name,
                    'warehouse' => $make->warehouse?->name,
                    'quantity' => rtrim(rtrim((string) $make->quantity, '0'), '.'),
                    'left' => rtrim(rtrim((string) ($make->batch?->current_quantity ?? '0'), '0'), '.'),
                    'batch' => $make->batch?->batch_number,
                    'cost' => SampleBook::seesCost($user) ? $make->costMoney()->format() : null,
                    'by' => $make->createdBy?->name,
                ])->values(),
            'give' => $request->integer('give') ?: null,
            'reversalReasons' => Reason::query()->where('context', 'reversal')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'reportUrl' => '/hrm/reports/samples_given',
            'today' => Carbon::today()->toDateString(),
        ]);
    }

    public function store(Request $request, RecordSampleIssue $record): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'given_on' => ['required', 'date', 'before_or_equal:today'],
            'recipient_kind' => ['required', Rule::in(['customer', 'lead', 'contact', 'employee', 'new'])],
            'recipient_id' => ['nullable', 'integer'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'recipient_phone' => ['nullable', 'string', 'max:40'],
            'purpose' => ['required', Rule::in(array_keys(SampleIssue::PURPOSES))],
            'purpose_note' => ['nullable', 'string', 'max:255'],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('business_id', $businessId)],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('business_id', $businessId)],
            'lines.*.stock_batch_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'delivery_method' => ['required', Rule::in(array_keys(SampleIssue::METHODS))],
            'delivery_charge' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'delivery_money_account_id' => ['nullable', 'integer'],
            'courier_account_id' => ['nullable', 'integer'],
            'delivery_district' => ['nullable', 'string', 'max:80'],
            'delivery_zone' => ['nullable', 'string', 'max:80'],
            'feedback_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'given_on.before_or_equal' => 'A sample cannot be given on a future date.',
        ]);

        try {
            $issue = $record->handle(
                givenOn: $data['given_on'],
                recipient: [
                    'kind' => $data['recipient_kind'],
                    'id' => $data['recipient_id'] ?? null,
                    'name' => $data['recipient_name'] ?? null,
                    'phone' => $data['recipient_phone'] ?? null,
                ],
                purpose: $data['purpose'],
                warehouse: Warehouse::query()->findOrFail($data['warehouse_id']),
                lines: array_map(fn (array $line) => [
                    'product_id' => (int) $line['product_id'],
                    'stock_batch_id' => empty($line['stock_batch_id']) ? null : (int) $line['stock_batch_id'],
                    'quantity' => (string) $line['quantity'],
                ], $data['lines']),
                delivery: [
                    'method' => $data['delivery_method'],
                    'charge' => isset($data['delivery_charge']) ? (string) $data['delivery_charge'] : null,
                    'money_account_id' => $data['delivery_money_account_id'] ?? null,
                    'courier_account_id' => $data['courier_account_id'] ?? null,
                    'district' => $data['delivery_district'] ?? null,
                    'zone' => $data['delivery_zone'] ?? null,
                ],
                givenBy: $request->user(),
                purposeNote: $data['purpose_note'] ?? null,
                feedbackOn: $data['feedback_on'] ?? null,
                note: $data['note'] ?? null,
            );
        } catch (PeriodClosedException|InsufficientStockException|DomainException|InvalidArgumentException|MappingNotFound|JournalException $e) {
            throw ValidationException::withMessages(['given_on' => $e->getMessage()]);
        }

        return back()->with('success', $issue->isPosted()
            ? "Sample {$issue->number} given to {$issue->recipient_name}."
            : "Sample {$issue->number} is waiting for approval by the Owner or a Manager.");
    }

    public function make(Request $request, RecordSampleMake $record): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'made_on' => ['required', 'date', 'before_or_equal:today'],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('business_id', $businessId)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('business_id', $businessId)],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'expiry_date' => ['nullable', 'date'],
            'inputs' => ['required', 'array', 'min:1', 'max:30'],
            'inputs.*.stock_batch_id' => ['required', 'integer'],
            'inputs.*.quantity' => ['required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'made_on.before_or_equal' => 'Samples cannot be made on a future date.',
        ]);

        try {
            $make = $record->handle(
                madeOn: $data['made_on'],
                output: Product::query()->findOrFail($data['product_id']),
                warehouse: Warehouse::query()->findOrFail($data['warehouse_id']),
                quantity: (string) $data['quantity'],
                inputs: array_map(fn (array $input) => ['stock_batch_id' => (int) $input['stock_batch_id'], 'quantity' => (string) $input['quantity']], $data['inputs']),
                by: $request->user(),
                note: $data['note'] ?? null,
                expiryDate: $data['expiry_date'] ?? null,
            );
        } catch (PeriodClosedException|MappingNotFound|JournalException $e) {
            throw ValidationException::withMessages(['made_on' => $e->getMessage()]);
        }

        return back()->with('success', "{$make->number}: sample lot {$make->batch?->batch_number} made.");
    }

    public function approve(Request $request, SampleIssue $sample, ApproveSampleIssue $approve): RedirectResponse
    {
        abort_unless($sample->approvalRequest?->canBeActionedBy($request->user()), 403);

        try {
            $sample = $approve->approve($sample, $request->user(), $request->input('note'));
        } catch (RuntimeException|DomainException|InvalidArgumentException|MappingNotFound|JournalException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $sample->isPosted() ? "Sample {$sample->number} approved and given." : "Sample {$sample->number} moved to its next approver.");
    }

    public function reject(Request $request, SampleIssue $sample, ApproveSampleIssue $approve): RedirectResponse
    {
        abort_unless($sample->approvalRequest?->canBeActionedBy($request->user()), 403);

        try {
            $approve->reject($sample, $request->user(), $request->input('review_note'));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Sample {$sample->number} rejected. Nothing left the warehouse.");
    }

    /** The courier zone charge to a district, from the shop's own zone rates. */
    public function zone(Request $request): JsonResponse
    {
        $charge = CourierZoneCharge::for(null, $request->string('district')->toString());

        return response()->json($charge === null ? null : ['charge' => $charge->charge, 'zone' => $charge->zone, 'district' => $charge->district]);
    }

    /**
     * What the two popups choose from. Sent only to who may give samples.
     *
     * @return array<string, mixed>
     */
    private function options(): array
    {
        $businessId = app(BusinessContext::class)->id();

        return [
            'customers' => Customer::query()->listable()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'phone']),
            'leads' => Lead::query()->workable()->orderBy('name')->limit(1000)->get(['id', 'name', 'phone']),
            'contacts' => Contact::query()->where('is_active', true)->orderBy('name')->limit(2000)->get(['id', 'name', 'phone']),
            'employees' => Employee::query()->whereHas('assignments')->where('status', '!=', 'separated')
                ->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'phone'])
                ->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->fullName(), 'phone' => $e->phone])->values(),
            'warehouses' => Warehouse::query()->where('not_for_sale', false)->orderBy('name')->get(['id', 'name']),
            'products' => Product::query()->where('type', '!=', 'service')->where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'type', 'unit_of_measure_id'])->load('unitOfMeasure:id,abbreviation')
                ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name, 'type' => $p->type->value, 'unit' => $p->unitOfMeasure?->abbreviation])->values(),
            // Every batch that can be taken, so the popup can offer FEFO or a
            // chosen batch (a sample lot is one of them).
            'batches' => StockBatch::query()->consumable()->whereIn('warehouse_id', Warehouse::query()->select('id'))
                ->orderBy('product_id')->get(['id', 'product_id', 'warehouse_id', 'batch_number', 'current_quantity', 'expiry_date', 'unit_cost'])
                ->map(fn (StockBatch $b) => [
                    'id' => $b->id,
                    'product_id' => $b->product_id,
                    'warehouse_id' => $b->warehouse_id,
                    'batch_number' => $b->batch_number ?? "#{$b->id}",
                    'quantity' => rtrim(rtrim((string) $b->current_quantity, '0'), '.'),
                    'expiry_date' => $b->expiry_date?->toDateString(),
                    'is_sample' => str_starts_with((string) $b->batch_number, 'SMPL-'),
                ])->values(),
            'moneyAccounts' => MoneyAccount::query()->handled()->withLedger()->where('is_active', true)->where('business_id', $businessId)->orderBy('name')->get(['id', 'name']),
            'couriers' => MoneyAccount::query()->where('type', 'courier')->where('is_active', true)->where('business_id', $businessId)->orderBy('name')->get(['id', 'name']),
            'districts' => array_column(BdGeo::districts(), 'name'),
        ];
    }
}
