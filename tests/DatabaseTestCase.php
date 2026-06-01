<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use Override;

abstract class DatabaseTestCase extends TestCase
{
    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('ai-workflow.logging.enabled', true);
    }
}
