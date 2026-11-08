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
        Schema::create('inbox_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('inbox_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('remote_id', 191);
            $table->string('author_name')->nullable();
            $table->string('author_username')->nullable();
            $table->text('author_avatar_url')->nullable();
            $table->boolean('is_own')->default(false);
            $table->text('body')->nullable();
            $table->json('attachments')->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['inbox_conversation_id', 'remote_id']);
            $table->index(['inbox_conversation_id', 'sent_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inbox_messages');
    }
};
