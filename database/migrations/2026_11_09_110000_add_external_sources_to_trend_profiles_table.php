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
            // Country Google Trends is read for.
            $table->string('region', 2)->default('US');
            // Which external sources the workspace uses; null is none (feeds only).
            $table->json('external_sources')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('trend_profiles', function (Blueprint $table) {
            $table->dropColumn(['region', 'external_sources']);
        });
    }
};
