<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Support;

final class ComposerProgressParser
{
    private const MAX_PENDING_BYTES = 8192;

    /** @var array<string, string> */
    private array $pending = [];

    /** @var array<string, bool> */
    private array $discarding = [];

    public function __construct(private readonly InstallationProgress $progress) {}

    /** @param list<string> $command */
    public static function supports(array $command): bool
    {
        $executable = basename(str_replace('\\', '/', $command[0] ?? ''));
        if (in_array($executable, ['composer', 'composer.phar', 'composer.exe'], true)) {
            return true;
        }

        // Also support a Composer PHAR launched with the current PHP binary;
        // ordinary PHP processes (including the Boost helper) remain opaque.
        return isset($command[1])
            && (realpath($command[0]) === realpath(PHP_BINARY) || preg_match('/^php(?:\d+(?:\.\d+)*)?(?:\.exe)?$/i', $executable) === 1)
            && in_array(basename(str_replace('\\', '/', $command[1])), ['composer', 'composer.phar'], true);
    }

    public function consume(string $stream, string $buffer): void
    {
        $lines = preg_split('/\r\n|\r|\n/', $buffer) ?: [$buffer];
        $tail = array_pop($lines);
        foreach ($lines as $line) {
            $this->append($stream, $line);
            $this->complete($stream);
        }
        $this->append($stream, $tail);
    }

    public function finish(): void
    {
        foreach (array_keys($this->pending) as $stream) {
            $this->complete($stream);
        }
    }

    private function append(string $stream, string $buffer): void
    {
        if ($this->discarding[$stream] ?? false) {
            return;
        }

        $pending = $this->pending[$stream] ?? '';
        if (strlen($pending) + strlen($buffer) > self::MAX_PENDING_BYTES) {
            // Do not reinterpret the tail of an oversized line as a marker.
            $this->pending[$stream] = '';
            $this->discarding[$stream] = true;

            return;
        }
        $this->pending[$stream] = $pending.$buffer;
    }

    private function complete(string $stream): void
    {
        if (! ($this->discarding[$stream] ?? false)) {
            $line = trim(preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $this->pending[$stream] ?? '') ?? '');
            $phase = $this->phase($line);
            if ($phase !== null) {
                $this->progress->stage('Composer: '.$phase);
            }
        }
        $this->pending[$stream] = '';
        $this->discarding[$stream] = false;
    }

    private function phase(string $line): ?string
    {
        return match (true) {
            $line === 'Loading composer repositories with package information' => 'loading package metadata',
            $line === 'Updating dependencies' => 'resolving compatible versions',
            $line === 'Writing lock file' => 'writing the lockfile',
            preg_match('/^Installing dependencies from lock file(?: \(including require-dev\))?$/', $line) === 1,
            preg_match('/^Package operations: \d+ installs?, \d+ updates?, \d+ removals?$/', $line) === 1 => 'installing packages',
            preg_match('/^- Downloading [a-z0-9_.-]+\/[a-z0-9_.-]+ \(/i', $line) === 1 => 'downloading packages',
            preg_match('/^- (?:Installing|Upgrading|Downgrading|Updating|Removing) [a-z0-9_.-]+\/[a-z0-9_.-]+\b/i', $line) === 1 => 'installing packages',
            preg_match('/^Generating (?:optimized )?autoload files$/', $line) === 1 => 'generating autoload files',
            preg_match('/^>\s+(?:post-(?:autoload-dump|update-cmd|install-cmd):\s+)?@php\s+artisan\s+package:discover(?:\s|$)/', $line) === 1 => 'discovering Laravel packages',
            preg_match('/^>\s+(?:post-(?:autoload-dump|update-cmd|install-cmd):\s+)?@php\s+artisan\s+boost:update(?:\s|$)/', $line) === 1 => 'updating Laravel Boost resources',
            str_starts_with($line, '> ') => 'running project scripts',
            // Composer provides no default audit-start marker. Later scripts
            // may still run, and audits may be disabled or opt-in on install.
            $line === 'Boost guidelines and skills updated successfully.' => 'finalizing',
            $line === 'No security vulnerability advisories found.' => 'security advisory check complete',
            default => null,
        };
    }
}
