<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('woocommerce_stores', function (Blueprint $table) {
            // Every existing store is a real one, so the default describes them correctly.
            $table->string('connection_type', 20)->default('live')->after('user_id');
            $table->boolean('simulated_outage')->default(false)->after('capabilities');
        });

        // Set only on local products created by a demo import. Real products keep NULL.
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('demo_store_id')->nullable()->after('user_id')->constrained('woocommerce_stores')->cascadeOnDelete();
        });

        // Stands in for the remote side of a demo store. Nothing here is a local product.
        Schema::create('woocommerce_demo_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('woocommerce_store_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('remote_id');
            $table->json('payload');
            $table->timestamps();

            $table->unique(['woocommerce_store_id', 'remote_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('woocommerce_demo_products');

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('demo_store_id');
        });

        Schema::table('woocommerce_stores', function (Blueprint $table) {
            $table->dropColumn(['connection_type', 'simulated_outage']);
        });
    }
};
