<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A review records one thing: the answer that was correct. Whether that amounts
 * to a correction follows from comparing it to the pick the model made, so a
 * separate thumbs up/down stored alongside it was a second copy of the same
 * fact, and the two disagreed whenever someone clicked the wrong button.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_workflow_annotations', function (Blueprint $table): void {
            $table->dropIndex(['request_id', 'verdict']);
            $table->dropColumn('verdict');

            // The composite index covered "history for a request"; that lookup
            // outlives the column it was sharing an index with.
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_workflow_annotations', function (Blueprint $table): void {
            $table->dropIndex(['request_id']);

            // Every restored row takes the default, because no verdict can be
            // recovered: compare a label to its request's recorded pick to tell
            // an approval from a correction.
            $table->string('verdict')->default('up');
            $table->index(['request_id', 'verdict']);
        });
    }
};
