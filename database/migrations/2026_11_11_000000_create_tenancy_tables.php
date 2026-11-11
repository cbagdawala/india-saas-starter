<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->string('gstin', 15)->nullable();     // placeholder for the GST invoicing task
            $table->string('state_code', 2)->nullable(); // GST state code, same
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tenant_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16); // owner | admin | member
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
            $table->index(['user_id', 'tenant_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            // Deliberately no DB-level foreign key: on SQLite, adding one rebuilds `users`, and dropping the old table
            // cascades deletes into every table that references it (data loss on upgrade). ResolveTenant re-validates
            // this pointer against tenant_user on every request, so a stale value is harmless (403).
            $table->unsignedBigInteger('current_tenant_id')->nullable()->index();
        });

        Schema::create('tenant_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 16);
            $table->char('token_hash', 64)->unique(); // HMAC-SHA256 of the emailed token; the token itself is never stored
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_invitations');
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['current_tenant_id']);
            $table->dropColumn('current_tenant_id');
        });
        Schema::dropIfExists('tenant_user');
        Schema::dropIfExists('tenants');
    }
};
