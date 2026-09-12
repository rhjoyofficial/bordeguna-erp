<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What kind of lead this is, whether anybody has checked it, and the one place
 * a phone number is allowed to be an identity.
 *
 * ⚠ Measured on production 12 Sep 2026 before writing this: `leads` holds 0
 * rows. Every column below is therefore additive against an empty table and the
 * backfill is a formality, which is exactly why the redesign was scheduled now
 * rather than after the field team started using it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // ⚠ Defaulted to `customer` rather than left nullable. Every lead is
            // some kind of lead, and a nullable type would mean every reader in
            // the system carrying a "what if it is null" branch for ever.
            $table->string('lead_type', 20)->default('customer')->after('business_id');

            // ⭐⭐ THE SHOP'S IDENTITY, AND THE ONLY UNIQUE PHONE COLUMN.
            //
            // It holds `phone_normalized` when the lead is a place of business
            // and NULL otherwise, maintained by the model beside the normalised
            // number itself. A unique index ignores NULLs, so consumer leads are
            // completely unaffected and `PhoneIdentity`'s rule - that a person's
            // number must never be unique, because households share one - still
            // holds exactly where it always did.
            //
            // ⚠ A REAL COLUMN RATHER THAN A GENERATED ONE, on purpose. A stored
            // generated column would express this in SQL, but the expression
            // syntax differs between MySQL and SQLite and the suite runs on
            // SQLite while production is MySQL. A column the model maintains
            // behaves identically on both, and the constraint stays where it
            // belongs: in the database, not in a controller that a future import
            // path could bypass.
            $table->string('shop_phone_key', 32)->nullable()->after('phone_normalized');

            // Verification. ⭐ Joy's decision, 12 Sep 2026, choosing this over
            // approval on every create and update: a field officer creates a
            // retailer and it EXISTS IMMEDIATELY and is usable for work, but
            // reads as Unverified until the ASM confirms it. Approval on every
            // edit would leave a phone correction waiting on a manager, and the
            // officer would go back to WhatsApp.
            //
            // ⚠ Unverified is the ABSENCE of verification rather than a status
            // word, the same shape `published_at` uses elsewhere in this ERP. It
            // cannot drift out of step with who did it or when.
            $table->timestamp('verified_at')->nullable()->after('note');
            $table->foreignId('verified_by_user_id')->nullable()->after('verified_at')
                ->constrained('users')->restrictOnDelete();

            // ⛔ NEVER A HARD DELETE. Archiving keeps what happened - who created
            // this lead, when, and every activity recorded against it - which is
            // the whole point of the management numbers verification exists to
            // produce. A deleted row would quietly change those counts after the
            // fact.
            $table->timestamp('archived_at')->nullable()->after('verified_by_user_id');
            $table->foreignId('archived_by_user_id')->nullable()->after('archived_at')
                ->constrained('users')->restrictOnDelete();

            $table->index(['business_id', 'lead_type']);

            // ⚠ Scoped by business, like every other index on this table: two
            // tenants may each hold the same shop's number without colliding.
            $table->unique(['business_id', 'shop_phone_key']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'shop_phone_key']);
            $table->dropIndex(['business_id', 'lead_type']);

            $table->dropConstrainedForeignId('verified_by_user_id');
            $table->dropConstrainedForeignId('archived_by_user_id');

            $table->dropColumn(['lead_type', 'shop_phone_key', 'verified_at', 'archived_at']);
        });
    }
};
