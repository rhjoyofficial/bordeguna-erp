<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Things the business owns and uses rather than sells.
     *
     * ⚠⚠ R8 of the architecture review: a fixed asset bought through Purchasing
     * becomes STOCK and is expensed as cost of goods sold the moment it is
     * issued - so a delivery van would have gone through the profit and loss in
     * one month and vanished from the balance sheet. This is the register that
     * stops that, by giving an asset somewhere to live that is not inventory.
     */
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('asset_code', 30);
            $table->string('name');

            // ⚠ Which asset account it sits in - Furniture, Office Equipment,
            // Vehicles. Named directly for the same reason an expense voucher
            // names its expense account: the classification IS the content of
            // the record, and a parallel category list would be a second thing
            // to keep in step.
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();

            $table->date('acquired_on');
            $table->decimal('cost', 15, 4);

            // What it is expected to be worth at the end of its life. Only the
            // difference between cost and this is ever depreciated.
            $table->decimal('salvage_value', 15, 4)->default(0);
            $table->unsignedSmallInteger('useful_life_months');

            // ⚠ Depreciation may start later than acquisition - an asset bought
            // in March and commissioned in May earns nothing in between, and
            // charging it would put cost in a month the business had no use of
            // it.
            $table->date('depreciation_starts_on');

            $table->foreignId('money_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();

            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'asset_code'], 'fixed_asset_code_unique');
            $table->index(['business_id', 'acquired_on']);
        });

        Schema::create('fixed_asset_depreciations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('charged_on');
            $table->decimal('amount', 15, 4);

            $table->timestamps();

            // ⚠⚠ ONE CHARGE PER ASSET PER MONTH, enforced by the database.
            // Running the depreciation command twice in a month is the obvious
            // way to double a cost that nobody is watching, and an idempotence
            // check in code alone is one retry away from being wrong.
            $table->unique(['fixed_asset_id', 'year', 'month'], 'fixed_asset_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_depreciations');
        Schema::dropIfExists('fixed_assets');
    }
};
