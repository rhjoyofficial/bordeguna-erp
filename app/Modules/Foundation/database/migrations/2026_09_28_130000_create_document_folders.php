<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Folders for the document archive (Joy, 28 Sep 2026: make it "like Google
 * Drive"). Until now the archive was a flat list filtered by category and date;
 * this adds a navigable tree so a person can keep "Licences", "Contracts 2026"
 * and so on the way they would on a shared drive.
 *
 * ⚠⚠ FOLDERS ORGANISE, THEY DO NOT DECIDE VISIBILITY. A file's folder is where it
 * lives; who may SEE it is still FileVisibility's job, keyed on the record the
 * file belongs to. Only library files (attached to the business) are foldered;
 * evidence attached to an expense claim or a payslip keeps its own home and
 * ignores `folder_id`. So a folder can never widen what somebody is allowed to
 * see - it only arranges what they already could.
 *
 * ⚠ Categories (with their retention rule) are left exactly as they were and stay
 * orthogonal: a folder is WHERE a document sits, a category is WHAT KIND it is
 * and how long it is kept. A document has at most one of each.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // A self-reference: null is a top-level folder. Deleting a folder
            // takes its subtree with it at the database level, but the controller
            // only ever deletes an EMPTY folder, so this is a backstop, not a
            // routine cascade.
            $table->foreignId('parent_id')->nullable()->constrained('document_folders')->cascadeOnDelete();
            $table->string('name', 120);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // No two folders with the same name under the same parent. ⚠ MySQL
            // treats NULL parents as distinct, so uniqueness at the ROOT is
            // enforced in the controller instead; this covers every nested case.
            $table->unique(['business_id', 'parent_id', 'name']);
        });

        Schema::table('attachments', function (Blueprint $table) {
            // ⚠ Nullable and null by default: every existing attachment - logos,
            // receipts, evidence - stays exactly as valid as it was, sitting at
            // the archive root. A folder deleted out from under a file (which the
            // controller forbids while it holds files) would only send it back to
            // the root, never orphan it.
            $table->foreignId('folder_id')->nullable()->after('document_category_id')
                ->constrained('document_folders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('folder_id');
        });

        Schema::dropIfExists('document_folders');
    }
};
