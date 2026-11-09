<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('operation', 10);
            $table->string('file_type', 10);
            $table->string('original_name')->nullable();
            $table->string('stored_path')->nullable();
            $table->string('status', 30);
            $table->unsignedInteger('total_records')->default(0);
            $table->unsignedInteger('successful_records')->default(0);
            $table->unsignedInteger('failed_records')->default(0);
            $table->json('options')->nullable();
            $table->json('summary')->nullable();
            $table->string('error_summary', 500)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('expires_at');
        });

        // Rows wait here until the user confirms, so nothing reaches products before that.
        Schema::create('catalog_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_transfer_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('first_row');
            $table->unsignedInteger('row_count')->default(1);
            $table->string('status', 10);
            $table->json('payload')->nullable();
            $table->json('errors')->nullable();
            $table->string('match_type', 10)->nullable();
            $table->string('result', 10)->nullable();
            $table->string('result_message', 500)->nullable();

            $table->index(['catalog_transfer_id', 'status', 'result']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_import_rows');
        Schema::dropIfExists('catalog_transfers');
    }
};
