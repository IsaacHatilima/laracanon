<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Support;

use Symfony\Component\Process\Process;
use Throwable;

final class SymfonyProcessRunner implements ProcessRunner
{
    public function __construct(private readonly ?InstallationProgress $progress = null) {}

    public function run(array $command, string $cwd): ProcessResult
    {
        // Inherited Composer flags must not turn a scoped operation into an
        // application-wide update or bypass PHP/platform compatibility checks.
        $environment = array_fill_keys([
            'COMPOSER_WITH_ALL_DEPENDENCIES',
            'COMPOSER_WITH_DEPENDENCIES',
            'COMPOSER_PREFER_LOWEST',
            'COMPOSER_IGNORE_PLATFORM_REQS',
            'COMPOSER_IGNORE_PLATFORM_REQ',
            'COMPOSER_NO_DEV',
            'COMPOSER',
            'COMPOSER_VENDOR_DIR',
        ], false);

        $process = new Process($command, $cwd, $environment, null, 600);
        $output = '';
        $composerProgress = $this->progress !== null && ComposerProgressParser::supports($command)
            ? new ComposerProgressParser($this->progress)
            : null;

        try {
            $process->start(static function (string $type, string $buffer) use (&$output, $composerProgress): void {
                $output .= $buffer;
                $composerProgress?->consume($type, $buffer);
            });

            while ($process->isRunning()) {
                $process->checkTimeout();
                $this->progress?->tick();
                usleep(100_000);
            }

            // Drain any remaining output and retain Symfony's signal handling.
            $exitCode = $process->wait();
            $composerProgress?->finish();

            return new ProcessResult($exitCode, $output);
        } catch (Throwable $exception) {
            try {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            } catch (Throwable) {
                // Report the original failure even if process cleanup fails.
            }

            return new ProcessResult(1, trim($output."\n".$exception->getMessage()));
        }
    }
}
