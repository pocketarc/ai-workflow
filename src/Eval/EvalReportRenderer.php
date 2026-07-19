<?php

declare(strict_types=1);

namespace AiWorkflow\Eval;

use AiWorkflow\Models\AiWorkflowEvalRun;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * Renders an eval run as a single self-contained HTML page — no external CSS,
 * scripts or fonts, so the file can be mailed around or opened offline.
 */
class EvalReportRenderer
{
    public function __construct(
        private readonly EvalReportMetrics $metrics,
        private readonly ViewFactory $views,
    ) {}

    public function render(AiWorkflowEvalRun $run, ?string $baseline = null, int $maxDecisions = 200): string
    {
        $report = $this->metrics->compute($run, $baseline, $maxDecisions);

        return $this->views->make('ai-workflow::eval-report', ['report' => $report])->render();
    }
}
