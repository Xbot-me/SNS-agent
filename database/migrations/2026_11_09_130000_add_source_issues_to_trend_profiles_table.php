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
            // The sources the last analysis could not read: source key => permission | failed.
            $table->json('source_issues')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('trend_profiles', function (Blueprint $table) {
            $table->dropColumn('source_issues');
        });
    }
};
