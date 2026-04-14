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
            $table->unsignedInteger('http_status')->nullable()->after('error');
            $table->longText('response_body')->nullable()->after('http_status');
            $table->string('error_class', 255)->nullable()->after('response_body');
        });
    }

    public function down(): void
    {
        Schema::table('ai_workflow_requests', function (Blueprint $table): void {
            $table->dropColumn(['error_class', 'response_body', 'http_status']);
        });
    }
};
