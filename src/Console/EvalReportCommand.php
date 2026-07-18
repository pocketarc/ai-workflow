<?php

declare(strict_types=1);

namespace AiWorkflow\Console;

use AiWorkflow\Eval\EvalReportRenderer;
use AiWorkflow\Models\AiWorkflowEvalRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class EvalReportCommand extends Command
{
    /** @var string */
    protected $signature = 'eval:report
        {run : The eval run id, or part of its name}
        {--out= : Where to write the HTML file (defaults to storage/app/eval/)}
        {--baseline= : Model to compare the others against (defaults to the run\'s first model)}
        {--max-decisions=200 : How many decisions to include in the drill-down}';

    /** @var string */
    protected $description = 'Render an eval run as a self-contained HTML comparison report.';

    public function handle(EvalReportRenderer $renderer): int
    {
        $run = $this->resolveRun();
        if ($run === null) {
            return self::FAILURE;
        }

        $maxDecisions = $this->parseMaxDecisions();
        if ($maxDecisions === false) {
            return self::FAILURE;
        }

        $baseline = $this->option('baseline');
        $html = $renderer->render(
            $run,
            is_string($baseline) && $baseline !== '' ? $baseline : null,
            $maxDecisions,
        );

        $path = $this->resolveOutputPath($run);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $html);

        $this->info("Report written to {$path}");

        return self::SUCCESS;
    }

    private function resolveRun(): ?AiWorkflowEvalRun
    {
        $identifier = $this->argument('run');

        if ($identifier === '') {
            $this->error('An eval run id or name is required.');

            return null;
        }

        $run = AiWorkflowEvalRun::query()->find($identifier);

        if ($run instanceof AiWorkflowEvalRun) {
            return $run;
        }

        $matches = AiWorkflowEvalRun::query()
            ->where('name', 'like', '%'.$identifier.'%')
            ->orderByDesc('created_at')
            ->get();

        if ($matches->isEmpty()) {
            $this->error("No eval run matching '{$identifier}'.");

            return null;
        }

        if ($matches->count() > 1) {
            $this->warn("'{$identifier}' matched {$matches->count()} runs; using the most recent.");
        }

        return $matches->first();
    }

    /**
     * @return int|false False when the option is not a usable number.
     */
    private function parseMaxDecisions(): int|false
    {
        $raw = $this->option('max-decisions');

        if (! ctype_digit($raw) || (int) $raw < 1) {
            $this->error('--max-decisions must be a positive integer.');

            return false;
        }

        return (int) $raw;
    }

    private function resolveOutputPath(AiWorkflowEvalRun $run): string
    {
        $out = $this->option('out');

        if (is_string($out) && $out !== '') {
            return $out;
        }

        $slug = Str::slug($run->name);

        if ($slug === '') {
            $slug = $run->id;
        }

        return storage_path("app/eval/{$slug}.html");
    }
}
