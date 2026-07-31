<?php

declare(strict_types=1);

use MediaManager\Services\ContinuityEtaEstimator;
use PHPUnit\Framework\TestCase;

final class ContinuityEtaEstimatorTest extends TestCase
{
    public function test_modeled_eta_divides_by_parallel(): void
    {
        // Force known concurrency via putenv for this process.
        putenv('CONTINUITY_CHECK_CONCURRENCY=4');
        putenv('OLLAMA_NUM_PARALLEL=4');

        $eta = ContinuityEtaEstimator::estimate(
            [
                'id'              => 9,
                'processed_files' => 100,
                'total_files'     => 500,
                'source_name'     => 'NY',
            ],
            8000.0, // 8s avg decide
            0       // no observed rate
        );

        $this->assertTrue($eta['active']);
        $this->assertSame(4, $eta['parallel']);
        $this->assertSame(400, $eta['remaining']);
        $this->assertSame('modeled', $eta['method']);
        // 400 files * (8000ms / 4) = 400 * 2000ms = 800s
        $this->assertSame(800, $eta['eta_seconds']);
    }

    public function test_observed_rate_preferred_when_enough_samples(): void
    {
        putenv('CONTINUITY_CHECK_CONCURRENCY=4');
        putenv('OLLAMA_NUM_PARALLEL=');

        $eta = ContinuityEtaEstimator::estimate(
            [
                'id'              => 1,
                'processed_files' => 0,
                'total_files'     => 100,
                'source_name'     => 'CH',
            ],
            8000.0,
            60 // 60 decides in 5 min = 0.2/s → 100/0.2 = 500s
        );

        $this->assertSame('observed', $eta['method']);
        $this->assertSame(500, $eta['eta_seconds']);
    }

    public function test_format_duration(): void
    {
        $this->assertSame('ETA unavailable', ContinuityEtaEstimator::formatDuration(null));
        $this->assertSame('< 1 min', ContinuityEtaEstimator::formatDuration(0));
        $this->assertSame('~45s', ContinuityEtaEstimator::formatDuration(45));
        $this->assertSame('~3 min', ContinuityEtaEstimator::formatDuration(121));
    }
}
