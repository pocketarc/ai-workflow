<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Override;

abstract class DatabaseTestCase extends TestCase
{
    use RefreshDatabase;

    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('ai-workflow.logging.enabled', true);
    }

    #[Override]
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
