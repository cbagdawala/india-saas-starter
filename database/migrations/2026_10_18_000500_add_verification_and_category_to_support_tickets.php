<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->string('category', 16)->default('other')->after('body_text'); // billing | account | bug | feature | other (keyword match)
            $table->boolean('sender_verified')->default(false)->after('category'); // form = signed-in user; email = trusted DMARC/DKIM/SPF pass
        });

        // signups/day and signups per variant (metrics summary) in one index
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->index(['created_at', 'landing_variant']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['created_at', 'landing_variant']);
            $table->index('created_at');
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn(['category', 'sender_verified']);
        });
    }
};
