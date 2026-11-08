<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trend_topics', function (Blueprint $table) {
            // How many of the workspace's own recent posts mention the topic, and how their
            // engagement compares with its average (100 = average); null when none do.
            $table->unsignedSmallInteger('own_posts')->default(0);
            $table->unsignedSmallInteger('own_ratio')->nullable();
        });

        Schema::table('trend_profiles', function (Blueprint $table) {
            // Instagram usernames of accounts to watch, without the @.
            $table->json('competitors')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('trend_topics', function (Blueprint $table) {
            $table->dropColumn(['own_posts', 'own_ratio']);
        });

        Schema::table('trend_profiles', function (Blueprint $table) {
            $table->dropColumn('competitors');
        });
    }
};
