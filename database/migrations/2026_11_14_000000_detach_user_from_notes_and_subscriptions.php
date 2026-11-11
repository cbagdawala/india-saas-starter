<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billing and notes belong to the workspace, not to the person who happened to create them. Deleting a user must not
 * delete a workspace's subscription (or, silently, its notes): user_id becomes nullable with ON DELETE SET NULL.
 *
 * SQLite rebuilds a table to change a column, and dropping the old `subscriptions` table would set the invoices'
 * subscription_id to NULL, so those links are saved and restored around the change (a no-op on MySQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        $links = DB::table('invoices')->whereNotNull('subscription_id')->pluck('subscription_id', 'id')->all();

        foreach (['subscriptions', 'notes'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->change();
            });
            Schema::table($name, function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        foreach ($links as $invoiceId => $subscriptionId) {
            DB::table('invoices')->where('id', $invoiceId)->update(['subscription_id' => $subscriptionId]);
        }
    }

    /** Only possible while no row has a NULL user_id. */
    public function down(): void
    {
        $links = DB::table('invoices')->whereNotNull('subscription_id')->pluck('subscription_id', 'id')->all();

        foreach (['subscriptions', 'notes'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable(false)->change();
            });
            Schema::table($name, function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        foreach ($links as $invoiceId => $subscriptionId) {
            DB::table('invoices')->where('id', $invoiceId)->update(['subscription_id' => $subscriptionId]);
        }
    }
};
