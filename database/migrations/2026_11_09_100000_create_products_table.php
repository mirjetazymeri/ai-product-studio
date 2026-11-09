<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('sku', 100)->nullable();
            $table->string('category', 120)->nullable();
            $table->string('material', 120)->nullable();
            $table->text('details')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamps();

            // A SKU identifies one product within a user's catalogue. NULLs do not collide.
            $table->unique(['user_id', 'sku']);
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
