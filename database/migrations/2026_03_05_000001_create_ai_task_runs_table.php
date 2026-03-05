<?php

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
        Schema::create('ai_task_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_conversation_id')
                ->constrained('ai_conversations')
                ->cascadeOnDelete();
            $table->string('goal', 1200);
            $table->string('status')->default('ready');
            $table->unsignedInteger('current_step_index')->default(0);
            $table->json('plan')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['ai_conversation_id', 'status']);
            $table->index(['ai_conversation_id', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_task_runs');
    }
};

