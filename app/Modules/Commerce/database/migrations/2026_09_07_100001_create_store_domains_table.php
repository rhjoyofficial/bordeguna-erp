<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hostnames a store answers on.
 *
 * ⛔ `hostname` is unique across every business, with no scope on it at all,
 * and it has to be. The storefront resolves an incoming request by host BEFORE
 * it knows which business or store it is serving, so there is no tenant context
 * to scope by at that moment. Two rows claiming bionic.garden would make the
 * answer depend on row order, which is the kind of defect that only appears in
 * production and only intermittently.
 *
 * ⚠ A store has one PRIMARY hostname and any number of alternates. Alternates
 * exist to be redirected, not to be served: www.laladia.com is a CNAME to the
 * apex today and www served the ENTIRE site at 200 until a canonical-host
 * middleware was added on 5 September. Two complete copies of a shop, each
 * claiming to be canonical, is what produced 59 "alternate page with proper
 * canonical" exclusions in Search Console. So an alternate is stored as a real
 * row and answered with a 301, deliberately, rather than left to DNS.
 *
 * ⚠ Hostnames are stored lower-cased and without a port. Host headers are
 * case-insensitive and a mixed-case Host would otherwise miss the lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();

            $table->string('hostname', 253);

            // Exactly one primary per store. Enforced in the model, because
            // MySQL has no partial unique index to say "unique where true".
            $table->boolean('is_primary')->default(false);

            $table->timestamps();

            $table->unique('hostname');
            $table->index(['store_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_domains');
    }
};
