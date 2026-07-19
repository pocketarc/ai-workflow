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
            // Providers bill thought tokens at the output rate, so a cost
            // report that ignores them understates the cost of exactly the
            // models an eval is most likely comparing.
            $table->unsignedInteger('thought_tokens')->nullable()->after('output_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('ai_workflow_eval_scores', function (Blueprint $table): void {
            $table->dropColumn('thought_tokens');
        });
    }
};
