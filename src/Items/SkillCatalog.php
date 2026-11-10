<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Items;

use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads skill folders: `<directory>/<name>/SKILL.md` plus the support files beside it.
 * Each folder installs once into `.agents/skills/<name>/`, with relative links from the
 * Laravel Boost and Claude Code discovery directories. Hidden files are never installed.
 */
final readonly class SkillCatalog
{
    private const SHARED_ROOT = '.agents/skills/';

    private const DISCOVERY_ROOTS = ['.ai/skills/', '.claude/skills/'];

    private const NAME = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    private const FILE_NAME = '/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/D';

    public function __construct(private string $directory) {}

    public static function supports(string $directory): bool
    {
        return (glob(rtrim($directory, DIRECTORY_SEPARATOR).'/*/SKILL.md') ?: []) !== [];
    }

    /** @return array<string, Item> */
    public function all(): array
    {
        $items = [];
        foreach ($this->entries('') as $name) {
            if (is_dir($this->path($name))) {
                $items[$name] = $this->item($name);
            }
        }

        return $items;
    }

    private function item(string $name): Item
    {
        if (! preg_match(self::NAME, $name)) {
            throw new RuntimeException('Invalid skill folder name: '.$name.'.');
        }
        if (! is_file($this->path($name.'/SKILL.md'))) {
            throw new RuntimeException('Skill folder '.$name.' has no SKILL.md.');
        }

        $files = [];
        foreach ($this->filesUnder($name) as $relative) {
            $files[self::SHARED_ROOT.$relative] = $this->read($relative);
        }
        $description = $this->description($name, $files[self::SHARED_ROOT.$name.'/SKILL.md']);
        $links = [];
        foreach (self::DISCOVERY_ROOTS as $root) {
            $links[$root.$name] = self::SHARED_ROOT.$name;
        }

        return new Item(
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

    private function description(string $name, string $markdown): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $markdown);
        if (! preg_match('/\A---[ \t]*\n(.*?)^---[ \t]*(?:\n|\z)/ms', $normalized, $matches)) {
            throw new RuntimeException($name.'/SKILL.md: expected YAML frontmatter between --- delimiters.');
        }
        try {
            $metadata = Yaml::parse($matches[1], Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $exception) {
            throw new RuntimeException($name.'/SKILL.md: invalid YAML frontmatter: '.$exception->getMessage());
        }
        if (! is_array($metadata) || ($metadata['name'] ?? null) !== $name) {
            throw new RuntimeException($name.'/SKILL.md: name must match its folder.');
        }
        $description = $metadata['description'] ?? null;
        if (! is_string($description) || trim($description) === '') {
            throw new RuntimeException($name.'/SKILL.md: description must be a nonempty string.');
        }

        return $description;
    }

    /** @return list<string> Paths relative to the catalog directory, sorted. */
    private function filesUnder(string $relative): array
    {
        $files = [];
        foreach ($this->entries($relative) as $entry) {
            $child = $relative.'/'.$entry;
            if (! preg_match(self::FILE_NAME, $entry)) {
                throw new RuntimeException('Unsafe skill file name: '.$child.'.');
            }
            if (is_dir($this->path($child))) {
                array_push($files, ...$this->filesUnder($child));
            } elseif (is_file($this->path($child))) {
                $files[] = $child;
            } else {
                throw new RuntimeException('Unsupported skill source: '.$child.'.');
            }
        }

        return $files;
    }

    /** @return list<string> Visible entry names, sorted case-insensitively; symlinks are refused. */
    private function entries(string $relative): array
    {
        $entries = @scandir($this->path($relative));
        if ($entries === false) {
            throw new RuntimeException('Cannot read skill directory: '.$this->path($relative).'.');
        }
        $visible = [];
        foreach ($entries as $entry) {
            if (str_starts_with($entry, '.')) {
                continue;
            }
            $child = ltrim($relative.'/'.$entry, '/');
            if (is_link($this->path($child))) {
                throw new RuntimeException('Symlink in skill source: '.$child.'.');
            }
            $visible[] = $entry;
        }
        sort($visible, SORT_STRING | SORT_FLAG_CASE);

        return $visible;
    }

    private function read(string $relative): string
    {
        $contents = @file_get_contents($this->path($relative));
        if ($contents === false) {
            throw new RuntimeException('Cannot read skill source: '.$relative.'.');
        }

        return $contents;
    }

    private function path(string $relative): string
    {
        return rtrim($this->directory, DIRECTORY_SEPARATOR).($relative === '' ? '' : '/'.$relative);
    }
}
