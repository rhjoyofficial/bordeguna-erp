<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Joy, 30 August 2026: the document font becomes a setting.
 *
 * ⚠ PER BUSINESS, not global. Four businesses share this installation and the
 * circular binds Bor de Guna; another brand may be told to use something else,
 * and a single global font would silently restyle their paperwork too.
 *
 * ⚠⚠ NULL MEANS THE CIRCULAR'S FONT, not "no font". An unset value resolves
 * through FontCatalogue::DEFAULT to Tiro Bangla, so a business nobody has
 * configured still prints on the mandated face rather than on whatever the
 * browser reaches for - which, for Bangla, is frequently nothing at all.
 *
 * ⛔ The INTERFACE font is deliberately not here. Joy was explicit that the
 * sidebar and headings stay as they are until he says otherwise, so there is no
 * column to set and no screen to set it from. Adding one "while we are here"
 * would be building a switch nobody asked to be able to throw.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('document_font', 40)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('document_font');
        });
    }
};
