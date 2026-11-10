<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive only. No existing row changes, so everyone starts on the default plan
     * with an empty counter: past AI requests aren't treated as this month's usage.
     */
    public function up(): void
    {
        // One row per user and month. The conditional update on it is what makes quotas safe.
        Schema::create('usage_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('period', 7);
            $table->unsignedInteger('used')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'period']);
        });

        Schema::create('usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('action', 40);
            $table->unsignedInteger('quantity');
            $table->string('plan', 30);
            $table->char('period', 7);
            $table->string('status', 12);

            // A retry of the same work finds this row instead of counting twice.
            $table->string('idempotency_key', 100);

            // Plain values, so usage history outlives the records it describes.
            $table->string('subject_type', 30)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->boolean('simulated')->default(false);
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'period', 'status']);
        });

        // Separate from Cashier's tables, so demo records can never be read as real billing.
        Schema::create('demo_billing_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('reference', 30)->unique();
            $table->string('plan', 30);
            $table->string('status', 20);
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('past_due_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('demo_billing_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 30)->unique();
            $table->string('type', 30);
            $table->string('plan', 30);
            $table->unsignedInteger('amount_cents')->default(0);
            $table->char('currency', 3);
            $table->string('status', 20);
            $table->string('description');
            $table->timestamps();

            $table->index(['user_id', 'id']);
        });

        Schema::create('billing_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_event_id')->unique();
            $table->string('type', 100);
            $table->string('object_id')->nullable()->index();
            $table->unsignedBigInteger('event_created_at');
            $table->string('status', 20);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        // Cashier keeps only the current status, and the grace period needs the start.
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('past_due_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('past_due_at');
        });

        Schema::dropIfExists('billing_webhook_events');
        Schema::dropIfExists('demo_billing_transactions');
        Schema::dropIfExists('demo_billing_subscriptions');
        Schema::dropIfExists('usage_events');
        Schema::dropIfExists('usage_counters');
    }
};
