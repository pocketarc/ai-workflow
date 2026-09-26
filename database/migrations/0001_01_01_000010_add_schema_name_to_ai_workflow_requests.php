<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_workflow_requests', function (Blueprint $table): void {
            $table->string('schema_name')->nullable()->after('schema');
        });
    }

    public function down(): void
    {
        Schema::table('ai_workflow_requests', function (Blueprint $table): void {
            $table->dropColumn('schema_name');
        });
    }
};
