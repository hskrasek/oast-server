<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Migrations\AiMigration;

return new class extends AiMigration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $conversations = config('ai.conversations.tables.conversations', 'agent_conversations');
        $conversationsTable = is_string($conversations) ? $conversations : 'agent_conversations';

        $messages = config('ai.conversations.tables.messages', 'agent_conversation_messages');
        $messagesTable = is_string($messages) ? $messages : 'agent_conversation_messages';

        Schema::create($conversationsTable, function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->foreignId('user_id')->nullable();
            $table->string('title');
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });

        Schema::create($messagesTable, function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->string('conversation_id', 36)->index();
            $table->foreignId('user_id')->nullable();
            $table->string('agent');
            $table->string('role', 25);
            $table->text('content');
            $table->text('attachments');
            $table->text('tool_calls');
            $table->text('tool_results');
            $table->text('usage');
            $table->text('meta');
            $table->timestamps();

            $table->index(['conversation_id', 'user_id', 'updated_at'], 'conversation_index');
            $table->index(['user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $messages = config('ai.conversations.tables.messages', 'agent_conversation_messages');
        $conversations = config('ai.conversations.tables.conversations', 'agent_conversations');

        Schema::dropIfExists(is_string($messages) ? $messages : 'agent_conversation_messages');
        Schema::dropIfExists(is_string($conversations) ? $conversations : 'agent_conversations');
    }
};
