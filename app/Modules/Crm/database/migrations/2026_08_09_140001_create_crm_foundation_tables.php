<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM foundation, slice 1: prospects and where they came from.
 *
 * Design note and the seventeen approved Corrections: CRM-FOUNDATION-ARCHITECTURE.md.
 *
 * ⛔ This slice captures leads and attribution. It creates no customer, writes
 * nothing to the ledger, and adds no path that sets a lead to `converted` -
 * conversion is a deliberate Phase 3 action (C-CRM-15, C-CRM-9).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Controlled vocabulary: where leads come from ────────────────────
        //
        // ⚠⚠ C-CRM-3: A TABLE WITH A FROZEN CODE, NOT FREE TEXT. Source and
        // campaign are the axes this business will group revenue by. Free text
        // gives `Meta`, `meta`, `Meta Ads` and `FB` as four sources, and nobody
        // notices until a report is short. Exactly the argument that made
        // `brands` a table rather than a string on `products`.
        Schema::create('lead_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'code']);
            $table->index(['business_id', 'is_active']);
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_source_id')->constrained('lead_sources')->restrictOnDelete();

            // ⚠ C-CRM-11: nullable. A campaign is usually run by one brand and
            // that is worth recording, but a generic campaign is legitimate.
            $table->foreignId('brand_id')->nullable()->constrained('brands')->restrictOnDelete();

            $table->string('code', 40);
            $table->string('name');

            // ⚠ C-CRM-8: Meta's own campaign id, preserved verbatim. Ours is
            // `code`; this one belongs to somebody else.
            $table->string('external_campaign_id')->nullable();

            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'code']);
            $table->index(['business_id', 'lead_source_id']);
            $table->index(['business_id', 'brand_id']);
        });

        // ── The prospect ────────────────────────────────────────────────────
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // ⚠⚠ C-CRM-16, and the reason `name` is NULLABLE on a table where it
            // is otherwise the obvious required field. Joy's D7: no retention
            // period is invented and nothing is auto-purged, but a future privacy
            // rule must be implementable WITHOUT destroying history. Every
            // personal field here is nullable so anonymisation can blank the
            // person while the lead row, its status, its campaign and its
            // attributions survive - campaign counts stay true and the person
            // disappears. Hard deletion would retrospectively change historical
            // campaign performance, which is the kind of rewrite this project
            // forbids everywhere else.
            $table->string('name')->nullable();
            $table->string('phone')->nullable();

            // ⚠⚠ C-CRM-12: DERIVED, FOR MATCHING ONLY. `phone` above keeps what
            // the user typed and is never silently rewritten. NOT unique -
            // households share numbers, and uniqueness would be both wrong and
            // unenforceable.
            $table->string('phone_normalized', 32)->nullable();

            $table->string('email')->nullable();
            $table->text('address')->nullable();

            // ⚠ C-CRM-11 / D4: nullable. LaLaDia's, Bionic-Garden's, or neither.
            $table->foreignId('brand_id')->nullable()->constrained('brands')->restrictOnDelete();

            // ⚠ C-CRM-9 / D1. `converted` is NOT settable from a form.
            $table->string('status', 20)->default('new');

            // ⚠⚠ C-CRM-2: a USER, not an employee - `employees` has no
            // business_id and is shared across businesses. Membership of a
            // business is the `business_user` PIVOT, so this cannot be validated
            // with a column-scoped exists rule. See LeadController.
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->restrictOnDelete();

            $table->text('qualification_note')->nullable();

            // ⚠ C-CRM-10 / D6: a `Reason` with context = 'lead_lost'. Validation
            // checks the context too, or a stock-adjustment reason could be
            // chosen as a lost reason.
            $table->foreignId('lost_reason_id')->nullable()->constrained('reasons')->restrictOnDelete();
            $table->timestamp('lost_at')->nullable();

            // ⚠⚠ C-CRM-5: THE ONE AND ONLY CONVERSION LINK. There is deliberately
            // no `customers.converted_from_lead_id`. Two links pointing at each
            // other is a consistency problem with no owner, and nothing here
            // would notice them disagreeing. The lead is the record that changes
            // state at conversion; most customers were never leads.
            $table->foreignId('converted_customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->timestamp('converted_at')->nullable();

            $table->text('note')->nullable();
            $table->timestamps();

            // ⚠ Sized for 5,000+ rows and for the questions actually asked:
            // who is unworked, who is mine, who matches this phone.
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'phone_normalized']);
            $table->index(['business_id', 'assigned_to_user_id']);
            $table->index(['business_id', 'converted_customer_id']);
            $table->index(['business_id', 'brand_id']);
            $table->index(['business_id', 'created_at']);
        });

        // ── Acquisition history ─────────────────────────────────────────────
        //
        // ⚠⚠ C-CRM-4: POLYMORPHIC OVER LEAD **AND** CUSTOMER. Joy's requirement
        // is that an existing customer responding to a later campaign is
        // recorded WITHOUT creating a second customer. A lead-only attribution
        // table cannot express that without inventing a fake lead for somebody
        // who is already a customer.
        //
        // ⚠⚠ C-CRM-7: APPEND-ONLY. Never updated, never deleted - enforced in
        // the model, not merely by convention. "Preserve the entire acquisition
        // history, not merely first and latest" is the whole point, and first and
        // latest are derived from this table rather than cached anywhere
        // (C-CRM-6).
        Schema::create('crm_attributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // ⚠ Explicit short index name. The generated name would be
            // `crm_attributions_business_id_subject_type_subject_id_index` at 58
            // characters - inside MySQL's 64 limit but close, and this schema
            // already carries one identifier at exactly 64.
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');

            $table->foreignId('lead_source_id')->constrained('lead_sources')->restrictOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->restrictOnDelete();

            // ⚠⚠ C-CRM-8: FREE TEXT, DELIBERATELY, and the one place free text is
            // correct. These are opaque strings owned by Meta. Normalising them
            // into our own taxonomy would mean maintaining a mirror of somebody
            // else's vocabulary that changes without notice. We preserve them
            // verbatim so nothing is thrown away, and group by `campaign_id`,
            // which is ours.
            $table->string('ad_set')->nullable();
            $table->string('ad_name')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('external_lead_id')->nullable();

            // Anything else the channel supplied, kept rather than discarded.
            $table->json('payload')->nullable();

            $table->timestamp('occurred_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'subject_type', 'subject_id'], 'crm_attr_subject_index');
            $table->index(['business_id', 'campaign_id'], 'crm_attr_campaign_index');
            $table->index(['business_id', 'lead_source_id'], 'crm_attr_source_index');
            $table->index(['business_id', 'occurred_at'], 'crm_attr_occurred_index');
        });

        // ── Three additive nullable columns on the LIVE customers table ──────
        //
        // ⛔ C-CRM-5 / R1: `customers` is NOT restructured. `sales_invoices`,
        // `deliveries` and `customer_receipts` all point at it with
        // restrictOnDelete. Additive and nullable only, and deliberately NO
        // `converted_from_lead_id` and NO `first_source_id`/`first_campaign_id`.
        Schema::table('customers', function (Blueprint $table) {
            // ⚠ Optional, never required, and with NO VAT dependency. Storing a
            // tax identifier is recording an attribute of the customer; VAT
            // treatment is how a sale is taxed. Nothing in the sales engine
            // reads these. An individual consumer is never asked for a BIN.
            $table->string('bin')->nullable()->after('email');
            $table->string('tin')->nullable()->after('bin');

            // ⚠ C-CRM-12: the same matching key as leads, so duplicate detection
            // can search across both in one pass.
            $table->string('phone_normalized', 32)->nullable()->after('phone');
            $table->index(['business_id', 'phone_normalized'], 'customers_phone_normalized_index');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_phone_normalized_index');
            $table->dropColumn(['bin', 'tin', 'phone_normalized']);
        });

        Schema::dropIfExists('crm_attributions');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('lead_sources');
    }
};
