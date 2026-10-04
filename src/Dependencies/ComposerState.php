<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Dependencies;

use JsonException;
use RuntimeException;

/** A disk snapshot, so a lock entry alone never counts as an installed package. */
final readonly class ComposerState
{
    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string>  $installed
     * @param  array<string, string>  $locked
     * @param  array<string, string>  $installedReferences
     * @param  array<string, string>  $lockedReferences
     */
    public function __construct(
        public array $manifest,
        public array $installed,
        public array $locked,
        public bool $hasLock,
        public array $installedReferences = [],
        public array $lockedReferences = [],
    ) {}

    public static function inspect(string $projectPath): self
    {
        $manifest = self::readJson($projectPath.'/composer.json');
        $vendorDirectory = $manifest['config']['vendor-dir'] ?? 'vendor';

        if (! is_string($vendorDirectory) || $vendorDirectory === '') {
            throw new RuntimeException('composer.json config.vendor-dir must be a non-empty string.');
        }

        $vendorPath = str_starts_with($vendorDirectory, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $vendorDirectory)
            ? $vendorDirectory
            : $projectPath.'/'.$vendorDirectory;
        $installedPath = $vendorPath.'/composer/installed.json';
        $installedData = is_file($installedPath) ? self::readJson($installedPath) : [];
        $installedPackages = $installedData['packages'] ?? $installedData;
        $lockPath = $projectPath.'/composer.lock';
        $lock = is_file($lockPath) ? self::readJson($lockPath) : [];

        return new self(
            $manifest,
            self::versions($installedPackages),
            self::versions(array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? [])),
            is_file($lockPath),
            self::references($installedPackages),
            self::references(array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? [])),
        );
    }

    public function section(string $package): ?string
    {
        foreach (['require', 'require-dev'] as $section) {
            if (array_key_exists($package, $this->manifest[$section] ?? [])) {
                return $section;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function readJson(string $path): array
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Cannot read '.$path.'.');
        }

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid JSON in '.$path.': '.$exception->getMessage(), 0, $exception);
        }

        if (! is_array($data)) {
            throw new RuntimeException('Expected a JSON object or array in '.$path.'.');
        }

        return $data;
    }

    /** @param array<mixed> $packages
     * @return array<string, string>
     */
    private static function versions(array $packages): array
    {
        $versions = [];

        foreach ($packages as $package) {
            if (is_array($package) && isset($package['name'], $package['version'])) {
                $versions[$package['name']] = $package['version'];
            }
        }

        return $versions;
    }

    /**
     * @param  array<mixed>  $packages
     * @return array<string, string>
     */
    private static function references(array $packages): array
    {
        $references = [];

        foreach ($packages as $package) {
            if (is_array($package) && isset($package['name'])) {
                $reference = $package['source']['reference'] ?? $package['dist']['reference'] ?? null;

                if (is_string($reference) && $reference !== '') {
                    $references[$package['name']] = $reference;
                }
            }
        }

        return $references;
    }
}
