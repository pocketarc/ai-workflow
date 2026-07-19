<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_workflow_eval_scores', function (Blueprint $table): void {
            // Replay cost/latency, so reports can weigh quality against price.
            $table->unsignedInteger('input_tokens')->nullable()->after('structured_response');
            $table->unsignedInteger('output_tokens')->nullable()->after('input_tokens');
            $table->unsignedInteger('duration_ms')->nullable()->after('output_tokens');

            // Classification labels, so a report can build a confusion matrix
            // without knowing anything about the judge that produced the score.
            $table->string('ground_truth')->nullable()->after('duration_ms');
            $table->string('predicted')->nullable()->after('ground_truth');
        });
    }

    public function down(): void
    {
        Schema::table('ai_workflow_eval_scores', function (Blueprint $table): void {
            $table->dropColumn(['input_tokens', 'output_tokens', 'duration_ms', 'ground_truth', 'predicted']);
        });
    }
};
