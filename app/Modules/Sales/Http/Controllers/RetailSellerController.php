<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Accounting\Exceptions\JournalException;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Support\EmployeeCash;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\BusinessIdentity;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\Sellers\MoveSellerStock;
use App\Modules\Sales\Actions\Sellers\OpenSellerStock;
use App\Modules\Sales\Actions\Sellers\ReportSellerSales;
use App\Modules\Sales\Corrections\SalesInvoiceActionPolicy;
use App\Modules\Sales\Corrections\SellerTransferActionPolicy;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SellerSale;
use App\Modules\Sales\Models\SellerTransfer;
use App\Modules\Sales\Support\RetailSellers;
use App\Modules\Sales\Support\SellerStatement;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * ⭐ Retail sellers on consignment (initial plan B21, R109, R106; Joy's Q3 = A,
 * 24 Oct 2026): "must have track who sells how much".
 *
 *   index      every seller with his period totals (Owner, Manager)
 *   store      make an HR employee a seller (his seller stock)
 *   show       one seller's statement and the three popups: Hand over, Report
 *              sales, Return unsold. The seller himself opens his OWN
 *              statement with no permission (self-service), read only.
 *   handover / returnGoods / sales   the three writes
 *   sheet      the printable hand-over (or return) paper at Trade Price
 *
 * ⚠ Double submit: every form carries a one-time `submission` key, taken once
 * (Cache::add), so a double click records one sheet, not two.
 */
class RetailSellerController extends Controller
{
    private const MANAGE = SellerTransferActionPolicy::PERMISSION;

