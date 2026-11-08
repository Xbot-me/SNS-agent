<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An assisted channel has no API token: the user publishes each post
     * themselves in a browser window the assisted posting helper fills in.
     */
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->boolean('is_assisted')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->dropColumn('is_assisted');
        });
    }
};
