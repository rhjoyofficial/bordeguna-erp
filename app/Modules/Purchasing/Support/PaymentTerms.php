<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Purchasing\Enums\PaymentTermType;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * ⭐ A supplier's payment terms as something a date can be worked out from
 * (ruling P6, 18 Oct 2026): a kind and, for "net" and "end of month", a number
 * of days.
 *
 * Where they come from, most specific first: what the bill itself says (an
 * override, with a reason), the purchase order's own terms, then the
 * supplier's. ⚠⚠ The bill keeps a COPY of the terms it used and the due date
 * worked out from them (snapshot()), so changing the supplier's terms later
 * moves no bill that is already recorded.
 */
final class PaymentTerms
{
    /** The longest term a person may type: a year. */
    public const MAX_DAYS = 365;

    public const REASON_NEEDED = 'This due date is not the one the payment terms give. Say why it differs.';

    public function __construct(
        public readonly PaymentTermType $type,
        public readonly ?int $days = null,
    ) {}

    /** Null when no kind is set, or when the kind needs days and has none. */
    public static function of(?string $type, mixed $days): ?self
    {
        $kind = $type === null || $type === '' ? null : PaymentTermType::tryFrom($type);

        if ($kind === null) {
            return null;
        }

        if (! $kind->usesDays()) {
            return new self($kind);
        }

        if ($days === null || $days === '' || ! is_numeric($days)) {
            return null;
        }

        return new self($kind, max(0, min(self::MAX_DAYS, (int) $days)));
    }

    public static function forSupplier(?Supplier $supplier): ?self
    {
        return $supplier === null ? null : self::of($supplier->payment_terms_type, $supplier->payment_terms_days);
    }

    /** The order's own terms when it has them, else its supplier's. */
    public static function forOrder(?PurchaseOrder $order, ?Supplier $supplier = null): ?self
    {
        return ($order === null ? null : self::of($order->payment_terms_type, $order->payment_terms_days))
            ?? self::forSupplier($supplier ?? $order?->supplier);
    }

    /** The terms a recorded bill kept, never re-read from the supplier. */
    public static function forBill(SupplierBill $bill): ?self
    {
        return self::of($bill->payment_terms_type, $bill->payment_terms_days);
    }

    public function dueDate(CarbonInterface $billDate): Carbon
    {
        return $this->type->dueDate($billDate, $this->days);
    }

    public function label(): string
    {
        return $this->type->label($this->days);
    }

    public function equals(?self $other): bool
    {
        return $other !== null && $other->type === $this->type && $other->days === $this->days;
    }

    /** @return array{type: string, days: int|null, label: string} */
    public function toArray(): array
    {
        return ['type' => $this->type->value, 'days' => $this->days, 'label' => $this->label()];
    }

    /** @return array{payment_terms_type: string|null, payment_terms_days: int|null} */
    public static function columns(?self $terms): array
    {
        return [
            'payment_terms_type' => $terms?->type->value,
            'payment_terms_days' => $terms?->days,
        ];
    }

    /**
     * The validation rules for a terms pair under `$prefix` (e.g.
     * "payment_terms_"), the same on the supplier, the order and the bill.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(string $prefix = 'payment_terms_'): array
    {
        $usesDays = array_values(array_map(fn (PaymentTermType $t) => $t->value, array_filter(PaymentTermType::cases(), fn (PaymentTermType $t) => $t->usesDays())));

        return [
            "{$prefix}type" => ['nullable', 'string', Rule::enum(PaymentTermType::class)],
            "{$prefix}days" => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_DAYS, 'required_if:'.$prefix.'type,'.implode(',', $usesDays)],
        ];
    }

    /** @return array<string, string> */
    public static function messages(string $prefix = 'payment_terms_'): array
    {
        return [
            "{$prefix}days.required_if" => 'Give the number of days for these terms.',
            "{$prefix}days.max" => 'Terms longer than a year are not accepted. Check the number of days.',
        ];
    }

    /**
     * ⚠⚠ The due date a NEW bill keeps, and where it came from. Called once,
     * as the bill is recorded; the result is stored on the bill and never
     * recomputed.
     *
     * - No date typed: the terms' date ('terms'), or none at all when there
     *   are no terms (ageing then counts from the bill date, as before).
     * - A date typed with no terms anywhere: kept as typed ('typed').
     * - A date typed that differs from the terms' date, or terms on the bill
     *   that differ from the order's or supplier's: an override, which needs
     *   a reason ('override').
     *
     * @return array{payment_terms_type: string|null, payment_terms_days: int|null, due_date: Carbon|null, due_date_source: string|null, due_date_reason: string|null}
     */
    public static function snapshot(
        Supplier $supplier,
        ?PurchaseOrder $order,
        CarbonInterface $billDate,
        mixed $typedDueDate = null,
        ?self $billTerms = null,
        ?string $reason = null,
    ): array {
        $default = self::forOrder($order, $supplier);
        $terms = $billTerms ?? $default;
        $worked = $terms?->dueDate($billDate);
        $typed = $typedDueDate === null || $typedDueDate === '' ? null : Carbon::parse($typedDueDate)->startOfDay();
        $reason = trim((string) $reason) === '' ? null : trim((string) $reason);

        // Terms typed on a bill whose supplier has none override nothing.
        $termsChanged = $billTerms !== null && $default !== null && ! $billTerms->equals($default);
        $dateChanged = $typed !== null && $worked !== null && $typed->toDateString() !== $worked->toDateString();
        $override = $termsChanged || $dateChanged;

        if ($override && $reason === null) {
            throw new InvalidArgumentException(self::REASON_NEEDED);
        }

        $due = $typed ?? $worked;

        return self::columns($terms) + [
            'due_date' => $due,
            'due_date_source' => match (true) {
                $due === null => null,
                $override => 'override',
                $terms === null => 'typed',
                default => 'terms',
            },
            'due_date_reason' => $override ? $reason : null,
        ];
    }

    /**
     * ⚠ Only what is UNAMBIGUOUS in the old free text becomes structured: the
     * same reading the 19 Oct migration applied to existing suppliers. A bare
     * "30" or "cash" is left alone, since it could mean a method rather than
     * a term; anything this does not recognise stays text only.
     */
    public static function parse(?string $text): ?self
    {
        $value = strtolower(trim(preg_replace('/\s+/', ' ', (string) $text) ?? ''));
        $value = rtrim($value, '.');

        if ($value === '') {
            return null;
        }

        if (preg_match('/^(cod|c\.o\.d|cash on delivery|immediate|immediately|due on receipt|payment on delivery|on delivery)$/', $value)) {
            return new self(PaymentTermType::Immediate);
        }

        if (preg_match('/^(advance|advance payment|full advance|100% advance|payment in advance|prepaid|prepayment)$/', $value)) {
            return new self(PaymentTermType::Advance);
        }

        if (preg_match('/^(?:eom|end of month|end of the month)(?: ?\+ ?(\d{1,3})(?: ?days?)?)?$/', $value, $m)) {
            $days = (int) ($m[1] ?? 0);

            return $days <= self::MAX_DAYS ? new self(PaymentTermType::EndOfMonth, $days) : null;
        }

        if (preg_match('/^(?:net ?(\d{1,3})(?: ?(?:days?|d))?|(\d{1,3}) ?(?:days?|d))$/', $value, $m)) {
            $days = (int) (($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? 0));

            return $days <= self::MAX_DAYS ? new self(PaymentTermType::NetDays, $days) : null;
        }

        return null;
    }
}
