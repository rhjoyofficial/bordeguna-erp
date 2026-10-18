<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ Ruling P6: the free-text terms already typed on suppliers become
 * structured ONLY where they read one way.
 *
 * "COD", "Cash on delivery", "Advance", "Net 30", "30 days", "EOM + 15" are
 * copied into payment_terms_type / payment_terms_days. Anything else (a bare
 * "30", "cash", Bangla text, "cheque after 2 weeks") is left NULL, and the
 * text stays exactly as typed. ⚠ A supplier that already has a structured
 * kind is never touched, so running this twice changes nothing.
 *
 * ⚠ The reading is copied here rather than calling PaymentTerms::parse(): a
 * migration must do tomorrow what it did today, whatever the class becomes.
 * No bill, order or journal is read or written.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('suppliers')
            ->whereNull('payment_terms_type')
            ->whereNotNull('payment_terms')
            ->where('payment_terms', '!=', '')
            ->select(['id', 'payment_terms'])
            ->chunkById(200, function ($suppliers) {
                foreach ($suppliers as $supplier) {
                    $terms = $this->read((string) $supplier->payment_terms);

                    if ($terms !== null) {
                        DB::table('suppliers')->where('id', $supplier->id)->whereNull('payment_terms_type')->update([
                            'payment_terms_type' => $terms[0],
                            'payment_terms_days' => $terms[1],
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Nothing to undo that the schema migration's down() does not drop.
    }

    /** @return array{0: string, 1: int|null}|null */
    private function read(string $text): ?array
    {
        $value = rtrim(strtolower(trim(preg_replace('/\s+/', ' ', $text) ?? '')), '.');

        if ($value === '') {
            return null;
        }

        if (preg_match('/^(cod|c\.o\.d|cash on delivery|immediate|immediately|due on receipt|payment on delivery|on delivery)$/', $value)) {
            return ['immediate', null];
        }

        if (preg_match('/^(advance|advance payment|full advance|100% advance|payment in advance|prepaid|prepayment)$/', $value)) {
            return ['advance', null];
        }

        if (preg_match('/^(?:eom|end of month|end of the month)(?: ?\+ ?(\d{1,3})(?: ?days?)?)?$/', $value, $m)) {
            $days = (int) ($m[1] ?? 0);

            return $days <= 365 ? ['end_of_month', $days] : null;
        }

        if (preg_match('/^(?:net ?(\d{1,3})(?: ?(?:days?|d))?|(\d{1,3}) ?(?:days?|d))$/', $value, $m)) {
            $days = (int) (($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? 0));

            return $days <= 365 ? ['net_days', $days] : null;
        }

        return null;
    }
};
