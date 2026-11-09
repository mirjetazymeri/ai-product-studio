<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('woocommerce_stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('url');

            // Encrypted by the model. Both are removed when a store is disconnected.
            $table->text('consumer_key')->nullable();
            $table->text('consumer_secret')->nullable();

            $table->string('status', 20)->default('untested');
            $table->string('last_error_code', 50)->nullable();
            $table->string('content_language', 10)->nullable();
            $table->json('capabilities')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_import_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'url']);
        });

        Schema::create('woocommerce_product_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('woocommerce_store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // Remote IDs are only unique within one store.
            $table->unsignedBigInteger('remote_id');
            $table->string('remote_type', 30);
            $table->string('sync_mode', 20);
            $table->string('remote_sku')->nullable();
            $table->string('remote_status', 30)->nullable();
            $table->string('remote_modified_gmt', 40)->nullable();
            $table->string('remote_language', 10)->nullable();
            $table->string('permalink', 500)->nullable();
            $table->json('translations')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_imported_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['woocommerce_store_id', 'remote_id']);
            $table->unique(['woocommerce_store_id', 'product_id']);
            $table->index(['user_id', 'woocommerce_store_id']);
        });

        Schema::create('woocommerce_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('woocommerce_store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rollback_of_id')->nullable()->constrained('woocommerce_sync_runs')->nullOnDelete();
            $table->string('language', 10);
            $table->string('status', 30);
            $table->string('error_summary', 500)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['woocommerce_store_id', 'status']);
        });

        Schema::create('woocommerce_sync_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('woocommerce_sync_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('woocommerce_product_link_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rollback_of_item_id')->nullable()->constrained('woocommerce_sync_items')->nullOnDelete();
            $table->unsignedBigInteger('remote_id')->nullable();
            $table->string('product_name');
            $table->string('language', 10);
            $table->string('status', 30);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->json('fields')->nullable();
            $table->json('previous_values')->nullable();
            $table->json('applied_values')->nullable();
            $table->json('conflicts')->nullable();
            $table->string('error_code', 50)->nullable();
            $table->string('message', 500)->nullable();
            $table->timestamps();

            $table->index(['woocommerce_sync_run_id', 'status']);
        });

        // Lets repeated pages of a store listing stage each product only once.
        Schema::table('catalog_import_rows', function (Blueprint $table) {
            $table->string('external_key', 64)->nullable()->after('catalog_transfer_id');
            $table->unique(['catalog_transfer_id', 'external_key']);
        });
    }

    public function down(): void
    {
        Schema::table('catalog_import_rows', function (Blueprint $table) {
            $table->dropUnique(['catalog_transfer_id', 'external_key']);
            $table->dropColumn('external_key');
        });

        Schema::dropIfExists('woocommerce_sync_items');
        Schema::dropIfExists('woocommerce_sync_runs');
        Schema::dropIfExists('woocommerce_product_links');
        Schema::dropIfExists('woocommerce_stores');
    }
};
