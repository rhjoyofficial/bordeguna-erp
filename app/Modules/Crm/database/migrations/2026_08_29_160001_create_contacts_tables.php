<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The phone book (Joy, 29 August 2026), and the groups you send to.
 *
 * ⚠⚠ WHY A TABLE OF ITS OWN, when leads and customers already hold phone
 * numbers. Because the phone book holds people who are NEITHER: the shopkeeper
 * two streets over, a card collected at a fair, an old list from a spreadsheet.
 * Pointing groups straight at leads and customers leaves those people with
 * nowhere to live. And a send that gathers numbers from four different tables
 * has four different ways to go wrong, and no single place to record that
 * somebody asked not to be contacted.
 *
 * So `contacts` is the one addressable register. A customer or a lead brought
 * into the phone book gets a row here that points back at the original through
 * `linked_type` / `linked_id`, and the original stays the master.
 *
 * ⚠⚠ `phone_normalized` IS NOT UNIQUE AND MUST NEVER BE MADE UNIQUE. Households
 * share a number, a shop's landline is the owner's mobile, a family buys under
 * one phone. C-CRM-12 and C-CRM-13 settled this for leads and the same reasoning
 * governs here: matching WARNS, it does not block. The index exists to find the
 * same person quickly, not to forbid the second one.
 *
 * ⚠ The number the human typed is kept verbatim in `phone`. `phone_normalized`
 * is a separate matching form produced by PhoneIdentity. We do not silently
 * correct somebody's data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('company')->nullable();

            // ⚠ Both nullable, and at least one required by the request rules
            // rather than by the schema. A contact with a phone and no email is
            // ordinary; a contact with neither cannot be sent anything, but the
            // database is the wrong place to argue about it, because an import
            // needs to report that row rather than abort the whole file.
            $table->string('phone')->nullable();
            $table->string('phone_normalized', 32)->nullable();
            $table->string('email')->nullable();

            $table->string('address')->nullable();

            // Where the number came from: an import file, a fair, the website.
            // Free text on purpose. Unlike a lead source this is not a reporting
            // axis, it is a note to the person looking at the row later.
            $table->string('source')->nullable();
            $table->text('note')->nullable();

            // ⚠ The link back to the master record, when there is one. Not a
            // foreign key, because it points at two different tables; the model
            // resolves it and a broken link degrades to a plain contact rather
            // than breaking the page.
            $table->string('linked_type')->nullable();
            $table->unsignedBigInteger('linked_id')->nullable();

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'phone_normalized']);
            $table->index(['business_id', 'email']);
            $table->index(['business_id', 'is_active']);

            // ⚠ One contact per linked record per business, so pulling the same
            // customer in twice cannot make two rows. The phone is NOT part of
            // this: see the warning above.
            $table->unique(['business_id', 'linked_type', 'linked_id'], 'contacts_linked_unique');
        });

        Schema::create('contact_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'name']);
        });

        Schema::create('contact_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // ⚠ The guarantee that a group cannot list the same person twice,
            // which matters the day this feeds a send: a duplicate row is a
            // duplicate message and, once SMS is wired, duplicate money.
            $table->unique(['contact_group_id', 'contact_id'], 'contact_group_members_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_group_members');
        Schema::dropIfExists('contact_groups');
        Schema::dropIfExists('contacts');
    }
};
