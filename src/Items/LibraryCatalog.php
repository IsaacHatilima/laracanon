<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Items;

use RuntimeException;

/** Reads the generated delivery from IsaacHatilima/skills. */
final readonly class LibraryCatalog
{
    public const LIBRARY_SCHEMA = 2;

    public function __construct(private string $directory) {}

    /** @return array<string, Item> */
    public function all(): array
    {
        $catalog = json_decode($this->read('catalog.json'), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($catalog) || ! in_array($catalog['schema'] ?? null, [1, self::LIBRARY_SCHEMA], true) || ! is_array($catalog['items'] ?? null) || ! array_is_list($catalog['items']) || $catalog['items'] === []) {
            throw new RuntimeException('Invalid agent-library catalog: expected supported schema and nonempty items.');
        }

        $items = [];
        $destinations = [];
        foreach ($catalog['items'] as $entry) {
            if (! is_array($entry)) {
                throw new RuntimeException('Invalid agent-library item.');
            }
            $name = $entry['name'] ?? null;
            $description = $entry['description'] ?? null;
            if (! is_string($name) || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $name)
                || ! is_string($description) || trim($description) === ''
                || ! is_array($entry['files'] ?? null) || $entry['files'] === [] || array_is_list($entry['files'])) {
                throw new RuntimeException('Invalid agent-library item.');
            }
            if (isset($items[$name])) {
                throw new RuntimeException('Duplicate library item: '.$name.'.');
            }

            $files = [];
            foreach ($entry['files'] as $destination => $source) {
                if (! is_string($destination) || ! preg_match('#^(?:\.(?:ai|agents|claude)/skills/[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*|\.(?:claude|codex)/agents/[A-Za-z0-9_-]+\.(?:md|toml))$#D', $destination)
                    || array_intersect(['.', '..'], explode('/', $destination)) !== [] || ! is_string($source)) {
                    throw new RuntimeException('Unsafe library destination or source.');
                }
                if (isset($destinations[$destination])) {
                    throw new RuntimeException('Duplicate library destination: '.$destination.'.');
                }
                $destinations[$destination] = $name;
                $files[$destination] = $this->read($source);
            }
            $links = $entry['links'] ?? [];
            if (! is_array($links) || ($links !== [] && array_is_list($links))) {
                throw new RuntimeException('Invalid library links.');
            }
            foreach ($links as $alias => $target) {
                $leaf = is_string($alias) ? basename($alias) : '';
                if (! is_string($alias) || ! preg_match('#^\.(?:ai|claude)/skills/[a-z0-9.-]+$#D', $alias)
                    || ! is_string($target) || $target !== '.agents/skills/'.$leaf || ! in_array($leaf, [$name, 'catalog.md'], true)
                    || array_filter(array_keys($files), fn (string $file): bool => $file === $target || str_starts_with($file, $target.'/')) === []) {
                    throw new RuntimeException('Unsafe library link.');
                }
                if (isset($destinations[$alias])) {
                    throw new RuntimeException('Duplicate library destination: '.$alias.'.');
                }
                $destinations[$alias] = $name;
            }

            $items[$name] = new Item(
                name: $name,
                description: $description,
                paths: [],
                dependencies: [],
                rules: null,
                examples: null,
                skill: null,
                skillName: $name,
                sourceHash: hash('sha256', json_encode([$files, $links], JSON_THROW_ON_ERROR)),
                files: $files,
                links: $links,
            );
        }

        ksort($items);

        return $items;
    }

    private function read(string $relative): string
    {
        if (! preg_match('#^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$#D', $relative) || array_intersect(['.', '..'], explode('/', $relative)) !== []) {
            throw new RuntimeException('Unsafe library source: '.$relative.'.');
        }
        $path = rtrim($this->directory, '/');
        foreach (explode('/', $relative) as $part) {
            $path .= '/'.$part;
            if (is_link($path)) {
                throw new RuntimeException('Symlink in library source: '.$relative.'.');
            }
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read library source: '.$relative.'.');
        }

        return $contents;
    }
}
