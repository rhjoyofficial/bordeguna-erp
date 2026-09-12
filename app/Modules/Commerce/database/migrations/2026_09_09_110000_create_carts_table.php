<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A basket somebody has started on a shop.
 *
 * ⛔⛔ A TABLE, NOT A SESSION, AND THE REASON IS MEASURED. "Abandoned Carts" and
 * "Cart Recovery" are two of the 48 parity screens. On LaLaDia that feature was
 * built against carts that stored no contact of any kind: 873 abandoned carts,
 * 25 of them holding any items, and ZERO with a contactable owner. The recovery
 * campaign had no recipients. A session would repeat that exactly, and would
 * also lose the basket when the cookie jar is cleared between two visits from
 * the same buyer.
 *
 * ⚠ The contact columns are filled at CHECKOUT, as typed, not at the first add.
 * Nobody types their phone number to look at a basket, so demanding it here
 * would cost sales to buy a recovery list. They are here rather than only on the
 * order because a cart that never becomes an order is precisely the one worth
 * recovering.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // ⚠ Scoped to the STORE, not only the business. Two shops in this
            // group may be open in two tabs of one browser, and a basket that
            // followed the buyer between them would put Bionic's honey in
            // LaLaDia's checkout.
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();

            // ⚠⚠ The public handle, and it is a RANDOM token rather than the id.
            // An incrementing id in a cookie is an invitation to read the next
            // person's basket by subtracting one, and a basket carries a name
            // and a phone number by the time it matters.
            $table->string('token', 64)->unique();

            // Filled at checkout, as typed. See the class comment.
            $table->string('contact_name')->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('contact_email')->nullable();

            // ⚠ Set when the cart becomes an order, so recovery can tell a
            // basket somebody abandoned from one that succeeded. Nullable
            // rather than a status word: there is one event, and its absence is
            // the other state.
            $table->timestamp('converted_at')->nullable();

            $table->timestamps();

            $table->index(['store_id', 'converted_at']);
            // Recovery reads "started, never converted, last touched before X".
            $table->index(['business_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
