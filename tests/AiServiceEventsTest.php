<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\AiService;
use AiWorkflow\Events\AiWorkflowRequestCompleted;
use AiWorkflow\Events\AiWorkflowRequestFailed;
use AiWorkflow\PromptData;
use Illuminate\Support\Facades\Event;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class AiServiceEventsTest extends TestCase
{
    private function makePrompt(): PromptData
    {
        return new PromptData(
            id: 'test',
            model: 'openrouter:test-model',
            prompt: 'You are a helpful assistant.',
        );
    }

    public function test_completed_event_dispatched_on_success(): void
    {
        Event::fake([AiWorkflowRequestCompleted::class]);

        Prism::fake([
            TextResponseFake::make()
                ->withText('Hello')
                ->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(AiService::class);
        $service->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());

        Event::assertDispatched(AiWorkflowRequestCompleted::class, function (AiWorkflowRequestCompleted $event): bool {
            return $event->method === 'sendMessages'
                && $event->model === 'test-model'
                && $event->finishReason === FinishReason::Stop
                && $event->durationMs > 0
                && $event->prompt->id === 'test';
        });
    }

    public function test_failed_event_dispatched_on_failure(): void
    {
        Event::fake([AiWorkflowRequestFailed::class]);

        Prism::fake([
            TextResponseFake::make()
                ->withText('Bad')
                ->withFinishReason(FinishReason::Unknown),
        ]);

        $service = app(AiService::class);

        try {
            $service->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());
        } catch (\Throwable) {
            // Expected.
        }

        Event::assertDispatched(AiWorkflowRequestFailed::class, function (AiWorkflowRequestFailed $event): bool {
            return $event->method === 'sendMessages'
                && $event->model === 'test-model'
                && $event->prompt->id === 'test';
        });
    }

    public function test_events_dispatched_even_when_logging_disabled(): void
    {
        config()->set('ai-workflow.logging.enabled', false);

        Event::fake([AiWorkflowRequestCompleted::class]);

        Prism::fake([
            TextResponseFake::make()
                ->withText('Hello')
                ->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(AiService::class);
        $service->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());

        Event::assertDispatched(AiWorkflowRequestCompleted::class);
    }
}
