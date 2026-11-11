<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Workspace billing profile = the buyer on invoices. gstin / state_code already exist on tenants.
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('legal_name', 150)->nullable();
            $table->string('address_line1', 150)->nullable();
            $table->string('address_line2', 150)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('pincode', 10)->nullable();
            $table->char('country', 2)->default('IN');
        });

        // Gapless counters: one row per seller, series and financial year, locked FOR UPDATE while an invoice is inserted.
        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('seller_key', 15);
            $table->string('series', 8);
            $table->string('financial_year', 7); // 2026-27
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['seller_key', 'series', 'financial_year']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete(); // issued invoices outlive everything
            $table->string('type', 16); // invoice | credit_note
            $table->string('series', 8);
            $table->string('financial_year', 7);
            $table->unsignedInteger('sequence');
            $table->string('number', 16);
            $table->string('seller_gstin', 15);
            $table->foreignId('original_invoice_id')->nullable()->constrained('invoices')->restrictOnDelete(); // credit notes
            $table->string('credit_reason', 255)->nullable();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payment_id')->nullable();
            $table->string('payment_method', 16); // gateway | manual
            $table->timestamp('issued_at');
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('supply_type', 16); // intra | inter | export
            $table->string('place_of_supply', 64); // "27-Maharashtra" or "Outside India"
            $table->json('seller'); // snapshots: an invoice never changes when the profile later does
            $table->json('buyer');
            $table->char('currency', 3)->default('INR');
            $table->boolean('prices_include_tax');
            $table->unsignedBigInteger('taxable_value'); // paise
            $table->unsignedBigInteger('cgst');
            $table->unsignedBigInteger('sgst');
            $table->unsignedBigInteger('igst');
            $table->unsignedBigInteger('total_tax');
            $table->unsignedBigInteger('total');
            $table->string('tax_note', 255)->nullable();
            $table->timestamps();

            $table->unique(['seller_gstin', 'number']);
            $table->unique(['seller_gstin', 'series', 'financial_year', 'sequence']);
            $table->unique(['type', 'payment_id']); // one invoice per gateway payment (NULLs do not collide)
            $table->index(['tenant_id', 'id']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('description', 255);
            $table->string('sac', 8);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price'); // paise (tax-inclusive when the invoice has prices_include_tax)
            $table->unsignedBigInteger('discount');   // paise
            $table->unsignedBigInteger('taxable_value');
            $table->unsignedSmallInteger('rate_bp');  // 1800 = 18%
            $table->unsignedBigInteger('cgst');
            $table->unsignedBigInteger('sgst');
            $table->unsignedBigInteger('igst');
            $table->unsignedBigInteger('total');
            $table->timestamps();

            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_sequences');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['legal_name', 'address_line1', 'address_line2', 'city', 'pincode', 'country']);
        });
    }
};
