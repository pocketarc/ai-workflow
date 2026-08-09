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
        // The foreign key on request_id has no index of its own. It leans on
        // the leftmost column of the composite index, and MySQL and MariaDB
        // refuse to drop an index a constraint still needs:
        //
        //   SQLSTATE[HY000]: 1553 Cannot drop index
        //   'ai_workflow_annotations_request_id_verdict_index': needed in a
        //   foreign key constraint
        //
        // So the key gets an index of its own before the composite goes.
        // SQLite enforces none of this, which is why the tests below pass
        // whichever order these run in.
        Schema::table('ai_workflow_annotations', function (Blueprint $table): void {
            $table->index('request_id');
        });

        Schema::table('ai_workflow_annotations', function (Blueprint $table): void {
            $table->dropIndex(['request_id', 'verdict']);
            $table->dropColumn('verdict');
        });
    }

    public function down(): void
    {
        Schema::table('ai_workflow_annotations', function (Blueprint $table): void {
            // Every restored row takes the default, because no verdict can be
            // recovered: compare a label to its request's recorded pick to tell
            // an approval from a correction.
            $table->string('verdict')->default('up');
            $table->index(['request_id', 'verdict']);
        });

        // Dropped last, for the same reason it was added first.
        Schema::table('ai_workflow_annotations', function (Blueprint $table): void {
            $table->dropIndex(['request_id']);
        });
    }
};
