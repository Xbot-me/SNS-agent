<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trend_profiles', function (Blueprint $table) {
            // Threads topic tags (a community's tag, without the #) and Threads usernames to watch.
            $table->json('threads_topics')->nullable();
            $table->json('threads_creators')->nullable();
        });

        Schema::table('trend_topics', function (Blueprint $table) {
            // How many of the topic's posts used each format (format => count), when the source said.
            $table->json('top_formats')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('trend_profiles', function (Blueprint $table) {
            $table->dropColumn(['threads_topics', 'threads_creators']);
        });

        Schema::table('trend_topics', function (Blueprint $table) {
            $table->dropColumn('top_formats');
        });
    }
};
