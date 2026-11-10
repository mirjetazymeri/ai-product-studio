<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('operation', 191);

            // Hashes only: neither the caller's key nor its request body is kept.
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);

            $table->string('status', 20);
            $table->unsignedSmallInteger('response_status')->nullable();

            // Encrypted by the model, because it can hold generated or analyzed text.
            $table->text('response')->nullable();

            $table->timestamp('locked_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique(['user_id', 'operation', 'key_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
    }
};