    public function index(Request $request, SellerStatement $statements): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user->can(self::MANAGE)) {
            $own = $this->ownSellerRecord($user);
            abort_if($own === null, 403);

            return redirect()->route('sales.sellers.show', ['employee' => $own->id]);
        }

        [$from, $to] = $this->period($request);
        $sellerIds = RetailSellers::stocks()->pluck('seller_employee_id')->all();

        return Inertia::render('sales/sellers/index', [
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'sellers' => $statements->all($from, $to),
            'employees' => collect(EmployeeCash::custodianNames())
                ->reject(fn (string $name, int $id) => in_array($id, $sellerIds, true))
                ->map(fn (string $name, int $id) => ['id' => $id, 'name' => $name])
                ->values(),
        ]);
    }

    public function store(Request $request, OpenSellerStock $open): RedirectResponse
    {
        abort_unless($request->user()->can(self::MANAGE), 403);

        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')],
        ], ['employee_id.required' => 'Choose the employee.']);

        $employee = Employee::query()->findOrFail($data['employee_id']);
        $stock = $open->handle($employee);

        return redirect()->route('sales.sellers.show', ['employee' => $employee->id])
            ->with('success', "{$employee->fullName()} is a retail seller. His goods are kept in {$stock->name}.");
    }

    public function show(Request $request, Employee $employee, SellerStatement $statements): Response
    {
        $user = $request->user();
        $manages = $user->can(self::MANAGE);
        abort_unless($manages || (int) $employee->user_id === (int) $user->id, 403);

        $stock = RetailSellers::stockOf($employee);
        abort_if($stock === null, 404);

        [$from, $to] = $this->period($request);
        $statement = $statements->for($employee, $from, $to);

        $forms = null;

        if ($manages) {
            $warehouses = Warehouse::query()->operational()->where('is_active', true)->saleable()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']);
            $products = Product::query()->stocked()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku', 'type']);
            $group = RetailSellers::tradePriceGroup();
            $tp = RetailSellers::tradePrices($products, Carbon::now());
            $onHand = StockBatch::query()->consumable()
                ->whereIn('warehouse_id', $warehouses->pluck('id')->push($stock->id))
                ->selectRaw('warehouse_id, product_id, SUM(current_quantity) as qty')
                // consumable() sorts FEFO by expiry; MySQL's only_full_group_by refuses that sort on a grouped total.
                ->reorder()
                ->groupBy('warehouse_id', 'product_id')->get()
                ->groupBy('warehouse_id')
                ->map(fn ($rows) => $rows->mapWithKeys(fn ($r) => [(int) $r->product_id => rtrim(rtrim(number_format((float) $r->qty, 4, '.', ''), '0'), '.')]));

            $cash = RetailSellers::cashAccountsOf($employee->id)->where('is_active', true)->get(['id', 'name']);

            $forms = [
                'warehouses' => $warehouses,
                'products' => $products->map(fn (Product $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'sku' => $p->sku,
                    'trade_price' => $tp[$p->id] ?? null,
                ])->values(),
                'on_hand' => $onHand,
                'seller_stock_id' => $stock->id,
                'trade_group' => $group?->name,
                'customers' => Customer::query()->listable($user)->active()
                    ->where('type', '<>', 'walk_in')->where('type', '<>', 'staff')
                    ->orderBy('name')->get(['id', 'name', 'phone', 'credit_limit']),
                'money_accounts' => MoneyAccount::query()->active()->handled()->withLedger()
                    ->orderByRaw('CASE WHEN custodian_employee_id = ? THEN 0 ELSE 1 END', [$employee->id])
                    ->orderBy('name')->get(['id', 'name', 'type', 'custodian_employee_id']),
                'own_cash_id' => $cash->first()?->id,
                'reversal_reasons' => Reason::query()->where('context', 'reversal')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            ];

            $statement['sheets'] = array_map(function (array $sheet) use ($user) {
                $model = SellerTransfer::query()->find($sheet['id']);

                return $sheet + ['actions' => $model === null ? [] : app(SellerTransferActionPolicy::class)->actionsFor($model, $user)->toArray()['actions']];
            }, $statement['sheets']);

            $statement['sales'] = array_map(function (array $sale) use ($user) {
                $invoice = SalesInvoice::query()->find($sale['invoice_id']);

                return $sale + ['actions' => $invoice === null ? [] : app(SalesInvoiceActionPolicy::class)->actionsFor($invoice, $user)->toArray()['actions']];
            }, $statement['sales']);
        }

        return Inertia::render('sales/sellers/show', [
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'statement' => $statement,
            'canManage' => $manages,
            'forms' => $forms,
            'submission' => (string) Str::uuid(),
        ]);
    }

    public function handover(Request $request, Employee $employee, MoveSellerStock $move): RedirectResponse
    {
        return $this->move($request, $employee, $move, SellerTransfer::KIND_HANDOVER);
    }

    public function returnGoods(Request $request, Employee $employee, MoveSellerStock $move): RedirectResponse
    {
        return $this->move($request, $employee, $move, SellerTransfer::KIND_RETURN);
    }

    public function sales(Request $request, Employee $employee, ReportSellerSales $report): RedirectResponse
    {
        abort_unless($request->user()->can(self::MANAGE), 403);
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'sold_on' => ['required', 'date', 'before_or_equal:today'],
            'payment' => ['required', Rule::in([SellerSale::PAY_COLLECTED, SellerSale::PAY_SELLER_OWES, SellerSale::PAY_ON_ACCOUNT])],
            'price_basis' => ['required', Rule::in([SellerSale::BASIS_TRADE, SellerSale::BASIS_CUSTOMER])],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('business_id', $businessId)],
            'money_account_id' => ['nullable', 'integer', Rule::exists('money_accounts', 'id')->where('business_id', $businessId)],
            'sold_to' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            ...$this->lineRules($businessId),
        ], $this->messages() + [
            'sold_on.required' => 'Enter the date he sold them.',
            'payment.required' => 'Choose what happened to the money.',
        ]);

        $this->claimSubmission($request);

        try {
            $sale = $report->handle(
                seller: $employee,
                soldOn: $data['sold_on'],
                lines: $this->lines($data['lines']),
                payment: $data['payment'],
                by: $request->user(),
                customer: isset($data['customer_id']) ? Customer::query()->find($data['customer_id']) : null,
                priceBasis: $data['price_basis'],
                moneyAccount: isset($data['money_account_id']) ? MoneyAccount::query()->find($data['money_account_id']) : null,
                soldTo: $data['sold_to'] ?? null,
                note: $data['note'] ?? null,
            );
        } catch (InsufficientStockException|PeriodClosedException|MappingNotFound|JournalException|DomainException|InvalidArgumentException|RuntimeException $e) {
            $this->releaseSubmission($request);

            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        } catch (ValidationException $e) {
            $this->releaseSubmission($request);

            throw $e;
        }

        return back()->with('success', "Sales {$sale->number} recorded for {$employee->fullName()}: Tk ".number_format((float) $sale->total, 2).'.');
    }

    /** The printable sheet: the seller signs the hand-over and keeps a copy. */
    public function sheet(Request $request, SellerTransfer $sheet): Response
    {
        $user = $request->user();
        $employee = $sheet->employee;
        abort_unless($user->can(self::MANAGE) || ($employee !== null && (int) $employee->user_id === (int) $user->id), 403);

        $sheet->load(['lines.product:id,name,sku', 'otherWarehouse:id,name', 'sellerWarehouse:id,name', 'createdBy:id,name', 'employee']);

        return Inertia::render('sales/sellers/sheet', [
            'issuer' => BusinessIdentity::forCurrent()->toArray(),
            'sheet' => [
                'number' => $sheet->number,
                'kind' => $sheet->kind,
                'date' => $sheet->moved_on?->toDateString(),
                'seller' => $employee?->fullName(),
                'seller_code' => $employee?->employee_code,
                'warehouse' => $sheet->otherWarehouse?->name,
                'reason' => $sheet->reason,
                'note' => $sheet->note,
                'by' => $sheet->createdBy?->name,
                'reversed' => $sheet->isReversed(),
                'lines' => $sheet->lines->map(fn ($line) => [
                    'product' => $line->product?->name,
                    'sku' => $line->product?->sku,
                    'quantity' => rtrim(rtrim((string) $line->quantity, '0'), '.'),
                    'trade_price' => number_format((float) $line->trade_price, 2),
                    'amount' => number_format((float) $line->quantity * (float) $line->trade_price, 2),
                ])->values(),
                'total' => number_format((float) $sheet->trade_value, 2),
            ],
        ]);
    }

    private function move(Request $request, Employee $employee, MoveSellerStock $move, string $kind): RedirectResponse
    {
        abort_unless($request->user()->can(self::MANAGE), 403);
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'moved_on' => ['required', 'date', 'before_or_equal:today'],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('business_id', $businessId)],
            'reason' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            ...$this->lineRules($businessId),
        ], $this->messages() + [
            'moved_on.required' => 'Enter the date.',
            'warehouse_id.required' => $kind === SellerTransfer::KIND_HANDOVER ? 'Choose the warehouse the goods come from.' : 'Choose the warehouse the goods go back to.',
        ]);

        $this->claimSubmission($request);

        try {
            $sheet = $move->handle(
                kind: $kind,
                seller: $employee,
                other: Warehouse::query()->findOrFail($data['warehouse_id']),
                lines: $this->lines($data['lines']),
                by: $request->user(),
                movedOn: $data['moved_on'],
                reason: $data['reason'] ?? null,
                note: $data['note'] ?? null,
            );
        } catch (InsufficientStockException|PeriodClosedException|DomainException|InvalidArgumentException|RuntimeException $e) {
            $this->releaseSubmission($request);

            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        } catch (ValidationException $e) {
            $this->releaseSubmission($request);

            throw $e;
        }

        $what = $kind === SellerTransfer::KIND_HANDOVER ? 'Hand-over' : 'Return';

        return back()->with('success', "{$what} {$sheet->number} recorded for {$employee->fullName()}.")
            ->with('sellerSheet', ['id' => $sheet->id, 'number' => $sheet->number, 'kind' => $kind]);
    }

    /** @return array<string, mixed> */
    private function lineRules(int $businessId): array
    {
        return [
            'submission' => ['required', 'string', 'max:64'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('business_id', $businessId)],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'lines.required' => 'Add at least one item.',
            'lines.*.product_id.required' => 'Choose the item.',
            'lines.*.quantity.required' => 'Enter how many.',
            'lines.*.quantity.gt' => 'Enter a quantity above zero.',
        ];
    }

    /**
     * @param  array<int, array{product_id: int, quantity: string|int|float}>  $lines
     * @return list<array{product: Product, quantity: string}>
     */
    private function lines(array $lines): array
    {
        $products = Product::query()->whereIn('id', collect($lines)->pluck('product_id'))->get()->keyBy('id');

        return array_values(array_map(fn (array $line) => [
            'product' => $products[(int) $line['product_id']],
            'quantity' => (string) $line['quantity'],
        ], $lines));
    }

    private function claimSubmission(Request $request): void
    {
        $key = 'seller-form:'.app(BusinessContext::class)->id().':'.$request->input('submission');

        if (! Cache::add($key, true, now()->addHour())) {
            throw ValidationException::withMessages(['lines' => 'This form was already sent once. Open it again to record another.']);
        }
    }

    private function releaseSubmission(Request $request): void
    {
        Cache::forget('seller-form:'.app(BusinessContext::class)->id().':'.$request->input('submission'));
    }

    /** @return array{0: Carbon, 1: Carbon} the chosen period, this month by default */
    private function period(Request $request): array
    {
        try {
            $from = $request->filled('from') ? Carbon::parse((string) $request->query('from'))->startOfDay() : Carbon::today()->startOfMonth();
            $to = $request->filled('to') ? Carbon::parse((string) $request->query('to'))->startOfDay() : Carbon::today();
        } catch (\Throwable) {
            [$from, $to] = [Carbon::today()->startOfMonth(), Carbon::today()];
        }

        return $from->gt($to) ? [$to, $from] : [$from, $to];
    }

    private function ownSellerRecord(User $user): ?Employee
    {
        $employee = Employee::query()->where('user_id', $user->id)->first();

        return $employee !== null && RetailSellers::stockOf($employee) !== null ? $employee : null;
    }
}
