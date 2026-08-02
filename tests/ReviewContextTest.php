<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\Eval\EvalReportRenderer;
use AiWorkflow\Eval\ReviewContext;
use AiWorkflow\Eval\ReviewContextLookup;
use AiWorkflow\Models\AiWorkflowEvalRun;
use AiWorkflow\Models\AiWorkflowEvalScore;
use AiWorkflow\Models\AiWorkflowRequest;
use AiWorkflow\Tests\Fixtures\StubReviewContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

class ReviewContextTest extends DatabaseTestCase
{
    /**
     * @return list<array{string}>
     */
    public static function unsafeUrls(): array
    {
        return [
            ['javascript:alert(1)'],
            ['JavaScript:alert(1)'],
            ['data:text/html,<script>alert(1)</script>'],
            ['vbscript:msgbox(1)'],
        ];
    }

    #[DataProvider('unsafeUrls')]
    public function test_it_rejects_a_link_that_could_run_on_click(string $url): void
    {
        // Blade escapes the href, but these schemes need no escaping to run,
        // and the report is a file someone opens and clicks around in.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must be http, https or relative');

        new ReviewContext(links: [['label' => 'Looks fine', 'url' => $url]]);
    }

    /**
     * @return list<array{string}>
     */
    public static function safeUrls(): array
    {
        return [
            ['https://example.test/issues/1'],
            ['http://example.test/issues/1'],
            ['//example.test/issues/1'],
            ['/agent/tickets/123'],
        ];
    }

    #[DataProvider('safeUrls')]
    public function test_it_accepts_a_link_a_browser_can_only_navigate_to(string $url): void
    {
        $context = new ReviewContext(links: [['label' => 'Ticket', 'url' => $url]]);

        $this->assertSame($url, $context->links[0]['url']);
    }

    public function test_it_reports_a_configured_class_that_is_not_a_resolver(): void
    {
        Exceptions::fake();

        config(['ai-workflow.review.context' => stdClass::class]);

        $request = $this->makeRequest();

        // Silently disabling context leaves the host app with no way to tell a
        // misconfiguration from a decision that genuinely has no records.
        $this->assertSame([], app(ReviewContextLookup::class)->for([$request]));

        Exceptions::assertReported(LogicException::class);
    }

    public function test_it_is_not_configured_when_the_class_does_not_exist(): void
    {
        config(['ai-workflow.review.context' => 'App\\Eval\\GoneAway']);

        $this->assertFalse(app(ReviewContextLookup::class)->isConfigured());
    }

    public function test_the_report_does_not_query_for_requests_when_no_resolver_is_configured(): void
    {
        config(['ai-workflow.review.context' => null]);

        $run = $this->seedScoredRun();

        DB::enableQueryLog();
        app(EvalReportRenderer::class)->render($run);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Every report would otherwise pay for a lookup whose result is
        // discarded, on the default path where no resolver exists.
        $contextQueries = array_filter(
            $queries,
            static fn (array $query): bool => str_contains((string) $query['query'], 'execution_id'),
        );

        $this->assertSame([], $contextQueries);
    }

    public function test_the_report_does_query_for_requests_when_a_resolver_is_configured(): void
    {
        config(['ai-workflow.review.context' => StubReviewContext::class]);

        $run = $this->seedScoredRun();

        DB::enableQueryLog();
        app(EvalReportRenderer::class)->render($run);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $contextQueries = array_filter(
            $queries,
            static fn (array $query): bool => str_contains((string) $query['query'], 'execution_id'),
        );

        $this->assertNotSame([], $contextQueries);
    }

    private function makeRequest(): AiWorkflowRequest
    {
        return AiWorkflowRequest::create([
            'prompt_id' => 'decide_next_action',
            'method' => 'sendStructuredMessages',
            'provider' => 'openrouter',
            'model' => 'test-model',
            'system_prompt' => 'Decide.',
            'messages' => [['type' => 'user', 'content' => 'Ticket body']],
            'finish_reason' => 'stop',
            'duration_ms' => 100,
        ]);
    }

    private function seedScoredRun(): AiWorkflowEvalRun
    {
        $run = AiWorkflowEvalRun::create(['name' => 'Context run', 'models' => ['openrouter:a']]);
        $request = $this->makeRequest();

        AiWorkflowEvalScore::create([
            'eval_run_id' => $run->id,
            'request_id' => $request->id,
            'model' => 'openrouter:a',
            'score' => 1.0,
            'ground_truth' => 'respond',
            'predicted' => 'respond',
            'input_tokens' => 10,
            'output_tokens' => 20,
            'duration_ms' => 100,
        ]);

        return $run;
    }
}
