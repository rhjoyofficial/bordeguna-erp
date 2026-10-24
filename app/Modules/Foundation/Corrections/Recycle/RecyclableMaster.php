<?php

namespace App\Modules\Foundation\Corrections\Recycle;

use App\Modules\Foundation\Support\ReferenceScan;

/**
 * ⭐ B24 (24 Oct 2026, R113; Joy's Q5 = A): a master list row (a product, a
 * brand, a unit, a warehouse...) that goes to the Recycle bin when nothing ever
 * used it, and is switched off (with a reason) once something did.
 *
 *   never used  Delete, to the bin; the Owner restores it
 *   used        Delete greyed with WHAT uses it; Switch off instead
 *
 * "Used" is read from the schema (ReferenceScan::usage): every column named
 * like the record's key and every foreign key that points at its table, so a
 * table added later counts without anybody remembering this trait.
 *
 * The model implements Removable and lists itself in config('recycle_bin').
 */
trait RecyclableMaster
{
    use Recyclable;

    /** Columns named after this record that may lack a foreign key (`brand_id`). */
    abstract public function usageColumns(): array;

    /** What the sentence calls it ("Brand LaLaDia"). */
    abstract public function binLabel(): string;

    /** Rows this record owns that do not make it "used" (its photos, its notes). */
    public function usageIgnoredTables(): array
    {
        return ['attachments'];
    }

    /** A rule of the record itself that keeps it (the default warehouse). */
    public function whyKept(): ?string
    {
        return null;
    }

    public function whyNotRemovable(): ?string
    {
        return $this->whyKept() ?? self::usageSentence($this->binLabel(), ReferenceScan::usage($this, $this->usageColumns(), $this->usageIgnoredTables()));
    }

    public function whyNotDeactivatable(): ?string
    {
        return null;
    }

    /** Restore refuses while what it hangs under is still in the bin. */
    public function whyNotRestorable(): ?string
    {
        $parents = ReferenceScan::parentsInBin($this);

        return $parents === [] ? null : 'Restore '.implode(', ', $parents).' first: '.$this->binLabel().' belongs under it.';
    }

    /** The one sentence a greyed Delete shows. Null when nothing uses it. */
    public static function usageSentence(string $label, array $usage): ?string
    {
        return $usage === [] ? null : "{$label} is used (".ReferenceScan::describe($usage).'), so it stays. Switch it off instead.';
    }
}
