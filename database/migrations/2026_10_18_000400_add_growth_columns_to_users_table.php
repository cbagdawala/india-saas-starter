<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('landing_variant', 64)->nullable()->after('remember_token'); // A/B attribution of the signup
            $table->timestamp('onboarded_at')->nullable()->after('landing_variant');
            $table->index('created_at'); // signups/day aggregates
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropColumn(['landing_variant', 'onboarded_at']);
        });
    }
};
