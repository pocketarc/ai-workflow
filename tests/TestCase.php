<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\AiWorkflowServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Override;
use Prism\Prism\PrismServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;

abstract class TestCase extends BaseTestCase
{
    /**
     * @return list<class-string>
     */
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [
            PrismServiceProvider::class,
            AiWorkflowServiceProvider::class,
            LaravelDataServiceProvider::class,
        ];
    }

    #[Override]
    protected function defineEnvironment($app): void
    {
        $app['config']->set('ai-workflow.prompts_path', __DIR__.'/Fixtures/prompts');
        $app['config']->set('data.structure_caching.enabled', false);
    }
}
