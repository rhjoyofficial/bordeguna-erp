<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The images a store's pages are built from.
 *
 * ⚠⚠ SEPARATE FROM `attachments` ON PURPOSE, and the difference is not
 * cosmetic. An attachment is PRIVATE by design: an employee's national ID, a
 * bill, correction evidence, all served through a controller that asks a policy
 * whether this user may see this file. A storefront image is the opposite: it
 * is served to anonymous strangers, thousands of times, and must be cacheable.
 * Bolting public delivery onto the private engine would put one access decision
 * in two minds, which is how a payslip ends up on a CDN.
 *
 * ⛔ `alt_text` AND `is_decorative` TOGETHER, because "no alt text yet" and
 * "this image deliberately has none" are different facts and only one of them
 * is acceptable on a live page. An empty alt is CORRECT for a decorative
 * flourish and WRONG for a product photograph, so the ERP makes somebody decide
 * rather than defaulting. The storefront audit found six alt attributes in a
 * thousand-line template; this is that defect made impossible to repeat.
 *
 * ⚠ `uuid` is what the public URL carries, not the id. Sequential ids can be
 * walked, and a store that has not launched yet would leak its product
 * photography to anyone counting upwards.
 *
 * ⚠ `width` and `height` are recorded at upload so the storefront can reserve
 * the space before the image arrives. Without them every image on the page
 * shifts the layout as it loads, which is a ranking signal as well as a
 * miserable thing to read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_media', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // ⛔ Both, for the reason the pages table records: every model in
            // this ERP fails closed through BusinessScope and this one must too.
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();

            // The disk is stored per file so retrieval keeps working if the
            // default changes, exactly as `attachments` already does.
            $table->string('disk');
            $table->string('path');

            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            $table->string('alt_text', 255)->nullable();
            $table->boolean('is_decorative')->default(false);

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['store_id', 'created_at']);
            $table->index(['business_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_media');
    }
};
