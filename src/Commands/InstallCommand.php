<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Commands;

use Illuminate\Console\Command;
use Isaachatilima\Laracanon\Installation\Installer;
use Isaachatilima\Laracanon\Support\InstallationProgress;
use Isaachatilima\Laracanon\Support\PlainProgressHeartbeat;
use Symfony\Component\Console\Helper\ProgressIndicator;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Throwable;

final class InstallCommand extends Command
{
    protected $signature = 'canon:install {items?* : Item names to install; omit to install all items} {--dry-run : Preview without running processes or changing files}';

    protected $description = 'Install selected or all Laracanon items into this Laravel application';

    public function handle(Installer $installer, InstallationProgress $progress): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $operation = $dryRun ? 'Preview' : 'Installation';
        $indicator = null;
        $progress->clear();

        if ($this->output->getVerbosity() > OutputInterface::VERBOSITY_QUIET) {
            $message = $dryRun ? 'Preparing Laracanon preview' : 'Preparing Laracanon installation';
            if ($this->supportsAnimation()) {
                $indicator = new ProgressIndicator($this->output, 'verbose');
                $indicator->start($message);
                $progress->listen(
                    static fn (string $stage) => $indicator->setMessage($stage),
                    static fn () => $indicator->advance(),
                );
            } else {
                $this->line($message);
                $heartbeat = new PlainProgressHeartbeat($message);
                $progress->listen(
                    function (string $stage) use ($heartbeat): void {
                        $heartbeat->stage($stage);
                        $this->line('  '.$stage);
                    },
                    function () use ($heartbeat): void {
                        if (($message = $heartbeat->message()) !== null) {
                            $this->line('  '.$message);
                        }
                    },
                );
            }
        }

        try {
            $report = $installer->install(base_path(), $this->argument('items'), $dryRun);
            $message = $operation.($report->successful() ? ' complete' : ' finished with issues');
            if ($indicator !== null) {
                $indicator->finish($message);
            } else {
                $this->line($message);
            }
        } catch (Throwable $exception) {
            $message = $operation.' stopped with an error';
            if ($indicator !== null) {
                $indicator->finish($message);
            } else {
                $this->line($message);
            }

            throw $exception;
        } finally {
            $progress->clear();
        }

        $this->info($report->dryRun ? 'Laracanon dry run (no changes made)' : 'Laracanon installation summary');
        foreach (['items' => 'Items', 'dependencies' => 'Dependencies', 'rules' => 'Rules', 'skills' => 'Skills', 'files' => 'Configuration files', 'resources' => 'Boost resources', 'conflicts' => 'Conflicts', 'failures' => 'Failures', 'notes' => 'Notes'] as $key => $label) {
            if ($report->{$key} === []) {
                continue;
            }
            $this->line($label.':');
            foreach ($report->{$key} as $name => $value) {
                $message = '  - '.($key === 'items' ? "{$name}: " : '').$value;
                if (in_array($key, ['conflicts', 'failures'], true)) {
                    $this->warn($message);
                } else {
                    $this->line($message);
                }
            }
        }

        return $report->successful() ? self::SUCCESS : self::FAILURE;
    }

    private function supportsAnimation(): bool
    {
        $output = $this->output->getOutput();
        if (! $output->isDecorated()) {
            return false;
        }

        return ! $output instanceof StreamOutput || ! function_exists('stream_isatty') || stream_isatty($output->getStream());
    }
}
