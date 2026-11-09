<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('language', 10);
            $table->string('title')->nullable();
            $table->text('short_description')->nullable();
            $table->mediumText('long_description')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->string('slug')->nullable();
            $table->json('keywords')->nullable();
            $table->string('source', 20)->default('manual');
            $table->timestamps();

            $table->unique(['product_id', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_contents');
    }
};
