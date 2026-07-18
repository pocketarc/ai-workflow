<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\Eval\Statistics;
use PHPUnit\Framework\TestCase as BaseTestCase;

class StatisticsTest extends BaseTestCase
{
    public function test_wilson_interval_matches_known_values(): void
    {
        // 80/100 successes: the textbook Wilson 95% interval is ~[0.7113, 0.8664].
        $interval = Statistics::wilsonInterval(80, 100);

        $this->assertNotNull($interval);
        $this->assertEqualsWithDelta(0.7113, $interval['lower'], 0.0005);
        $this->assertEqualsWithDelta(0.8664, $interval['upper'], 0.0005);
    }

    public function test_wilson_interval_stays_within_bounds_at_the_extremes(): void
    {
        // The normal approximation would run past 1.0 here; Wilson must not.
        $perfect = Statistics::wilsonInterval(10, 10);
        $this->assertNotNull($perfect);
        $this->assertLessThanOrEqual(1.0, $perfect['upper']);
        $this->assertGreaterThan(0.6, $perfect['lower']);

        $zero = Statistics::wilsonInterval(0, 10);
        $this->assertNotNull($zero);
        $this->assertGreaterThanOrEqual(0.0, $zero['lower']);
        $this->assertLessThan(0.4, $zero['upper']);
    }

    public function test_wilson_interval_is_null_without_trials(): void
    {
        $this->assertNull(Statistics::wilsonInterval(0, 0));
    }

    public function test_wilson_interval_narrows_as_the_sample_grows(): void
    {
        $small = Statistics::wilsonInterval(8, 10);
        $large = Statistics::wilsonInterval(800, 1000);

        $this->assertNotNull($small);
        $this->assertNotNull($large);

        $smallWidth = $small['upper'] - $small['lower'];
        $largeWidth = $large['upper'] - $large['lower'];

        $this->assertLessThan($smallWidth, $largeWidth);
    }

    public function test_cohens_kappa_is_one_for_perfect_agreement_across_labels(): void
    {
        $kappa = Statistics::cohensKappa([
            ['truth' => 'a', 'predicted' => 'a'],
            ['truth' => 'b', 'predicted' => 'b'],
            ['truth' => 'a', 'predicted' => 'a'],
            ['truth' => 'b', 'predicted' => 'b'],
        ]);

        $this->assertNotNull($kappa);
        $this->assertEqualsWithDelta(1.0, $kappa, 0.0001);
    }

    public function test_cohens_kappa_is_zero_at_chance_agreement(): void
    {
        // Truth is 50/50 and predictions are 50/50, agreeing exactly half the
        // time — which is precisely what chance alone would produce.
        $kappa = Statistics::cohensKappa([
            ['truth' => 'a', 'predicted' => 'a'],
            ['truth' => 'a', 'predicted' => 'b'],
            ['truth' => 'b', 'predicted' => 'a'],
            ['truth' => 'b', 'predicted' => 'b'],
        ]);

        $this->assertNotNull($kappa);
        $this->assertEqualsWithDelta(0.0, $kappa, 0.0001);
    }

    public function test_cohens_kappa_punishes_a_majority_class_guesser(): void
    {
        // 90% accurate, but only by always guessing the dominant label — the
        // exact case where raw accuracy flatters a useless model.
        $pairs = [];
        for ($i = 0; $i < 9; $i++) {
            $pairs[] = ['truth' => 'wait', 'predicted' => 'wait'];
        }
        $pairs[] = ['truth' => 'close', 'predicted' => 'wait'];

        $kappa = Statistics::cohensKappa($pairs);

        $this->assertNotNull($kappa);
        $this->assertEqualsWithDelta(0.0, $kappa, 0.0001);
    }

    public function test_cohens_kappa_is_null_without_pairs(): void
    {
        $this->assertNull(Statistics::cohensKappa([]));
    }

    public function test_percentile_interpolates(): void
    {
        $values = [1, 2, 3, 4];

        $this->assertEqualsWithDelta(2.5, Statistics::percentile($values, 0.5) ?? 0.0, 0.0001);
        $this->assertEqualsWithDelta(1.0, Statistics::percentile($values, 0.0) ?? 0.0, 0.0001);
        $this->assertEqualsWithDelta(4.0, Statistics::percentile($values, 1.0) ?? 0.0, 0.0001);
    }

    public function test_percentile_surfaces_a_tail_a_median_would_hide(): void
    {
        // A tenth of calls are slow, so the median stays flat while p95 spikes.
        $latencies = array_merge(array_fill(0, 90, 100), array_fill(0, 10, 10_000));

        $median = Statistics::percentile($latencies, 0.5);
        $p95 = Statistics::percentile($latencies, 0.95);

        $this->assertNotNull($median);
        $this->assertNotNull($p95);
        $this->assertEqualsWithDelta(100.0, $median, 0.0001);
        $this->assertEqualsWithDelta(10_000.0, $p95, 0.0001);
    }

    public function test_percentile_does_not_report_a_tail_below_the_percentile(): void
    {
        // A single outlier in 100 samples sits at the 99th percentile, so p95
        // must ignore it — otherwise the report would overstate tail latency.
        $latencies = array_merge(array_fill(0, 99, 100), [10_000]);

        $this->assertEqualsWithDelta(100.0, Statistics::percentile($latencies, 0.95) ?? 0.0, 0.0001);
        $this->assertEqualsWithDelta(10_000.0, Statistics::percentile($latencies, 1.0) ?? 0.0, 0.0001);
    }

    public function test_percentile_handles_edge_cases(): void
    {
        $this->assertNull(Statistics::percentile([], 0.5));
        $this->assertEqualsWithDelta(7.0, Statistics::percentile([7], 0.95) ?? 0.0, 0.0001);
    }

    public function test_f1_is_the_harmonic_mean(): void
    {
        $this->assertEqualsWithDelta(0.5, Statistics::f1(0.5, 0.5), 0.0001);
        $this->assertEqualsWithDelta(0.4, Statistics::f1(1.0, 0.25), 0.0001);
        $this->assertSame(0.0, Statistics::f1(0.0, 0.0));
    }

    public function test_interval_overlap_detection(): void
    {
        $this->assertTrue(Statistics::intervalsOverlap(
            ['lower' => 0.70, 'upper' => 0.85],
            ['lower' => 0.80, 'upper' => 0.92],
        ));

        $this->assertFalse(Statistics::intervalsOverlap(
            ['lower' => 0.70, 'upper' => 0.78],
            ['lower' => 0.80, 'upper' => 0.92],
        ));
    }
}
