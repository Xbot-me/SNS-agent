<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inbox_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 32);
            $table->string('kind', 16);
            $table->string('remote_id', 191);
            $table->string('participant_name')->nullable();
            $table->string('participant_username')->nullable();
            $table->text('participant_avatar_url')->nullable();
            $table->text('subject')->nullable();
            $table->text('permalink')->nullable();
            $table->text('last_message_excerpt')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['social_account_id', 'kind', 'remote_id']);
            $table->index(['workspace_id', 'last_message_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inbox_conversations');
    }
};
