<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Installation;

use Isaachatilima\Laracanon\Support\ItemFilePath;
use Nette\Neon\Neon;
use RuntimeException;

final class ManagedFiles
{
    private array $state = ['schema' => 1, 'files' => [], 'items' => []];

    public function __construct(private readonly string $root, private readonly bool $dryRun)
    {
        $path = $this->absolute('.ai/laracanon/state.json');
        if (is_file($path)) {
            $state = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($state) || ! in_array($state['schema'] ?? null, [1, 2], true) || ! is_array($state['files'] ?? null) || ! is_array($state['items'] ?? null)) {
                throw $this->invalidState('expected schema 1 or 2 with files and items mappings');
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

        // A previously existing configuration grants ownership of selected
        // fields only; a later literal item must not acquire the whole file.
        if ($existing !== null && isset($this->state['files'][$relative]['neon_fields'])) {
            if ($existing !== $desired || ! $this->fieldsUnchanged($relative, file_get_contents($path))) {
                return 'conflict';
            }

            return 'unchanged';
        }

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
        if (isset($this->state['files'][$relative]['neon_fields'])) {
            if (file_exists($path) && (! is_file($path) || ! $this->fieldsUnchanged($relative, file_get_contents($path)))) {
                return 'conflict';
            }
            // Removing a convention never deletes an adopted project config.
            unset($this->state['files'][$relative]);

            return 'released';
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

    /** @param list<string> $candidates */
    public function resolveNeonPath(array $candidates): string
    {
        if ($candidates === []) {
            throw new RuntimeException('NEON configuration candidates cannot be empty.');
        }
        foreach ($candidates as $candidate) {
            $path = $this->absolute($candidate);
            if (! $this->isNeon($candidate)) {
                throw new RuntimeException("Invalid NEON configuration candidate {$candidate}.");
            }
            if (file_exists($path)) {
                if (! is_file($path)) {
                    throw new RuntimeException("NEON configuration {$candidate} is not a regular file.");
                }

                return $candidate;
            }
        }

        return $candidates[0];
    }

    public function exists(string $relative): bool
    {
        return is_file($this->absolute($relative));
    }

    /** @param array<string, int|string|bool|float|null> $updates */
    public function updateNeon(string $relative, array $updates, string $owner): string
    {
        if ($updates === []) {
            throw new RuntimeException('NEON configuration updates cannot be empty.');
        }
        $path = $this->absolute($relative);
        if (! $this->isNeon($relative) || ! is_file($path)) {
            throw new RuntimeException("Cannot update NEON configuration {$relative}: expected an existing regular NEON file.");
        }
        if ($this->owner($relative) !== null && $this->owner($relative) !== $owner) {
            return 'conflict';
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Cannot read NEON configuration {$relative}.");
        }
        $metadata = $this->state['files'][$relative] ?? null;
        if ($metadata !== null) {
            if (isset($metadata['neon_fields'])) {
                if (! $this->fieldsUnchanged($relative, $contents)) {
                    return 'conflict';
                }
            } elseif (hash('sha256', $contents) !== $metadata['hash']) {
                return 'conflict';
            }
        }

        $patcher = new NeonPatcher;
        $patched = $patcher->patch($contents, $updates);
        if ($metadata !== null && ! isset($metadata['neon_fields'])) {
            // Configurations created by Laracanon retain full-file ownership.
            return $this->put($relative, $patched, $owner);
        }
        if (! $this->dryRun && $patched !== $contents) {
            clearstatcache(true, $path);
            $mode = @fileperms($path);
            if ($mode === false) {
                throw new RuntimeException("Cannot read permissions for {$relative}.");
            }
            self::write($path, $patched, $mode & 0777);
        }
        $fields = $patcher->values($patched, array_keys($updates));
        $this->state['schema'] = 2;
        // Keep the audit hash stable when unrelated settings change, making
        // repeated installs a no-op. Field comparisons govern conflicts.
        $this->state['files'][$relative] = [
            'hash' => $patched === $contents && $metadata !== null ? $metadata['hash'] : hash('sha256', $patched),
            'owner' => $owner,
            'neon_fields' => $fields,
        ];

        return $patched === $contents ? 'unchanged' : 'updated';
    }

    /**
     * Protect managed configurations used by an active config, including
     * indirect includes. Unresolved includes are treated conservatively.
     *
     * @param  list<string>  $previous
     * @return list<string>
     */
    public function retainedNeonIncludes(string $active, array $previous, ?string $plannedContents = null): array
    {
        $candidates = array_values(array_filter($previous, fn (string $path): bool => $path !== $active && $this->isNeon($path)));
        if ($candidates === []) {
            return [];
        }
        $root = realpath($this->root);
        $activePath = $this->absolute($active);
        $queue = [$activePath];
        $seen = [];
        $retained = [];
        while ($queue !== []) {
            $path = array_pop($queue);
            $virtual = $path === $activePath && $plannedContents !== null;
            $canonical = $virtual && $root !== false ? $root.'/'.$active : realpath($path);
            if ($root === false || $canonical === false || ! str_starts_with($canonical, $root.'/') || is_link($path)) {
                return $candidates;
            }
            if (isset($seen[$canonical])) {
                continue;
            }
            if (count($seen) >= 128 || (! $virtual && ! is_readable($canonical)) || ! preg_match('/\.neon(?:\.dist)?$/D', $canonical)) {
                return $candidates;
            }
            $seen[$canonical] = true;
            try {
                $parsed = Neon::decode($virtual ? $plannedContents : file_get_contents($canonical));
            } catch (\Throwable) {
                return $candidates;
            }
            $includes = is_array($parsed) ? ($parsed['includes'] ?? []) : [];
            if (! is_array($includes)) {
                return $candidates;
            }
            foreach ($includes as $include) {
                if (! is_string($include) || str_contains($include, '%')) {
                    return $candidates;
                }
                $included = str_starts_with($include, '/') ? $include : dirname($canonical).'/'.$include;
                $resolved = realpath($included);
                foreach ($candidates as $candidate) {
                    if ($resolved !== false && $resolved === realpath($this->absolute($candidate))) {
                        $retained[] = $candidate;
                    }
                }
                $queue[] = $included;
            }
        }

        return array_values(array_unique($retained));
    }

    private function fieldsUnchanged(string $relative, string $contents): bool
    {
        $recorded = $this->state['files'][$relative]['neon_fields'];

        return (new NeonPatcher)->values($contents, array_keys($recorded)) === $recorded;
    }

    private function isNeon(string $relative): bool
    {
        return ! str_contains($relative, '/') && ItemFilePath::isConfiguration($relative);
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
            $json = json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)."\n";
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
            if (array_key_exists('neon_fields', $metadata)) {
                $fields = $metadata['neon_fields'];
                if ($state['schema'] !== 2 || ! $this->isNeon($path) || ! is_array($fields) || $fields === [] || array_is_list($fields)) {
                    throw $this->invalidState('invalid NEON field ownership for '.$path);
                }
                $seenFields = [];
                foreach ($fields as $field => $value) {
                    if (! is_string($field) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*$/D', $field)
                        || ! is_array($value) || array_keys($value) !== ['exists', 'value'] || ! is_bool($value['exists'])
                        || (! is_scalar($value['value']) && $value['value'] !== null)
                        || (is_float($value['value']) && ! is_finite($value['value']))
                        || (! $value['exists'] && $value['value'] !== null)) {
                        throw $this->invalidState('invalid NEON field ownership for '.$path);
                    }
                    foreach ($seenFields as $seen) {
                        if (str_starts_with($field, $seen.'.') || str_starts_with($seen, $field.'.')) {
                            throw $this->invalidState('overlapping NEON field ownership for '.$path);
                        }
                    }
                    $seenFields[] = $field;
                }
            }
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
