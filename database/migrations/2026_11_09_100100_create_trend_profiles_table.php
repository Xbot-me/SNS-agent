<?php

declare(strict_types=1);

use App\Enums\Trend\Goal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trend_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('niche', 120);
            $table->string('audience')->nullable();
            $table->string('goal')->default(Goal::DEFAULT->value);
            $table->json('keywords');
            $table->json('excluded_keywords');
            $table->json('formats');
            $table->unsignedTinyInteger('posts_per_week')->default(3);
            $table->timestamp('onboarded_at')->nullable();
            $table->timestamp('analysis_requested_at')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('trend_profile_rss_feed', function (Blueprint $table) {
            $table->foreignUuid('trend_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('rss_feed_id')->constrained()->cascadeOnDelete();

            $table->primary(['trend_profile_id', 'rss_feed_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trend_profile_rss_feed');
        Schema::dropIfExists('trend_profiles');
    }
};
