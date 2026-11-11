<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Revoked / replaced invitations stay (soft deleted) so the daily invitation cap cannot be reset by revoke + re-invite.
        Schema::table('tenant_invitations', function (Blueprint $table) {
            $table->softDeletes();
        });

        // Time of the last webhook event applied to the subscription: older events are ignored (out-of-order delivery, replay).
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('last_event_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('last_event_at');
        });
        Schema::table('tenant_invitations', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
