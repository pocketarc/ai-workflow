<?php

declare(strict_types=1);

namespace AiWorkflow\Eval;

/**
 * One model's results across the golden set.
 */
class EvalReportModelSummary
{
    /**
     * @param  int  $labelled  Scores that had a human answer to compare against.
     * @param  int  $errors  Replays or judgements that threw.
     * @param  array{lower: float, upper: float}|null  $accuracyInterval  Wilson 95%.
     * @param  float  $blendedScore  Mean judge score, including partial credit.
     * @param  array<string, array{support: int, precision: float, recall: float, f1: float}>  $perClass
     * @param  array<string, array<string, int>>  $confusion  truth => predicted => count
     * @param  float|null  $cost  Total USD, or null when the model has no pricing configured.
     * @param  int|null  $winsVsBaseline  Labelled requests this model got right and the baseline got wrong.
     * @param  int|null  $lossesVsBaseline  The reverse: baseline right, this model wrong.
     * @param  float|null  $mcNemarP  Exact McNemar p-value over the discordant pairs; null on the
     *                                baseline row or when the pair share no labelled requests.
     */
    public function __construct(
        public readonly string $model,
        public readonly int $scored,
        public readonly int $labelled,
        public readonly int $errors,
        public readonly int $correct,
        public readonly ?float $accuracy,
        public readonly ?array $accuracyInterval,
        public readonly float $blendedScore,
        public readonly ?float $kappa,
        public readonly ?float $macroF1,
        public readonly array $perClass,
        public readonly array $confusion,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $thoughtTokens,
        public readonly ?float $cost,
        public readonly ?float $medianLatencyMs,
        public readonly ?float $p95LatencyMs,
        public readonly bool $isBaseline = false,
        public readonly ?float $accuracyDelta = null,
        public readonly ?bool $overlapsBaselineInterval = null,
        public readonly ?int $winsVsBaseline = null,
        public readonly ?int $lossesVsBaseline = null,
        public readonly ?float $mcNemarP = null,
    ) {}

    /**
     * Share of items where the replay or judge threw rather than producing an
     * answer. A high rate means the score measures a broken integration, not
     * the model's judgement.
     */
    public function errorRate(): float
    {
        return $this->scored === 0 ? 0.0 : $this->errors / $this->scored;
    }

    /**
     * What it costs to run a thousand decisions through this model. Cost sums
     * every replay, labelled or not, so the denominator is every replay too.
     */
    public function costPerThousand(): ?float
    {
        if ($this->cost === null || $this->scored === 0) {
            return null;
        }

        return ($this->cost / $this->scored) * 1000;
    }

    /**
     * The headline trade-off: price per decision the model actually got right.
     * Null when it got none right, because the figure would be meaningless.
     */
    public function costPerCorrectDecision(): ?float
    {
        if ($this->cost === null || $this->correct === 0) {
            return null;
        }

        return $this->cost / $this->correct;
    }
}
