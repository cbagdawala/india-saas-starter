<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A/B slots for the public landing page; managed through LandingVariantSeeder or LandingService::upsert().
        Schema::create('landing_variants', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('hero_headline', 160);
            $table->string('hero_sub', 400)->nullable();
            $table->string('cta_label', 40)->nullable();
            $table->string('pricing_blurb', 300)->nullable();
            $table->json('features')->nullable(); // [{title, body}]
            $table->json('faq')->nullable();      // [{q, a}]
            $table->string('meta_title', 70)->nullable();
            $table->string('meta_description', 170)->nullable();
            $table->unsignedSmallInteger('weight')->default(1); // share of traffic among active variants
            $table->boolean('active')->default(false)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_variants');
    }
};
