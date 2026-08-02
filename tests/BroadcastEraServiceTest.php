<?php

declare(strict_types=1);

use MediaManager\Services\BroadcastEraService;
use PHPUnit\Framework\TestCase;

final class BroadcastEraServiceTest extends TestCase
{
    public function test_hour_allowed_without_era_coverage(): void
    {
        $this->assertTrue(BroadcastEraService::hourAllowed([], '2020-09-01', 20 * 60, 1));
    }

    public function test_hour_allowed_inside_window(): void
    {
        $coverage = [
            '2020-09-01' => [
                ['days' => 31, 'start' => 20 * 60, 'end' => 22 * 60],
            ],
        ];
        $this->assertTrue(BroadcastEraService::hourAllowed($coverage, '2020-09-01', 20 * 60, 1));
        $this->assertTrue(BroadcastEraService::hourAllowed($coverage, '2020-09-01', 21 * 60, 1));
        $this->assertFalse(BroadcastEraService::hourAllowed($coverage, '2020-09-01', 19 * 60, 1));
        $this->assertFalse(BroadcastEraService::hourAllowed($coverage, '2020-09-01', 20 * 60, 64)); // Sunday
    }
}
