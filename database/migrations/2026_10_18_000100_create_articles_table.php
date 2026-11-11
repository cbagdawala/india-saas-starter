<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SEO articles (blog). Body is Markdown, rendered server-side with raw HTML stripped.
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('title', 200);
            $table->string('excerpt', 500)->nullable();
            $table->mediumText('body_markdown');
            $table->string('status', 16)->default('draft'); // draft | published
            $table->timestamp('published_at')->nullable();
            $table->string('meta_title', 70)->nullable();
            $table->string('meta_description', 170)->nullable();
            $table->string('author', 64)->default('editorial');
            $table->timestamps();

            // public listing: status = published AND published_at <= now ORDER BY published_at DESC
            $table->index(['status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
