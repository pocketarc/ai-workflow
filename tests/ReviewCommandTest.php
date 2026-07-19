<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

class ReviewCommandTest extends TestCase
{
    public function test_it_prints_the_review_url_without_serving(): void
    {
        $this->artisan('ai-workflow:review')
            ->expectsOutputToContain('http://127.0.0.1:8099/ai-workflow/review')
            ->assertSuccessful();
    }

    public function test_an_ipv6_host_is_bracketed(): void
    {
        // `::1:8099` parses as neither a bind address nor a URL host.
        $this->artisan('ai-workflow:review', ['--host' => '::1'])
            ->expectsOutputToContain('http://[::1]:8099/ai-workflow/review')
            ->assertSuccessful();
    }

    public function test_an_already_bracketed_ipv6_host_is_not_double_bracketed(): void
    {
        $this->artisan('ai-workflow:review', ['--host' => '[::1]'])
            ->expectsOutputToContain('http://[::1]:8099/ai-workflow/review')
            ->assertSuccessful();
    }
}
