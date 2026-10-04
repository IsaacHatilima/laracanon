<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Boost;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class PackageSkillDiscovery
{
    /**
     * This read-only scan also works before the target app can bootstrap Boost.
     * Only direct Composer packages are considered, matching Boost's trust boundary.
     *
     * @return list<string>
     */
    public function names(string $projectPath): array
    {
        $composer = json_decode((string) @file_get_contents($projectPath.'/composer.json'), true) ?? [];
        $vendorDirectory = $composer['config']['vendor-dir'] ?? 'vendor';
        $vendorPath = is_string($vendorDirectory) && (str_starts_with($vendorDirectory, '/') || preg_match('/^[a-z]:[\\\\\/]/i', $vendorDirectory))
            ? $vendorDirectory
            : $projectPath.'/'.$vendorDirectory;
        $installed = json_decode((string) @file_get_contents($vendorPath.'/composer/installed.json'), true) ?? [];
        $packages = $installed['packages'] ?? $installed;
        $paths = [];

        foreach (is_array($packages) ? $packages : [] as $package) {
            if (is_array($package) && isset($package['name'])) {
                $paths[$package['name']] = isset($package['install-path'])
                    ? $vendorPath.'/composer/'.$package['install-path']
                    : $vendorPath.'/'.$package['name'];
            }
        }

        $names = [];
        $direct = array_unique(array_merge(array_keys($composer['require'] ?? []), array_keys($composer['require-dev'] ?? [])));

        foreach ($direct as $package) {
            $packagePath = $paths[$package] ?? $vendorPath.'/'.$package;
            $directories = [$packagePath.'/resources/boost/skills'];

            if ($package === 'laravel/boost') {
                $directories[] = $packagePath.'/.ai';
            }

            foreach ($directories as $directory) {
                if (! is_dir($directory)) {
                    continue;
                }

                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                    if (! $file->isFile() || ! in_array($file->getFilename(), ['SKILL.md', 'SKILL.blade.php'], true)) {
                        continue;
                    }

                    $contents = (string) file_get_contents($file->getPathname());
                    $name = basename($file->getPath());

                    try {
                        if (preg_match('/^\s*---\s*\R(.*?)\R---/s', $contents, $match) === 1) {
                            $frontmatter = Yaml::parse($match[1]);
                            $name = is_array($frontmatter) && is_string($frontmatter['name'] ?? null) ? $frontmatter['name'] : $name;
                        }
                    } catch (Throwable) {
                        // A malformed package skill must still reserve its directory name.
                    }

                    $names[] = $name;
                }
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }
}
