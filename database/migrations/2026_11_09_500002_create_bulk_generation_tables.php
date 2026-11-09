<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_generation_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('submission_token');
            $table->string('status', 30);
            $table->json('preferences');
            $table->text('custom_instructions')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'submission_token']);
        });

        Schema::create('bulk_generation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bulk_generation_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_generation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('language', 10);

            // Whether Gemini produced content is tracked apart from whether a person approved it.
            $table->string('status', 20);
            $table->string('review_status', 20)->nullable();

            $table->string('error_code', 50)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->json('edited_content')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['bulk_generation_batch_id', 'product_id', 'language'], 'bulk_items_batch_product_language_unique');
            $table->index(['user_id', 'status', 'review_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_generation_items');
        Schema::dropIfExists('bulk_generation_batches');
    }
};
