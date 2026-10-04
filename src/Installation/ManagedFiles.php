<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Installation;

use Isaachatilima\Laracanon\Support\ItemFilePath;
use RuntimeException;

final class ManagedFiles
{
    private array $state = ['schema' => 1, 'files' => [], 'items' => []];

    public function __construct(private readonly string $root, private readonly bool $dryRun)
    {
        $path = $this->absolute('.ai/laracanon/state.json');
        if (is_file($path)) {
            $state = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($state) || ($state['schema'] ?? null) !== 1 || ! is_array($state['files'] ?? null) || ! is_array($state['items'] ?? null)) {
                throw $this->invalidState('expected schema 1 with files and items mappings');
            }
            $this->state = $this->validateState($state);
        }
    }

    /** Returns installed, updated, unchanged, or conflict. */
    public function put(string $relative, string $contents, string $owner): string
    {
        $path = $this->absolute($relative);
        if ($this->owner($relative) !== null && $this->owner($relative) !== $owner) {
            return 'conflict';
        }
        $desired = hash('sha256', $contents);
        $existing = is_file($path) ? hash_file('sha256', $path) : null;
        $owned = $this->state['files'][$relative]['hash'] ?? null;

        if ($existing !== null && $existing !== $desired && $existing !== $owned) {
            return 'conflict';
        }
        if (file_exists($path) && ! is_file($path)) {
            return 'conflict';
        }

        $status = $existing === $desired ? 'unchanged' : ($existing === null ? 'installed' : 'updated');
        if (! $this->dryRun && $existing !== $desired) {
            $mode = null;
            if (ItemFilePath::isConfiguration($relative)) {
                clearstatcache(true, $path);
                $permissions = $existing === null ? null : @fileperms($path);
                if ($permissions === false) {
                    throw new RuntimeException("Cannot read permissions for {$relative}.");
                }
                $mode = $permissions === null ? (0666 & ~umask()) : ($permissions & 0777);
            }
            self::write($path, $contents, $mode);
        }
        $this->state['files'][$relative] = ['hash' => $desired, 'owner' => $owner];

        return $status;
    }

    public function remove(string $relative, string $owner): string
    {
        $path = $this->absolute($relative);
        if ($this->owner($relative) !== null && $this->owner($relative) !== $owner) {
            return 'conflict';
        }
        if (! isset($this->state['files'][$relative])) {
            return 'unchanged';
        }
        if (is_file($path) && hash_file('sha256', $path) !== $this->state['files'][$relative]['hash']) {
            return 'conflict';
        }
        if (file_exists($path) && ! is_file($path)) {
            return 'conflict';
        }
        if (! $this->dryRun && is_file($path) && ! unlink($path)) {
            throw new RuntimeException("Cannot remove managed file {$relative}.");
        }
        unset($this->state['files'][$relative]);

        return 'removed';
    }

    public function item(string $name): array
    {
        return $this->state['items'][$name] ?? [];
    }

    public function record(string $name, array $metadata): void
    {
        $this->state['items'][$name] = $metadata;
    }

    public function owner(string $relative): ?string
    {
        $this->absolute($relative);
        if (isset($this->state['files'][$relative]['owner'])) {
            return $this->state['files'][$relative]['owner'];
        }

        // Older schema-1 conflict records may reference paths with no file hash.
        foreach ($this->state['items'] as $name => $item) {
            if (in_array($relative, $item['files'], true)) {
                return $name;
            }
        }

        return null;
    }

    public function lockPath(): string
    {
        return $this->absolute('.ai/laracanon/install.lock');
    }

    public function save(): void
    {
        if (! $this->dryRun) {
            $path = $this->absolute('.ai/laracanon/state.json');
            $json = json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            if (! is_file($path) || file_get_contents($path) !== $json) {
                self::write($path, $json);
            }
        }
    }

    private function absolute(string $relative): string
    {
        $segments = explode('/', $relative);
        $aiPath = preg_match('#^\.ai/[a-zA-Z0-9_.-]+(?:/[a-zA-Z0-9_.-]+)*$#D', $relative) === 1
            && ! in_array('.', $segments, true) && ! in_array('..', $segments, true);
        if (! $aiPath && ! ItemFilePath::isConfiguration($relative)) {
            throw new RuntimeException("Unsafe managed path {$relative}.");
        }
        $path = rtrim($this->root, '/');
        foreach (explode('/', $relative) as $part) {
            $path .= '/'.$part;
            if (is_link($path)) {
                throw new RuntimeException("Refusing symlink in managed path {$relative}.");
            }
        }

        return $path;
    }

    private function validateState(array $state): array
    {
        if (($state['files'] !== [] && array_is_list($state['files'])) || ($state['items'] !== [] && array_is_list($state['items']))) {
            throw $this->invalidState('files and items must be mappings');
        }

        $owners = [];
        foreach ($state['items'] as $name => $metadata) {
            if (! is_string($name) || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $name)
                || ! is_array($metadata) || ! $this->validHash($metadata['source_hash'] ?? null)
                || ! is_string($metadata['status'] ?? null) || ! in_array($metadata['status'], ['installed', 'planned', 'conflict', 'partial failure'], true)
                || ! is_array($metadata['files'] ?? null) || ! array_is_list($metadata['files'])) {
                throw $this->invalidState('invalid item metadata for '.(string) $name);
            }

            $seen = [];
            foreach ($metadata['files'] as $path) {
                if (! is_string($path)) {
                    throw $this->invalidState('item '.$name.' files must be a list of managed paths');
                }
                $this->absolute($path);
                if (isset($seen[$path]) || (isset($owners[$path]) && $owners[$path] !== $name)) {
                    throw $this->invalidState('multiple item claims for '.$path);
                }
                $seen[$path] = true;
                $owners[$path] = $name;
            }
        }

        foreach ($state['files'] as $path => $metadata) {
            if (! is_string($path) || ! is_array($metadata) || ! $this->validHash($metadata['hash'] ?? null)) {
                throw $this->invalidState('invalid managed file metadata');
            }
            $this->absolute($path);
            $owner = array_key_exists('owner', $metadata) ? $metadata['owner'] : ($owners[$path] ?? null);
            if (! is_string($owner) || ! isset($state['items'][$owner]) || ($owners[$path] ?? null) !== $owner) {
                throw $this->invalidState('missing or inconsistent item owner for '.$path);
            }
            $state['files'][$path]['owner'] = $owner;
        }

        return $state;
    }

    private function validHash(mixed $hash): bool
    {
        return is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash) === 1;
    }

    private function invalidState(string $reason): RuntimeException
    {
        return new RuntimeException('Invalid Laracanon ownership state ('.$reason.'); restore .ai/laracanon/state.json before installing.');
    }

    public static function write(string $path, string $contents, ?int $mode = null): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create {$directory}.");
        }
        $temporary = @tempnam($directory, '.laracanon-');
        if ($temporary === false) {
            throw new RuntimeException("Cannot create temporary file in {$directory}.");
        }
        try {
            if (@file_put_contents($temporary, $contents) === false) {
                throw new RuntimeException("Cannot write {$path}.");
            }
            if ($mode !== null && ! @chmod($temporary, $mode)) {
                throw new RuntimeException("Cannot set permissions for {$path}.");
            }
            if (! @rename($temporary, $path)) {
                throw new RuntimeException("Cannot write {$path}.");
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
