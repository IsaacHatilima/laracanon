<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Items;

use Isaachatilima\Laracanon\Support\ItemFilePath;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class ItemParser
{
    private const FRONTMATTER_KEYS = [
        'name', 'description', 'paths', 'dependencies', 'minimum_versions', 'composer_plugins', 'skill_name', 'overrides_skill', 'sample',
    ];

    public function parse(string $markdown, ?string $sourcePath = null): Item
    {
        $sourceHash = hash('sha256', $markdown);
        $normalized = str_replace(["\r\n", "\r"], "\n", $markdown);
        $normalized = preg_replace('/\A\xEF\xBB\xBF/', '', $normalized) ?? $normalized;

        if (! preg_match('/\A---[ \t]*\n(.*?)^---[ \t]*(?:\n|\z)(.*)\z/ms', $normalized, $matches)) {
            throw $this->error('An item must start with YAML frontmatter between --- delimiters.', $sourcePath);
        }

        try {
            $metadata = Yaml::parse($matches[1], Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $exception) {
            throw $this->error('Invalid YAML frontmatter: '.$exception->getMessage(), $sourcePath);
        }

        if (! is_array($metadata) || array_is_list($metadata)) {
            throw $this->error('Frontmatter must be a mapping.', $sourcePath);
        }

        foreach (array_keys($metadata) as $key) {
            if (! in_array($key, self::FRONTMATTER_KEYS, true)) {
                throw $this->error('Unknown frontmatter field: '.(string) $key.'.', $sourcePath);
            }
        }

        $name = $metadata['name'] ?? null;
        if (! is_string($name) || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $name)) {
            throw $this->error('name must be a lowercase kebab-case identifier.', $sourcePath);
        }

        if ($sourcePath !== null && basename($sourcePath) !== $name.'.md') {
            throw $this->error('name must match the Markdown filename '.$name.'.md.', $sourcePath);
        }

        $description = $metadata['description'] ?? null;
        if (! is_string($description) || trim($description) === '') {
            throw $this->error('description must be a nonempty string.', $sourcePath);
        }

        $paths = $this->paths($metadata['paths'] ?? null, $sourcePath);
        $dependencies = $this->dependencies(array_key_exists('dependencies', $metadata) ? $metadata['dependencies'] : [], $sourcePath);
        $minimumVersions = $this->minimumVersions(array_key_exists('minimum_versions', $metadata) ? $metadata['minimum_versions'] : [], $dependencies, $sourcePath);
        $composerPlugins = $this->composerPlugins(array_key_exists('composer_plugins', $metadata) ? $metadata['composer_plugins'] : [], $dependencies, $sourcePath);
        $sections = $this->sections($matches[2], $sourcePath);
        $overridesSkill = $this->boolean($metadata, 'overrides_skill', $sourcePath);
        $sample = $this->boolean($metadata, 'sample', $sourcePath);
        $skillName = $metadata['skill_name'] ?? null;

        if ($skillName !== null && (! is_string($skillName) || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $skillName))) {
            throw $this->error('skill_name must be a lowercase kebab-case identifier.', $sourcePath);
        }

        $skill = $sections['Skill'] ?? null;
        if ($skill === null && ($skillName !== null || $overridesSkill)) {
            throw $this->error('skill_name and overrides_skill require nonempty, explicitly authored ## Skill instructions.', $sourcePath);
        }

        if ($overridesSkill && $skillName === null) {
            throw $this->error('overrides_skill requires an explicit skill_name.', $sourcePath);
        }

        return new Item(
            name: $name,
            description: trim($description),
            paths: $paths,
            dependencies: $dependencies,
            rules: $sections['Rules'] ?? null,
            examples: $sections['Examples'] ?? null,
            skill: $skill,
            skillName: $skill === null ? null : ($skillName ?? 'laracanon-'.$name),
            sourceHash: $sourceHash,
            overridesSkill: $overridesSkill,
            sample: $sample,
            files: $this->files($sections['Files'] ?? null, $sourcePath),
            minimumVersions: $minimumVersions,
            composerPlugins: $composerPlugins,
        );
    }

    /** @return list<string> */
    private function paths(mixed $value, ?string $sourcePath): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw $this->error('paths must be a list of relative file paths or glob patterns.', $sourcePath);
        }

        foreach ($value as $path) {
            if (! is_string($path) || trim($path) !== $path || $path === ''
                || preg_match('/[\x00-\x1F\x7F\\\\]/', $path)
                || str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:/', $path)
                || in_array('..', explode('/', $path), true)) {
                throw $this->error('paths must contain safe relative paths without parent traversal.', $sourcePath);
            }
        }

        return array_values(array_unique($value));
    }

    /** @return list<Dependency> */
    private function dependencies(mixed $value, ?string $sourcePath): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw $this->error('dependencies must map runtime and/or development to package-name lists.', $sourcePath);
        }

        $dependencies = [];
        $seen = [];
        foreach ($value as $type => $packages) {
            if (! in_array($type, ['runtime', 'development'], true)) {
                throw $this->error('Unknown dependency type: '.(string) $type.'. Use runtime or development.', $sourcePath);
            }

            if (! is_array($packages) || ! array_is_list($packages)) {
                throw $this->error('dependencies.'.$type.' must be a list of Composer package names.', $sourcePath);
            }

            foreach ($packages as $package) {
                if (! is_string($package) || ! preg_match('/^[a-z0-9](?:[_.-]?[a-z0-9]+)*\/[a-z0-9](?:[_.-]?[a-z0-9]+)*$/D', $package)) {
                    throw $this->error('Dependencies must be Composer package names without version constraints.', $sourcePath);
                }

                if (isset($seen[$package])) {
                    throw $this->error('Duplicate dependency '.$package.'; declare each package once.', $sourcePath);
                }

                $seen[$package] = true;
                $dependencies[] = new Dependency($package, $type);
            }
        }

        return $dependencies;
    }

    /**
     * @param  list<Dependency>  $dependencies
     * @return array<string, string>
     */
    private function minimumVersions(mixed $value, array $dependencies, ?string $sourcePath): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw $this->error('minimum_versions must map declared dependency packages to stable version strings.', $sourcePath);
        }

        $packages = array_map(static fn (Dependency $dependency): string => $dependency->package, $dependencies);

        foreach ($value as $package => $minimum) {
            if (! is_string($package) || $package === '' || ! in_array($package, $packages, true)) {
                throw $this->error('minimum_versions keys must reference packages declared in dependencies.', $sourcePath);
            }

            if (! is_string($minimum) || ! preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/D', $minimum)) {
                throw $this->error('minimum_versions.'.$package.' must be a stable version string in canonical x.y.z format.', $sourcePath);
            }
        }

        return $value;
    }

    /**
     * @param  list<Dependency>  $dependencies
     * @return array<string, list<string>>
     */
    private function composerPlugins(mixed $value, array $dependencies, ?string $sourcePath): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw $this->error('composer_plugins must map declared dependency packages to Composer plugin package-name lists.', $sourcePath);
        }

        $packages = array_map(static fn (Dependency $dependency): string => $dependency->package, $dependencies);

        foreach ($value as $package => $plugins) {
            if (! is_string($package) || ! in_array($package, $packages, true)) {
                throw $this->error('composer_plugins keys must reference packages declared in dependencies.', $sourcePath);
            }

            if (! is_array($plugins) || ! array_is_list($plugins)) {
                throw $this->error('composer_plugins.'.$package.' must be a list of Composer plugin package names.', $sourcePath);
            }

            $seen = [];
            foreach ($plugins as $plugin) {
                if (! is_string($plugin) || ! preg_match('/^[a-z0-9](?:[_.-]?[a-z0-9]+)*\/[a-z0-9](?:[_.-]?[a-z0-9]+)*$/D', $plugin)) {
                    throw $this->error('Composer plugins must be exact lowercase Composer package names without version constraints or wildcards.', $sourcePath);
                }

                if (isset($seen[$plugin])) {
                    throw $this->error('Duplicate Composer plugin '.$plugin.' for '.$package.'; declare each plugin once per dependency.', $sourcePath);
                }

                $seen[$plugin] = true;
            }
        }

        return $value;
    }

    /** @param array<string, mixed> $metadata */
    private function boolean(array $metadata, string $key, ?string $sourcePath): bool
    {
        if (array_key_exists($key, $metadata) && ! is_bool($metadata[$key])) {
            throw $this->error($key.' must be a YAML boolean.', $sourcePath);
        }

        return $metadata[$key] ?? false;
    }

    /** @return array<string, string|null> */
    private function sections(string $body, ?string $sourcePath): array
    {
        $sections = [];
        $current = null;
        $fenceMarker = null;
        $fenceLength = 0;

        foreach (explode("\n", $body) as $line) {
            if ($fenceMarker !== null) {
                if (preg_match('/^ {0,3}'.preg_quote($fenceMarker, '/').'{'.$fenceLength.',}[ \t]*$/D', $line)) {
                    $fenceMarker = null;
                }
            } elseif (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $fence)) {
                $fenceMarker = $fence[1][0];
                $fenceLength = strlen($fence[1]);
            } elseif (preg_match('/^ {0,3}##(?:[ \t]+(.*))?$/D', $line, $heading)) {
                $section = trim($heading[1] ?? '');
                if (! in_array($section, ['Rules', 'Examples', 'Skill', 'Files'], true)) {
                    throw $this->error('Unknown section ## '.$section.'. Use ## Rules, ## Examples, ## Skill, or ## Files.', $sourcePath);
                }

                if (array_key_exists($section, $sections)) {
                    throw $this->error('Duplicate section ## '.$section.'.', $sourcePath);
                }

                $sections[$section] = [];
                $current = $section;

                continue;
            }

            if ($current === null) {
                if (trim($line) !== '') {
                    throw $this->error('Markdown content must appear inside an optional ## Rules, ## Examples, ## Skill, or ## Files section.', $sourcePath);
                }
            } else {
                $sections[$current][] = $line;
            }
        }

        foreach ($sections as $name => $lines) {
            while ($lines !== [] && trim($lines[0]) === '') {
                array_shift($lines);
            }
            while ($lines !== [] && trim($lines[array_key_last($lines)]) === '') {
                array_pop($lines);
            }
            $sections[$name] = $lines === [] ? null : implode("\n", $lines);
        }

        return $sections;
    }

    /** @return array<string, string> */
    private function files(?string $section, ?string $sourcePath): array
    {
        if ($section === null) {
            return [];
        }

        $files = [];
        $lines = explode("\n", $section);
        $index = 0;

        while ($index < count($lines)) {
            if (trim($lines[$index]) === '') {
                $index++;

                continue;
            }

            if (! preg_match('/^ {0,3}###[ \t]+(.*)$/D', $lines[$index], $heading)) {
                throw $this->error('## Files may contain only ### destination headers for configuration files and their fenced literal blocks.', $sourcePath);
            }

            $destination = $heading[1];
            if (! ItemFilePath::isConfiguration($destination)) {
                throw $this->error('File destinations must be canonical relative config/ paths or root filenames ending .neon or .neon.dist, without unsafe characters, parent traversal, or directory suffixes.', $sourcePath);
            }

            if (array_key_exists($destination, $files)) {
                throw $this->error('Duplicate file destination '.$destination.' in ## Files.', $sourcePath);
            }

            $index++;
            while ($index < count($lines) && trim($lines[$index]) === '') {
                $index++;
            }

            if ($index >= count($lines)
                || ! preg_match('/^ {0,3}(`{3,}|~{3,})(?:[ \t]*[A-Za-z0-9_.+-]+)?[ \t]*$/D', $lines[$index], $fence)) {
                throw $this->error('File '.$destination.' must be followed by a fenced literal block with an optional language.', $sourcePath);
            }

            $closing = '/^ {0,3}'.preg_quote($fence[1][0], '/').'{'.strlen($fence[1]).',}[ \t]*$/D';
            $index++;
            $payload = '';

            while ($index < count($lines) && ! preg_match($closing, $lines[$index])) {
                $payload .= $lines[$index]."\n";
                $index++;
            }

            if ($index >= count($lines)) {
                throw $this->error('Unclosed fenced literal block for file '.$destination.' in ## Files.', $sourcePath);
            }

            $files[$destination] = $payload;
            $index++;
        }

        return $files;
    }

    private function error(string $message, ?string $sourcePath): ItemFormatException
    {
        return new ItemFormatException(($sourcePath === null ? '' : $sourcePath.': ').$message);
    }
}
