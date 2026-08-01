<?php

declare(strict_types=1);

use MediaManager\Support\Ulid;
use PHPUnit\Framework\TestCase;

final class UlidTest extends TestCase
{
    public function test_generate_is_valid_length_and_alphabet(): void
    {
        $ulid = Ulid::generate();
        $this->assertSame(26, strlen($ulid));
        $this->assertTrue(Ulid::isValid($ulid));
    }

    public function test_generate_is_time_sortable(): void
    {
        $a = Ulid::generate(1_700_000_000_000);
        $b = Ulid::generate(1_700_000_000_001);
        $this->assertTrue(strcmp($a, $b) < 0);
    }

    public function test_shard_path(): void
    {
        $ulid = '01J8X9K2M3N4P5Q6R7S8T9U0V1';
        $this->assertSame(
            '01/J8/X9/01J8X9K2M3N4P5Q6R7S8T9U0V1',
            Ulid::shardPath($ulid)
        );
    }

    public function test_normalize_uppercases(): void
    {
        $this->assertSame(
            '01J8X9K2M3N4P5Q6R7S8T9U0V1',
            Ulid::normalize('01j8x9k2m3n4p5q6r7s8t9u0v1')
        );
    }
}
