<?php

declare(strict_types=1);

namespace AiWorkflow;

use AiWorkflow\Console\EvalRunCommand;
use AiWorkflow\Console\PromptTestCommand;
use AiWorkflow\Eval\AiWorkflowEvalRunner;
use AiWorkflow\Events\AiWorkflowRequestCompleted;
use AiWorkflow\Events\AiWorkflowRequestFailed;
use AiWorkflow\Listeners\SentryBreadcrumbListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AiWorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-workflow.php', 'ai-workflow');

        $this->app->singleton(AiService::class);
        $this->app->singleton(PromptService::class);
        $this->app->singleton(AiWorkflowReplayer::class);
        $this->app->singleton(AiWorkflowCache::class);
        $this->app->singleton(AiWorkflowEvalRunner::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ai-workflow.php' => config_path('ai-workflow.php'),
            ], 'ai-workflow-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'ai-workflow-migrations');

            $this->commands([
                EvalRunCommand::class,
                PromptTestCommand::class,
            ]);
        }

        if (function_exists('\Sentry\addBreadcrumb')) {
            Event::listen(AiWorkflowRequestCompleted::class, [SentryBreadcrumbListener::class, 'handleCompleted']);
            Event::listen(AiWorkflowRequestFailed::class, [SentryBreadcrumbListener::class, 'handleFailed']);
        }
    }
}
