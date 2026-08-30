<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The office document house (Joy, 30 August 2026).
 *
 * ⚠⚠ NO NEW TABLE FOR THE FILES THEMSELVES. Every file in this ERP already
 * lives in `attachments`, on a private disk, reachable only through the one
 * authenticated, business-scoped `attachments.show` route, and shown only to
 * people `FileVisibility` says may see it. An archive with its own store would
 * be a SECOND security surface doing the same job, and the second one is always
 * the one that is wrong. So the archive is metadata ON the existing rows.
 *
 * ⚠ Every added column is nullable. Two hundred existing attachments - logos,
 * expense receipts, correction evidence - are not archive documents and must
 * stay exactly as valid as they were.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug', 80);

            // ⚠ How long a document of this kind is worth keeping, in months.
            // NULL means "keep indefinitely", which is the safe reading: a
            // retention nobody set must never be read as permission to delete.
            $table->unsignedSmallInteger('retention_months')->nullable();

            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'slug']);
        });

        Schema::table('attachments', function (Blueprint $table) {
            // ⚠⚠ A FILENAME IS NOT A TITLE. "scan_0012.pdf" is what a scanner
            // called it; "Trade licence 2026" is what somebody is looking for.
            // Everything below exists because a document nobody can find again
            // has been stored, not archived.
            $table->string('title')->nullable()->after('original_name');
            $table->text('description')->nullable()->after('title');

            // The date ON the document, which is not the date it was uploaded.
            // A licence issued in January and scanned in August is a January
            // document, and sorting by upload date hides that.
            $table->date('document_date')->nullable()->after('description');

            // The document's own number: licence no, contract ref, cheque no.
            $table->string('reference', 120)->nullable()->after('document_date');

            $table->foreignId('document_category_id')->nullable()
                ->after('collection')->constrained('document_categories')->nullOnDelete();

            // Versioning. A replaced document is SUPERSEDED, never overwritten:
            // the old one keeps its row and points at what replaced it, so the
            // version somebody acted on last year can still be produced.
            $table->unsignedSmallInteger('version')->default(1)->after('reference');
            $table->foreignId('superseded_by_id')->nullable()->after('version')
                ->constrained('attachments')->nullOnDelete();

            $table->index(['business_id', 'document_category_id']);
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignKey('superseded_by_id');
            $table->dropConstrainedForeignKey('document_category_id');
            $table->dropIndex(['business_id', 'document_category_id']);
            $table->dropColumn(['title', 'description', 'document_date', 'reference', 'version']);
        });

        Schema::dropIfExists('document_categories');
    }
};
