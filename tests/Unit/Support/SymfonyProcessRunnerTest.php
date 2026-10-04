<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Tests\Unit\Support;

use Isaachatilima\Laracanon\Support\InstallationProgress;
use Isaachatilima\Laracanon\Support\SymfonyProcessRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SymfonyProcessRunnerTest extends TestCase
{
    public function test_silent_child_keeps_progress_advancing_before_it_exits(): void
    {
        $ticks = [];
        $progress = new InstallationProgress;
        $progress->listen(null, static function () use (&$ticks): void {
            $ticks[] = hrtime(true);
        });

        $result = (new SymfonyProcessRunner($progress))->run([PHP_BINARY, '-r', 'usleep(600_000);'], __DIR__);
        $finished = hrtime(true);

        self::assertTrue($result->successful());
        self::assertSame('', $result->output);
        self::assertGreaterThanOrEqual(2, count($ticks));
        self::assertLessThan($finished, $ticks[array_key_last($ticks)]);
        self::assertGreaterThanOrEqual(80_000_000, $ticks[1] - $ticks[0]);
    }

    public function test_stdout_stderr_and_failure_exit_are_retained_with_progress(): void
    {
        $ticks = 0;
        $progress = new InstallationProgress;
        $progress->listen(null, static function () use (&$ticks): void {
            $ticks++;
        });

        $result = (new SymfonyProcessRunner($progress))->run([
            PHP_BINARY,
            '-r',
            'echo "standard output\n"; usleep(150_000); fwrite(STDERR, "error output\n"); exit(7);',
        ], __DIR__);

        self::assertFalse($result->successful());
        self::assertSame(7, $result->exitCode);
        self::assertStringContainsString("standard output\n", $result->output);
        self::assertStringContainsString("error output\n", $result->output);
        self::assertSame(strlen("standard output\nerror output\n"), strlen($result->output));
        self::assertGreaterThan(0, $ticks);
    }

    public function test_running_without_progress_preserves_output_and_working_directory(): void
    {
        $result = (new SymfonyProcessRunner)->run([PHP_BINARY, '-r', 'echo getcwd();'], __DIR__);

        self::assertSame(0, $result->exitCode);
        self::assertSame(realpath(__DIR__), $result->output);
    }

    public function test_composer_phases_are_live_and_original_output_is_preserved(): void
    {
        $directory = sys_get_temp_dir().'/laracanon-composer-progress-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $script = $directory.'/composer';
        file_put_contents($script, <<<'PHP'
<?php
echo "\x1B[32mLoading composer repositories with package information\x1B[0m\n";
fflush(STDOUT);
usleep(200_000);
fwrite(STDERR, "Updating dep");
usleep(200_000);
fwrite(STDERR, "endencies\nDownloading https://private.example/archive?token=secret\n");
usleep(200_000);
echo "Generating autoload files";
exit(7);
PHP);
        $events = [];
        $progress = new InstallationProgress;
        $progress->listen(static function (string $message) use (&$events): void {
            $events[] = [$message, hrtime(true)];
        }, null);

        try {
            $result = (new SymfonyProcessRunner($progress))->run([PHP_BINARY, $script], $directory);
            $finished = hrtime(true);
            self::assertSame(7, $result->exitCode);
            self::assertSame([
                'Composer: loading package metadata',
                'Composer: resolving compatible versions',
                'Composer: generating autoload files',
            ], array_column($events, 0));
            self::assertLessThan($finished - 100_000_000, $events[0][1]);
            self::assertStringContainsString("\x1B[32mLoading composer repositories with package information\x1B[0m\n", $result->output);
            self::assertStringContainsString("Updating dependencies\nDownloading https://private.example/archive?token=secret\n", $result->output);
            self::assertStringEndsWith('Generating autoload files', $result->output);
            self::assertStringNotContainsString('secret', implode("\n", array_column($events, 0)));
        } finally {
            unlink($script);
            rmdir($directory);
        }
    }

    public function test_unrelated_php_output_cannot_change_composer_progress(): void
    {
        $events = [];
        $progress = new InstallationProgress;
        $progress->listen(static function (string $message) use (&$events): void {
            $events[] = $message;
        }, null);

        $result = (new SymfonyProcessRunner($progress))->run([PHP_BINARY, '-r', 'echo "Updating dependencies\n";'], __DIR__);

        self::assertTrue($result->successful());
        self::assertSame("Updating dependencies\n", $result->output);
        self::assertSame([], $events);
    }

    public function test_inherited_composer_overrides_are_removed_without_removing_unrelated_environment(): void
    {
        $names = [
            'COMPOSER_WITH_ALL_DEPENDENCIES',
            'COMPOSER_WITH_DEPENDENCIES',
            'COMPOSER_PREFER_LOWEST',
            'COMPOSER_IGNORE_PLATFORM_REQS',
            'COMPOSER_IGNORE_PLATFORM_REQ',
            'COMPOSER_NO_DEV',
            'COMPOSER',
            'COMPOSER_VENDOR_DIR',
        ];
        $original = [];
        foreach ([...$names, 'LARACANON_TEST_ENVIRONMENT'] as $name) {
            $original[$name] = [
                'value' => getenv($name),
                'serverExists' => array_key_exists($name, $_SERVER),
                'server' => $_SERVER[$name] ?? null,
                'envExists' => array_key_exists($name, $_ENV),
                'env' => $_ENV[$name] ?? null,
            ];
            putenv($name.'=present');
            $_SERVER[$name] = 'present';
            $_ENV[$name] = 'present';
        }

        try {
            $script = '$values = []; foreach ('.var_export($names, true).' as $name) { $values[$name] = getenv($name); } $values["unrelated"] = getenv("LARACANON_TEST_ENVIRONMENT"); echo json_encode($values);';
            $result = (new SymfonyProcessRunner)->run([PHP_BINARY, '-r', $script], __DIR__);

            self::assertTrue($result->successful());
            self::assertSame([...array_fill_keys($names, false), 'unrelated' => 'present'], json_decode($result->output, true, flags: JSON_THROW_ON_ERROR));
        } finally {
            foreach ($original as $name => $state) {
                putenv($state['value'] === false ? $name : $name.'='.$state['value']);
                if ($state['serverExists']) {
                    $_SERVER[$name] = $state['server'];
                } else {
                    unset($_SERVER[$name]);
                }
                if ($state['envExists']) {
                    $_ENV[$name] = $state['env'];
                } else {
                    unset($_ENV[$name]);
                }
            }
        }
    }

    public function test_progress_exception_stops_the_child_and_reports_the_original_error(): void
    {
        if (! function_exists('posix_kill')) {
            self::markTestSkipped('Checking child cleanup requires POSIX process inspection.');
        }

        $ticks = 0;
        $progress = new InstallationProgress;
        $progress->listen(null, static function () use (&$ticks): void {
            if (++$ticks === 3) {
                throw new RuntimeException('Progress observer failed.');
            }
        });

        $result = (new SymfonyProcessRunner($progress))->run([
            PHP_BINARY,
            '-r',
            'echo "pid:", getmypid(), "\n"; fflush(STDOUT); sleep(10);',
        ], __DIR__);

        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('Progress observer failed.', $result->output);
        self::assertSame(1, preg_match('/pid:(\d+)/', $result->output, $matches));
        self::assertFalse(posix_kill((int) $matches[1], 0), 'The failed observer must not leave the child process running.');
    }
}
