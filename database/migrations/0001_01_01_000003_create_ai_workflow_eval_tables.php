<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
            $table->timestamp('created_at')->nullable();

            $table->foreign('eval_run_id')
                ->references('id')
                ->on('ai_workflow_eval_runs')
                ->cascadeOnDelete();

            $table->foreign('request_id')
                ->references('id')
                ->on('ai_workflow_requests')
                ->cascadeOnDelete();

            $table->index('eval_run_id');
            $table->index('request_id');
            $table->index('model');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_workflow_eval_scores');
        Schema::dropIfExists('ai_workflow_eval_runs');
    }
};
