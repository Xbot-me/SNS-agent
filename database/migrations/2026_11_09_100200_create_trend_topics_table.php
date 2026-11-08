<?php

declare(strict_types=1);

use App\Enums\Trend\TopicStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trend_topics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('key', 160);
            $table->string('label', 160);
            $table->unsignedInteger('score')->default(0);
            $table->unsignedSmallInteger('signal_count')->default(0);
            $table->unsignedSmallInteger('outlet_count')->default(0);
            $table->json('evidence');
            $table->json('formats');
            $table->string('status')->default(TopicStatus::DEFAULT->value);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['workspace_id', 'key']);
            $table->index(['workspace_id', 'status', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trend_topics');
    }
};
