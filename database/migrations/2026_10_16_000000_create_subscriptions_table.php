<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('plan');
            $table->string('status')->default('created')->index();
            $table->string('gateway', 32);
            $table->string('gateway_subscription_id')->unique();
            $table->unsignedInteger('amount'); // paise
            $table->char('currency', 3)->default('INR');
            $table->string('last_payment_id')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        // Webhook idempotency ledger: one row per delivered event id.
        Schema::create('billing_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('type');
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_webhook_events');
        Schema::dropIfExists('subscriptions');
    }
};
