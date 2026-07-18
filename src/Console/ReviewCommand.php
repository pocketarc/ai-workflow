<?php

declare(strict_types=1);

namespace AiWorkflow\Console;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class ReviewCommand extends Command
{
    /** Environment flag that switches the review routes on. */
    private const string REVIEW_ENV = 'AI_WORKFLOW_REVIEW';

    /** @var string */
    protected $signature = 'ai-workflow:review
        {--serve : Start a local web server for the review UI}
        {--host=127.0.0.1 : Host to bind the review server to}
        {--port=8099 : Port to bind the review server to}
        {--prompt= : Pre-filter the UI to one prompt id}
        {--all : Include requests that have already been reviewed}';

    /** @var string */
    protected $description = 'Review recorded AI responses and label them for the eval golden set.';

    public function handle(): int
    {
        $url = $this->reviewUrl();

        if ($this->option('serve') !== true) {
            $this->info('Review UI route: '.$url);
            $this->line('Set AI_WORKFLOW_REVIEW=true and serve the app, or re-run with --serve.');

            return self::SUCCESS;
        }

        $this->info("Starting the review UI at {$url}");
        $this->line('Press Ctrl+C to stop.');
        $this->newLine();

        return $this->serve();
    }

    private function serve(): int
    {
        $host = $this->stringOption('host', '127.0.0.1');
        $port = $this->stringOption('port', '8099');

        // Run PHP's dev server directly rather than going through `artisan
        // serve`, which forwards only an allowlist of variables to the server it
        // starts. An app configured from its process environment — a container
        // getting DB credentials from compose, say — loses that configuration on
        // the way through. Symfony inherits the full parent environment, so the
        // served app sees exactly what this command sees.
        //
        // index.php doubles as the router: this UI ships no static assets, so
        // routing every request through the app is fine.
        $process = new Process(
            [PHP_BINARY, '-S', $host.':'.$port, '-t', public_path(), public_path('index.php')],
            base_path(),
            // Keeps the routes dormant everywhere that has not asked for them.
            [self::REVIEW_ENV => 'true'],
        );

        // A review session lasts as long as the human needs.
        $process->setTimeout(null);

        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        return $process->isSuccessful() ? self::SUCCESS : self::FAILURE;
    }

    private function reviewUrl(): string
    {
        $host = $this->stringOption('host', '127.0.0.1');
        $port = $this->stringOption('port', '8099');

        $query = [];

        $prompt = $this->stringOption('prompt', '');
        if ($prompt !== '') {
            $query['prompt'] = $prompt;
        }

        if ($this->option('all') === true) {
            $query['all'] = '1';
        }

        $url = "http://{$host}:{$port}/ai-workflow/review";

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    private function stringOption(string $name, string $default): string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
