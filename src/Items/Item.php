<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Items;

final readonly class Item
{
    /**
     * @param  list<string>  $paths
     * @param  list<Dependency>  $dependencies
     * @param  array<string, string>  $files
     * @param  array<string, string>  $minimumVersions
     * @param  array<string, list<string>>  $composerPlugins
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $paths,
        public array $dependencies,
        public ?string $rules,
        public ?string $examples,
        public ?string $skill,
        public ?string $skillName,
        public string $sourceHash,
        public bool $overridesSkill = false,
        public bool $sample = false,
        public array $files = [],
        public array $minimumVersions = [],
        public array $composerPlugins = [],
    ) {}
}
