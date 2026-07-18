<?php

declare(strict_types=1);

namespace AiWorkflow\Eval;

/**
 * Statistics for classification evals.
 *
 * Eval sets are small enough that the normal-approximation confidence interval
 * misbehaves near 0% and 100%, so accuracy uses a Wilson score interval. Label
 * distributions are usually skewed, so agreement is also reported chance-
 * corrected via Cohen's kappa rather than raw accuracy alone.
 */
class Statistics
{
    /** Two-sided z for a 95% confidence level. */
    public const float Z_95 = 1.959964;

    /**
     * Wilson score interval for a binomial proportion.
     *
     * @param  int  $successes  Number of successes.
     * @param  int  $total  Number of trials.
     * @return array{lower: float, upper: float}|null Null when there are no trials.
     */
    public static function wilsonInterval(int $successes, int $total, float $z = self::Z_95): ?array
    {
        if ($total <= 0) {
            return null;
        }

        $proportion = $successes / $total;
        $zSquared = $z ** 2;
        $denominator = 1.0 + ($zSquared / $total);

        $centre = ($proportion + ($zSquared / (2 * $total))) / $denominator;
        $margin = ($z / $denominator) * sqrt(
            (($proportion * (1 - $proportion)) / $total) + ($zSquared / (4 * ($total ** 2)))
        );

        return [
            'lower' => max(0.0, $centre - $margin),
            'upper' => min(1.0, $centre + $margin),
        ];
    }

    /**
     * Cohen's kappa: agreement corrected for the agreement expected by chance.
     *
     * @param  list<array{truth: string, predicted: string}>  $pairs
     * @return float|null Null when there is nothing to compare.
     */
    public static function cohensKappa(array $pairs): ?float
    {
        $total = count($pairs);

        if ($total === 0) {
            return null;
        }

        $agreements = 0;
        /** @var array<string, int> $truthCounts */
        $truthCounts = [];
        /** @var array<string, int> $predictedCounts */
        $predictedCounts = [];

        foreach ($pairs as $pair) {
            if ($pair['truth'] === $pair['predicted']) {
                $agreements++;
            }

            $truthCounts[$pair['truth']] = ($truthCounts[$pair['truth']] ?? 0) + 1;
            $predictedCounts[$pair['predicted']] = ($predictedCounts[$pair['predicted']] ?? 0) + 1;
        }

        $observed = $agreements / $total;

        $expected = 0.0;
        foreach ($truthCounts as $label => $truthCount) {
            $expected += ($truthCount / $total) * (($predictedCounts[$label] ?? 0) / $total);
        }

        // Perfect expected agreement (a single label everywhere) leaves kappa
        // undefined; report the honest extremes instead of dividing by zero.
        if (abs(1.0 - $expected) < PHP_FLOAT_EPSILON) {
            return $observed >= 1.0 ? 1.0 : 0.0;
        }

        return ($observed - $expected) / (1.0 - $expected);
    }

    /**
     * Linear-interpolation percentile (the method Excel's PERCENTILE and R's
     * type-7 quantile use).
     *
     * @param  list<float|int>  $values  Need not be sorted.
     * @param  float  $percentile  Between 0.0 and 1.0.
     */
    public static function percentile(array $values, float $percentile): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);

        $rank = $percentile * ($count - 1);
        $lowerIndex = (int) floor($rank);
        $upperIndex = (int) ceil($rank);

        $lower = $values[$lowerIndex] ?? null;
        $upper = $values[$upperIndex] ?? null;

        if ($lower === null || $upper === null) {
            return null;
        }

        if ($lowerIndex === $upperIndex) {
            return (float) $lower;
        }

        return (float) $lower + (($rank - $lowerIndex) * ((float) $upper - (float) $lower));
    }

    /**
     * Harmonic mean of precision and recall.
     */
    public static function f1(float $precision, float $recall): float
    {
        if ($precision + $recall <= 0.0) {
            return 0.0;
        }

        return (2 * $precision * $recall) / ($precision + $recall);
    }

    /**
     * Whether two confidence intervals overlap.
     *
     * A coarse caution flag, not a significance test: model results are paired
     * on the same requests, and paired estimates can differ reliably even while
     * their marginal intervals overlap. Calling a difference significant would
     * take a paired test such as McNemar's — overlap only says these intervals
     * cannot separate the two on their own.
     *
     * @param  array{lower: float, upper: float}  $a
     * @param  array{lower: float, upper: float}  $b
     */
    public static function intervalsOverlap(array $a, array $b): bool
    {
        return $a['lower'] <= $b['upper'] && $b['lower'] <= $a['upper'];
    }
}
