<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customer PII (sender email/name, body) stays here. Keep it out of events and logs.
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 16); // email | form
            $table->string('message_id')->nullable()->unique(); // inbound idempotency (email Message-ID)
            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->string('subject', 255);
            $table->text('body_text');
            $table->string('status', 16)->default('open'); // open | pending | closed
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['user_id', 'id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
