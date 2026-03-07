<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_workflow_executions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('name');
        });

        Schema::create('ai_workflow_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('execution_id')->nullable();
            $table->string('prompt_id');
            $table->string('method');
            $table->string('provider');
            $table->string('model');
            $table->text('system_prompt');
            $table->json('messages');
            $table->text('response_text')->nullable();
            $table->json('structured_response')->nullable();
            $table->string('finish_reason')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->json('schema')->nullable();
            $table->text('error')->nullable();
            $table->json('metadata')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();

            $table->foreign('execution_id')
                ->references('id')
                ->on('ai_workflow_executions')
                ->nullOnDelete();

            $table->index('execution_id');
            $table->index('prompt_id');
            $table->index('model');
            $table->index('created_at');
        });

        Schema::create('ai_workflow_eval_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->json('models');
            $table->json('config')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_workflow_eval_scores', function (Blueprint $table): void {
            $table->id();
            $table->uuid('eval_run_id');
            $table->unsignedBigInteger('request_id');
            $table->string('model');
            $table->float('score');
            $table->json('details')->nullable();
            $table->text('response_text')->nullable();
            $table->json('structured_response')->nullable();
            $table->timestamps();

            $table->foreign('eval_run_id')
                ->references('id')
                ->on('ai_workflow_eval_runs')
                ->cascadeOnDelete();

            $table->foreign('request_id')
                ->references('id')
                ->on('ai_workflow_requests')
                ->cascadeOnDelete();

            $table->index(['eval_run_id', 'model']);
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_workflow_eval_scores');
        Schema::dropIfExists('ai_workflow_eval_runs');
        Schema::dropIfExists('ai_workflow_requests');
        Schema::dropIfExists('ai_workflow_executions');
    }
};
