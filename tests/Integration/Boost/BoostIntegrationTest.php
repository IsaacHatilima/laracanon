<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Tests\Integration\Boost;

use Isaachatilima\Laracanon\Boost\BoostIntegration;
use Isaachatilima\Laracanon\Support\ProcessResult;
use Isaachatilima\Laracanon\Support\ProcessRunner;
use PHPUnit\Framework\TestCase;

final class BoostIntegrationTest extends TestCase
{
    public function test_dry_run_does_not_launch_process_or_mutate_application(): void
    {
        $runner = $this->createMock(ProcessRunner::class);
        $runner->expects(self::never())->method('run');
        $report = (new BoostIntegration($runner))->refresh('/unused/project', true);

        self::assertSame([], $report['failures']);
        self::assertStringContainsString('Would configure', $report['notes'][0]);
    }

    public function test_fresh_process_receives_selected_packages_and_partial_result_is_reported(): void
    {
        $runner = $this->createMock(ProcessRunner::class);
        $runner->expects(self::once())->method('run')->with(
            self::callback(fn (array $command): bool => $command[0] === PHP_BINARY && $command[2] === '/app' && $command[3] === '["fixture/toolkit"]'),
            '/app',
        )->willReturn(new ProcessResult(1, 'App boot message'."\n".'LARACANON_BOOST_RESULT:{"resources":["Rule index regenerated"],"failures":["Skill failed"],"notes":[],"conflicts":[]}'));
        $report = (new BoostIntegration($runner))->refresh('/app', false, ['fixture/toolkit']);

        self::assertSame(['Rule index regenerated'], $report['resources']);
        self::assertSame(['Skill failed'], $report['failures']);
    }

    public function test_bootstrap_errors_are_reported_clearly(): void
    {
        $runner = $this->createMock(ProcessRunner::class);
        $runner->expects(self::once())->method('run')->willReturn(new ProcessResult(1, 'Unsupported package API'));
        $report = (new BoostIntegration($runner))->refresh('/app');

        self::assertSame(['Boost refresh failed: Unsupported package API'], $report['failures']);
    }
}
