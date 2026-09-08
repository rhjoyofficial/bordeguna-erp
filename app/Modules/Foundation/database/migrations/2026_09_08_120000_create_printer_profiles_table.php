<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the receipt printer at a counter actually is.
 *
 * ⭐⭐ NO MACHINE IS NAMED IN THIS SYSTEM, DELIBERATELY. Joy bought a Rongta
 * RP327 on 8 September 2026; the next one will be a different make, and a shop
 * that opens in Savar will have a third. So the ERP does not know about
 * printers, it knows about PAPER: how wide the roll is, how much of it can be
 * printed on, and how many dots the head lays down per inch. Change the
 * machine, change the profile, and every document that goes to it is correct
 * again with no deploy.
 *
 * ⚠⚠ ONLY WHAT CHANGES THE PAPER IS STORED HERE. A thermal printer's auto-cut
 * and its cash-drawer kick are settings of the PRINTER DRIVER on the counter PC
 * - this application prints through the browser and cannot reach either of
 * them. Storing them would be recording a setting nothing honours, which is a
 * lie the settings screen would tell every time somebody opened it. The screen
 * explains where they really live instead.
 *
 * ⚠ `warehouse_id` IS THE OUTLET. This ERP has no separate outlet entity: a
 * counter sale already picks the warehouse it sells out of, so that is the
 * physical place a printer sits. NULL means "this business's default", which is
 * the officially-defined profile every counter falls back to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // ⚠ NULLABLE ON PURPOSE - null is the business-wide default, not a
            // missing value. nullOnDelete rather than cascade: deleting an
            // outlet must not silently take the shop's only printer profile
            // with it, it should fall back to being the default.
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name', 100);

            // The roll, and the part of the roll the head can reach. They are
            // NOT the same number and confusing them is the commonest way a
            // receipt comes out with its right-hand column cut off: an 80mm
            // roll prints 72mm.
            $table->unsignedSmallInteger('paper_width_mm');
            $table->unsignedSmallInteger('print_width_mm');

            // ⭐ THE NUMBER THE BARCODE DEPENDS ON. A printer lays down whole
            // dots; a barcode whose narrow bar works out at 2.16 dots gets some
            // bars at 2 and some at 3, and scanners fail it intermittently. With
            // the dpi known, the bar width can be made a whole number of dots.
            $table->unsignedSmallInteger('dpi')->default(203);

            // Points, not pixels: a point is a physical size, so 8pt is 8pt on
            // a 203dpi head and on a 300dpi one. Pixels are not.
            $table->decimal('base_font_pt', 4, 1)->default(8.0);
            $table->decimal('line_height', 3, 2)->default(1.30);

            $table->boolean('show_logo')->default(false);
            $table->boolean('show_barcode')->default(true);

            // The shop's own last line: a return rule, a thank you, a hotline.
            $table->string('footer_note', 500)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['business_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printer_profiles');
    }
};
