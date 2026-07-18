<?php

declare(strict_types=1);

namespace AiWorkflow\Eval;

use Illuminate\Support\Carbon;

/**
 * Everything the HTML report renders for one eval run.
 */
class EvalReport
{
    /**
     * @param  list<string>  $classes  Every label seen, as truth or prediction.
     * @param  list<EvalReportModelSummary>  $models  Best accuracy first.
     * @param  list<EvalReportDecision>  $decisions  Possibly truncated; see $decisionsTotal.
     * @param  list<string>  $modelsMissingPricing  Models whose cost could not be computed.
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $runName,
        public readonly ?Carbon $runCreatedAt,
        public readonly int $requestCount,
        public readonly int $labelledCount,
        public readonly array $classes,
        public readonly ?string $baseline,
        public readonly array $models,
        public readonly array $decisions,
        public readonly int $decisionsTotal,
        public readonly array $modelsMissingPricing,
    ) {}

    public function isTruncated(): bool
    {
        return $this->decisionsTotal > count($this->decisions);
    }

    /**
     * The eval is only as trustworthy as its labels; an unlabelled golden set
     * means the judge fell back to comparing against the recorded response.
     */
    public function hasUnlabelledRequests(): bool
    {
        return $this->labelledCount < $this->requestCount;
    }

    public function best(): ?EvalReportModelSummary
    {
        return $this->models[0] ?? null;
    }

    /**
     * Models that failed on more than a fifth of the set. Their scores say the
     * call did not work, not that the model judged badly — usually an
     * unsupported parameter or a capability the prompt needs. Worth separating,
     * because "cannot be called" is a fixable integration problem while "answers
     * wrongly" is not.
     *
     * @return list<EvalReportModelSummary>
     */
    public function unreliableModels(): array
    {
        return array_values(array_filter(
            $this->models,
            static fn (EvalReportModelSummary $model): bool => $model->errorRate() > 0.2,
        ));
    }
}
