<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Tests\Unit\Commands;

use Isaachatilima\Laracanon\Support\InstallationProgress;
use PHPUnit\Framework\TestCase;

final class InstallationProgressTest extends TestCase
{
    public function test_progress_is_optional_and_emits_events_in_order(): void
    {
        $progress = new InstallationProgress;
        $progress->stage('No listener');
        $progress->tick();
        $events = [];
        $progress->listen(
            static function (string $message) use (&$events): void {
                $events[] = $message;
            },
            static function () use (&$events): void {
                $events[] = 'tick';
            },
        );

        $progress->stage('Checking dependencies');
        $progress->tick();
        $progress->stage('Checking dependencies');
        $progress->stage('Installing item');
        self::assertSame(['Checking dependencies', 'tick', 'Installing item'], $events);
    }

    public function test_listeners_can_be_replaced_or_cleared(): void
    {
        $progress = new InstallationProgress;
        $events = [];
        $progress->listen(
            static function (string $message) use (&$events): void {
                $events[] = 'old: '.$message;
            },
            static function () use (&$events): void {
                $events[] = 'old tick';
            },
        );
        $progress->listen(static function (string $message) use (&$events): void {
            $events[] = 'new: '.$message;
        }, null);
        $progress->stage('New listener');
        $progress->tick();
        $progress->clear();
        $progress->stage('Detached listener');
        $progress->tick();
        $progress->listen(static function (string $message) use (&$events): void {
            $events[] = 'reset: '.$message;
        }, null);
        $progress->stage('Detached listener');

        self::assertSame(['new: New listener', 'reset: Detached listener'], $events);
    }
}
