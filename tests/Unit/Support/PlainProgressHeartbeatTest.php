<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Tests\Unit\Support;

use Isaachatilima\Laracanon\Support\PlainProgressHeartbeat;
use PHPUnit\Framework\TestCase;

final class PlainProgressHeartbeatTest extends TestCase
{
    public function test_silent_work_reports_elapsed_time_without_flooding_plain_output(): void
    {
        $now = 100.0;
        $heartbeat = new PlainProgressHeartbeat('Composer: loading package metadata', static function () use (&$now): float {
            return $now;
        });
        $now = 114.999;
        self::assertNull($heartbeat->message());
        $now = 115.0;
        self::assertSame('Still working: Composer: loading package metadata (15s elapsed)', $heartbeat->message());
        self::assertNull($heartbeat->message());
        $now = 145.0;
        self::assertSame('Still working: Composer: loading package metadata (45s elapsed)', $heartbeat->message());
    }

    public function test_new_stage_delays_heartbeat_but_preserves_total_elapsed_time(): void
    {
        $now = 0.0;
        $heartbeat = new PlainProgressHeartbeat('Checking dependencies', static function () use (&$now): float {
            return $now;
        });
        $now = 10.0;
        $heartbeat->stage('Composer: running project scripts');
        $now = 15.0;
        self::assertNull($heartbeat->message());
        $now = 25.0;
        self::assertSame('Still working: Composer: running project scripts (25s elapsed)', $heartbeat->message());
    }
}
