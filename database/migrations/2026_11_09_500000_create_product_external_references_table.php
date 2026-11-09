<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kept apart from products.id so a platform's ID can never be mistaken for a local one.
        Schema::create('product_external_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('source', 50);
            $table->string('external_id', 191);
            $table->timestamps();

            $table->unique(['user_id', 'source', 'external_id']);
            $table->unique(['product_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_external_references');
    }
};
