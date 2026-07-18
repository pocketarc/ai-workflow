<?php

declare(strict_types=1);

use AiWorkflow\Http\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

// The UI is a stateful form: it needs session flashes, CSRF protection and
// route-model binding, all of which the web group provides.
Route::middleware('web')
    ->prefix('ai-workflow/review')
    ->name('ai-workflow.review.')
    ->group(function (): void {
        Route::get('/', [ReviewController::class, 'index'])->name('index');
        Route::get('/{aiWorkflowRequest}/input', [ReviewController::class, 'input'])->name('input');
        Route::post('/{aiWorkflowRequest}/annotate', [ReviewController::class, 'annotate'])->name('annotate');
    });
