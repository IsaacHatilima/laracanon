<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Boost;

use RuntimeException;

/** Ownership for derived Boost outputs, separate from Laracanon's item files. */
class ManagedBoostFiles
{
    // Verified against https://github.com/laravel/laravel/blob/v13.10.1/AGENTS.md.
    // Only the unchanged scaffold block is eligible for initial adoption.
    private const LARAVEL_BOOTSTRAP_HASH = 'e4f9841acc3d38f50c4b6ce4853876f7c12a4ce8a89dfa53613dbb9a7ea72031';

    /** @var array<string, string> */
    private array $hashes;

    /** @var list<string> */
    public array $conflicts = [];

    public function __construct(private readonly string $projectPath)
    {
        $path = $this->absolute('.ai/laracanon/boost-state.json');
        $state = file_exists($path) ? json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];

        if (! is_array($state) || ! is_array($state['hashes'] ?? []) || (file_exists($path) && ($state['version'] ?? null) !== 1)) {
            throw new RuntimeException('Invalid Laracanon Boost ownership state; no Boost outputs were changed.');
        }

        $this->hashes = $state['hashes'] ?? [];

        foreach ($this->hashes as $managedPath => $hash) {
            if (! is_string($managedPath) || ! is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new RuntimeException('Invalid Laracanon Boost managed path or hash; no Boost outputs were changed.');
            }

            $this->absolute(str_ends_with($managedPath, '#boost-guidelines') ? substr($managedPath, 0, -strlen('#boost-guidelines')) : $managedPath);
        }
    }

    public function write(string $path, string $contents, ?string $trustedBaseline = null): bool
    {
        $absolute = $this->absolute($path);
        $current = file_exists($absolute) ? (string) file_get_contents($absolute) : null;
        $expected = $this->hashes[$path] ?? null;

        if ($current !== null && $current !== $contents && $expected !== hash('sha256', $current) && $current !== $trustedBaseline) {
            $this->conflicts[] = 'Conflict: '.$path.' is locally modified or has no verifiable managed baseline; preserved.';

            return false;
        }

        if ($current !== $contents) {
            $this->put($absolute, $contents);
        }

        $this->hashes[$path] = hash('sha256', $contents);

        return true;
    }

    /** Only own the Boost marker, allowing edits to surrounding project instructions. */
    public function writeGuideline(string $path, string $contents, ?string $trustedBaseline = null): bool
    {
        $absolute = $this->absolute($path);
        $current = file_exists($absolute) ? (string) file_get_contents($absolute) : '';
        preg_match_all('/<laravel-boost-guidelines>.*?<\/laravel-boost-guidelines>/s', $current, $matches);
        $block = $matches[0][0] ?? '';
        preg_match('/<laravel-boost-guidelines>.*?<\/laravel-boost-guidelines>/s', $contents, $desired);
        $newBlock = $desired[0] ?? throw new RuntimeException('Boost generated guidelines without the expected ownership marker.');
        preg_match('/<laravel-boost-guidelines>.*?<\/laravel-boost-guidelines>/s', $trustedBaseline ?? '', $baseline);
        $key = $path.'#boost-guidelines';
        $officialBootstrap = ! isset($this->hashes[$key])
            && substr_count($current, '<laravel-boost-guidelines>') === 1
            && substr_count($current, '</laravel-boost-guidelines>') === 1
            && hash('sha256', $block) === self::LARAVEL_BOOTSTRAP_HASH;

        if (count($matches[0]) > 1 || ($block !== '' && $block !== $newBlock && ($this->hashes[$key] ?? null) !== hash('sha256', $block) && $block !== ($baseline[0] ?? null) && ! $officialBootstrap)) {
            $this->conflicts[] = 'Conflict: Boost block in '.$path.' is locally modified or has no verifiable managed baseline; preserved.';

            return false;
        }

        if ($block !== '') {
            $contents = preg_replace_callback('/<laravel-boost-guidelines>.*?<\/laravel-boost-guidelines>/s', fn (): string => $newBlock, $current, 1);
        }
        if ($current !== $contents) {
            $this->put($absolute, $contents);
        }

        $this->hashes[$key] = hash('sha256', $newBlock);

        return true;
    }

    /** @param list<string> $keep */
    public function removeStale(string $prefix, array $keep): void
    {
        foreach ($this->hashes as $path => $hash) {
            if (! str_starts_with($path, $prefix) || in_array($path, $keep, true) || str_contains($path, '#')) {
                continue;
            }

            $absolute = $this->absolute($path);

            if (is_file($absolute) && hash_file('sha256', $absolute) !== $hash) {
                $this->conflicts[] = 'Conflict: obsolete Boost file '.$path.' was edited; preserved.';

                continue;
            }

            if (is_file($absolute) && ! unlink($absolute)) {
                throw new RuntimeException('Cannot remove obsolete Boost file '.$path);
            }

            unset($this->hashes[$path]);
        }
    }

    public function save(): void
    {
        ksort($this->hashes);
        $path = $this->absolute('.ai/laracanon/boost-state.json');
        $contents = json_encode(['version' => 1, 'hashes' => $this->hashes], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        if (is_file($path) && file_get_contents($path) === $contents) {
            return;
        }

        $this->put($path, $contents);
    }

    /** @return list<string> */
    public function ownedSkillNames(string $prefix): array
    {
        $names = [];

        foreach (array_keys($this->hashes) as $path) {
            if (str_starts_with($path, $prefix)) {
                $names[] = explode('/', substr($path, strlen($prefix)))[0];
            }
        }

        return array_values(array_unique($names));
    }

    /** @param list<string> $keep */
    public function retireSkills(string $prefix, array $keep): void
    {
        $currentNames = array_unique(array_map(fn (string $path): string => explode('/', substr($path, strlen($prefix)))[0], $keep));

        foreach (array_diff($this->ownedSkillNames($prefix), $currentNames) as $name) {
            $skillPrefix = $prefix.$name.'/';
            $edited = false;

            foreach ($this->hashes as $path => $hash) {
                if (str_starts_with($path, $skillPrefix) && is_file($this->absolute($path)) && hash_file('sha256', $this->absolute($path)) !== $hash) {
                    $edited = true;
                    break;
                }
            }

            if ($edited) {
                $this->conflicts[] = 'Conflict: obsolete Boost skill '.$prefix.$name.' was edited; preserved.';
            } else {
                $this->removeStale($skillPrefix, []);
            }
        }
    }

    public function absolute(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', realpath($this->projectPath) ?: $this->projectPath), '/');

        if (str_starts_with($path, $root.'/')) {
            $path = substr($path, strlen($root) + 1);
        }

        if ($path === '' || str_starts_with($path, '/') || preg_match('/^[a-z]:/i', $path) || in_array('..', explode('/', $path), true)) {
            throw new RuntimeException('Boost output must stay inside the Laravel project: '.$path);
        }

        $absolute = $root.'/'.$path;
        $ancestor = $absolute;

        while (! file_exists($ancestor) && ! is_link($ancestor)) {
            $ancestor = dirname($ancestor);
        }

        $resolved = realpath($ancestor);

        if ($resolved === false || ($resolved !== $root && ! str_starts_with(str_replace('\\', '/', $resolved), $root.'/'))) {
            throw new RuntimeException('Boost output points outside the Laravel project: '.$path);
        }

        return $absolute;
    }

    private function put(string $path, string $contents): void
    {
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0755, true) && ! is_dir(dirname($path))) {
            throw new RuntimeException('Cannot create Boost output directory '.dirname($path));
        }

        // Rename rather than follow an existing file symlink into a custom source.
        $temporary = tempnam(dirname($path), '.laracanon-');

        if ($temporary === false || file_put_contents($temporary, $contents) === false || ! rename($temporary, $path)) {
            throw new RuntimeException('Cannot write Boost output '.$path);
        }
    }
}
