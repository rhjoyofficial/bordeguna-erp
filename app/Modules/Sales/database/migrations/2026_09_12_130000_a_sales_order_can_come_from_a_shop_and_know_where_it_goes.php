<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website Phase 2, slice 2.4: the checkout writes a real ERP sales order.
 *
 * ⚠⚠ A SALES ORDER STILL MOVES NO STOCK AND POSTS NO JOURNAL. Nothing in this
 * migration changes that and nothing may. The order gains a channel, a
 * destination and an agreed delivery charge - all facts about the promise, none
 * of them an accounting event. Stock leaves at delivery, revenue is earned at
 * invoicing, exactly as in Phase 1.
 *
 * ⭐⭐ THE DESTINATION IS A SALES FACT, NOT A WEBSITE ONE, and that is the whole
 * reason `sales_order_addresses` sits in this module rather than in Commerce.
 * LaLaDia put the address on the storefront side only: `orders/Create.tsx` never
 * collected a district, `AdminOrderController::store()` never validated one, and
 * every order taken over the PHONE therefore reached the courier with no routing
 * pair at all. The same shop's web checkout had required both since 31 August.
 * Putting the address where every channel can reach it closes that gap by
 * construction instead of waiting to repeat it.
 *
 * ⚠ Every column added here is NULLABLE, and on production every one of these
 * tables holds zero rows (measured 12 Sep: sales_orders 0, sales_order_lines 0,
 * carts 0). So this is additive by construction and not merely by intention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            // ⚠⚠ THE DIMENSIONS THE ARCHITECTURE ASKED FOR ON DAY ONE (D17):
            // "Order carries store_id, brand_id and channel from the first
            // migration... Retrofitting that later is how you end up unable to
            // answer 'what did Bionic earn' out of one set of books." The first
            // migration was 11 August and predates stores existing, so this IS
            // the retrofit - done while the table is still empty, which is the
            // only cheap moment it will ever have.
            //
            // ℹ brand is deliberately NOT copied here. It is answerable today
            // from `products.brand_id` on each line, and a dimension nothing
            // reads and nothing keeps in step is a column that will be wrong
            // the first time somebody trusts it. It belongs with the invoice
            // and journal dimensions, where the per-brand question is actually
            // asked. Named rather than quietly skipped.
            $table->foreignId('store_id')->nullable()->after('warehouse_id')
                ->constrained()->nullOnDelete();

            // ⚠ Null is not "unknown", it is INTERNAL - an order somebody typed
            // into the ERP, which is every order that exists. A default would
            // say the same thing, and a default on a column whose meaning is
            // "how did this reach us" invites a new channel to inherit it
            // silently. See SalesChannel.
            $table->string('channel', 20)->nullable()->after('store_id');

            // ⚠⚠ THE NUMBER THE CUSTOMER QUOTES, and it is NOT the order number.
            // `order_number` is SO-FY2026-0001: sequential across the whole
            // business, so it interleaves two shops' orders in one run and tells
            // anyone who reads two of them how many orders the company took in
            // between. A shop reference is prefixed per store (`stores.
            // order_prefix`, unique across every business because the shops
            // share one SSLCommerz merchant) and ends in a random tail.
            //
            // ⚠ Unique globally, not per business, for the same reason the
            // prefix is: a payment gateway reference has to identify one order
            // in the whole group.
            $table->string('shop_reference', 40)->nullable()->after('order_number');

            // ⚠⚠ FROZEN, exactly like `sales_order_lines.unit_price`. The cart
            // resolves delivery live so a corrected rate reaches every open
            // basket (DeliveryQuote), but an ORDER is an agreement: the charge
            // the customer accepted is the charge that stands, and a rate
            // changed next week must not rewrite it. Same rule as C-SO-4 for
            // the goods.
            //
            // ⛔ AND IT IS NOT YET BILLED. Invoicing does not read this column,
            // so a website order records a delivery charge that no invoice
            // carries and no journal posts. Delivery income needs its own
            // account mapping, which is the accountant's line - the same
            // outstanding question as COGS-at-dispatch. This is recorded here
            // rather than left as a surprise for whoever writes the invoice.
            $table->decimal('delivery_charge', 15, 4)->nullable()->after('expected_date');

            // How it will be paid, which for a COD order is the whole of what is
            // known at order time: nothing has been paid, and the receipt is a
            // separate event with its own money account. The second value is
            // already scheduled (SSLCommerz rides in behind this same order),
            // which is why this is a column and not a note.
            $table->string('payment_method', 20)->nullable()->after('delivery_charge');

            $table->unique('shop_reference', 'sales_orders_shop_reference_unique');
            $table->index(['business_id', 'channel'], 'sales_orders_business_channel_index');
            $table->index(['store_id', 'ordered_at'], 'sales_orders_store_date_index');
        });

        /**
         * Where the goods go. One row per order, and the order is the owner.
         *
         * ⚠ A separate table rather than six more columns on `sales_orders`,
         * because a counter sale has no destination and never will: the buyer
         * carried the jar out. Nullable columns on the order would make "no
         * address" and "an address nobody filled in" the same shape, and the
         * screens that book couriers have to tell those apart.
         */
        Schema::create('sales_order_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();

            // ⚠ The RECIPIENT, who is not always the buyer. A gift is a real
            // and common order on these shops, and a courier calls the person
            // at the door, not the person who paid.
            $table->string('recipient_name');
            $table->string('recipient_phone', 32);

            // ⚠⚠ DISTRICT AND THANA ARE BOTH REQUIRED, and that is a deliberate
            // 3 September decision on LaLaDia carried here: a courier needs the
            // pair to route a parcel, and an order with only a district is one
            // somebody has to telephone about. Area is optional because the
            // dataset's own coverage is uneven.
            $table->string('district', 100);
            $table->string('thana', 100);
            $table->string('area', 120)->nullable();

            // ⚠⚠ DERIVED SERVER-SIDE FROM THE PAIR, NEVER POSTED BY THE BROWSER.
            // Slice 2.2's rule: a postcode sent to the page is one a form can
            // send back, and then the shop is trusting the customer's arithmetic
            // about its own geography. Null when the dataset has no answer, which
            // is honest - a made-up postcode misroutes a parcel.
            $table->string('postcode', 10)->nullable();

            $table->string('address_line', 500);

            // "Second gate, ask for Karim" - what a rider actually needs and
            // what no structured field will ever hold.
            $table->string('landmark', 255)->nullable();

            $table->timestamps();

            $table->unique('sales_order_id', 'sales_order_addresses_order_unique');
            $table->index(['business_id', 'district'], 'sales_order_addresses_district_index');
        });

        Schema::table('carts', function (Blueprint $table) {
            // ⚠ Which order this basket became. `converted_at` already says
            // THAT it converted, which is what recovery reads; this says into
            // what, and it is also the idempotency key - a second submit of the
            // same basket finds the order it already made rather than writing a
            // twin. A double-tapped button on a poor connection is not rare.
            $table->foreignId('sales_order_id')->nullable()->after('converted_at')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_order_id');
        });

        Schema::dropIfExists('sales_order_addresses');

        // ⚠⚠ THE FOREIGN KEY COMES OFF FIRST, AND THIS ORDER WAS MEASURED, NOT
        // REASONED. The first version dropped the indexes first and MySQL
        // refused with error 1553, "Cannot drop index
        // 'sales_orders_store_date_index': needed in a foreign key constraint"
        // - that index leads on `store_id`, so it is the index the constraint
        // is using. SQLite rebuilds a table on an ALTER and never says a word
        // about either, so the whole suite was green against a down() that
        // could not run on production. Exactly the 11 August error-1091
        // failure in a new disguise, found the same way: by running it.
        //
        // ⚠ And a failed rollback does not undo itself. That first attempt had
        // already dropped `sales_order_addresses` and the carts column before
        // it hit the index, and the migrations table still called the migration
        // applied - a half-rolled-back database that reports as whole.
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex('sales_orders_store_date_index');
            $table->dropIndex('sales_orders_business_channel_index');
            $table->dropUnique('sales_orders_shop_reference_unique');
            $table->dropColumn(['store_id', 'channel', 'shop_reference', 'delivery_charge', 'payment_method']);
        });
    }
};
