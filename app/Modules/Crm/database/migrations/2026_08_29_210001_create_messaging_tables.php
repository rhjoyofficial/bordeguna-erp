<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sending to the phone book (Joy, 29 August 2026): phases 2 and 3 of the design
 * at D:\WORK\BorDeGuna\PHONEBOOK-MESSAGING-DESIGN-2026-08-29-BN.md.
 *
 * ⚠⚠ THIS IS THE FIRST THING IN THE ERP THAT SPENDS MONEY BY ITSELF. Every
 * other module records money somebody else moved. A send costs per message, per
 * part, and a loop with a bug is a real bill. Three brakes are built into the
 * shape below and none of them is optional: a preview that must be read, a hard
 * cap per campaign, and approval above a threshold.
 *
 * ⚠⚠ `message_campaigns` IS NOT THE EXISTING `campaigns` TABLE. That one records
 * where a LEAD CAME FROM - attribution, tied to a lead source, with Meta's own
 * campaign id. This one is an outbound send. Same word, opposite direction, and
 * confusing them would put marketing spend into the attribution reports.
 *
 * ⚠⚠ OPT-OUT KEYS ON THE NORMALISED ADDRESS, NOT ON THE CONTACT ROW. If "do not
 * message me" lived as a column on `contacts`, deleting the contact and
 * re-importing the file would resurrect consent and message the person again.
 * The register outlives the phone book on purpose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('channel', 10);          // sms | email
            $table->string('driver', 40);           // which class actually sends
            $table->string('name');

            // ⚠⚠ ENCRYPTED AT REST and never rendered back to a screen. The
            // settings page shows the last four characters of a key and nothing
            // more. Joy types his own credentials; nobody else needs to read
            // them back, and a page that could show them is a page that will.
            $table->text('credentials')->nullable();

            // The masking sender id, when BTRC has approved one.
            $table->string('sender_id')->nullable();

            $table->boolean('is_active')->default(false);
            $table->unsignedSmallInteger('priority')->default(100);

            // ⚠ What one message part costs, for the estimate. INFORMATION, not
            // accounting: the real bill arrives monthly and is entered as an
            // expense voucher like the hosting bill. No journal is ever posted
            // from an estimate.
            $table->decimal('cost_per_part', 10, 4)->default(0);

            $table->timestamps();

            $table->index(['business_id', 'channel', 'is_active']);
        });

        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('channel', 10);
            $table->string('name');
            $table->string('subject')->nullable();  // email only
            $table->text('body');
            $table->timestamps();

            $table->unique(['business_id', 'channel', 'name']);
        });

        Schema::create('message_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('channel', 10);
            $table->string('name');
            $table->string('subject')->nullable();
            $table->text('body');

            // ⚠ How the audience was chosen, kept so the send can be explained
            // later. `group` plus an id, or `all` for every active contact.
            $table->string('audience_type', 20)->default('group');
            $table->foreignId('contact_group_id')->nullable()->constrained('contact_groups')->nullOnDelete();

            $table->foreignId('message_provider_id')->nullable()->constrained('message_providers')->nullOnDelete();

            // ⚠⚠ THE HARD CAP. A send that would exceed it STOPS rather than
            // continuing, because the failure mode being guarded against is an
            // audience larger than anybody intended.
            $table->unsignedInteger('recipient_cap')->default(500);

            $table->string('status', 20)->default('draft');

            // What the preview said, frozen at the moment it was approved, so
            // the bill can be compared with what somebody agreed to.
            $table->unsignedInteger('estimated_recipients')->default(0);
            $table->unsignedInteger('estimated_parts')->default(0);
            $table->decimal('estimated_cost', 12, 2)->default(0);

            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });

        Schema::create('message_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_campaign_id')->constrained('message_campaigns')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            // ⚠ The address AS SENT TO, copied here rather than read back
            // through the contact. A contact whose number changes next month
            // must not rewrite the history of where a message actually went.
            $table->string('to_address');

            $table->string('status', 20)->default('queued');

            $table->string('provider_message_id')->nullable();
            $table->text('error')->nullable();

            $table->unsignedSmallInteger('parts')->default(1);
            $table->decimal('cost', 10, 4)->default(0);

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['message_campaign_id', 'status']);
        });

        Schema::create('message_opt_outs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('channel', 10);

            // ⚠⚠ The normalised phone, or the lowercased email. NOT a contact
            // id. See the file note: consent must outlive the contact row.
            $table->string('address', 191);

            $table->string('reason')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'channel', 'address'], 'message_opt_outs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_opt_outs');
        Schema::dropIfExists('message_deliveries');
        Schema::dropIfExists('message_campaigns');
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('message_providers');
    }
};
