<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_workflow_annotations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->string('verdict');
            $table->string('label')->nullable();
            $table->text('reason')->nullable();
            $table->string('reviewer')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('request_id')
                ->references('id')
                ->on('ai_workflow_requests')
                ->cascadeOnDelete();

            // The latest annotation per request is the current one; this index
            // serves both "history for a request" and verdict filtering.
            $table->index(['request_id', 'verdict']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_workflow_annotations');
    }
};
