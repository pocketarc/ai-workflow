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
            $table->unsignedInteger('cache_read_tokens')->nullable()->after('thought_tokens');
            $table->unsignedInteger('cache_write_tokens')->nullable()->after('cache_read_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('ai_workflow_eval_scores', function (Blueprint $table): void {
            $table->dropColumn(['cache_read_tokens', 'cache_write_tokens']);
        });
    }
};
