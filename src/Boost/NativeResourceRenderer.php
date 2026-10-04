<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Boost;

use Laravel\Boost\Contracts\SupportsGuidelines;
use Laravel\Boost\Contracts\SupportsSkills;
use Laravel\Boost\Install\GuidelineComposer;
use Laravel\Boost\Install\GuidelineConfig;
use Laravel\Boost\Install\GuidelineWriter;
use Laravel\Boost\Install\Herd;
use Laravel\Boost\Install\Skill;
use Laravel\Boost\Install\SkillWriter;
use Laravel\Boost\Rules\RuleRepository;
use Laravel\Roster\ProjectManager;

class NativeResourceRenderer
{
    public function composeGuidelines(GuidelineConfig $options): string
    {
        return (new GuidelineComposer(app(ProjectManager::class), app(Herd::class)))->config($options)->compose();
    }

    public function guideline(SupportsGuidelines $agent, string $guidelines, string $existing, string $temporary): string
    {
        file_put_contents($temporary, $existing);
        $stagedAgent = new class($agent, $temporary) implements SupportsGuidelines
        {
            public function __construct(private SupportsGuidelines $agent, private string $path) {}

            public function guidelinesPath(): string
            {
                return $this->path;
            }

            public function frontmatter(): bool
            {
                return $this->agent->frontmatter();
            }

            public function transformGuidelines(string $markdown): string
            {
                return $this->agent->transformGuidelines($markdown);
            }
        };
        (new GuidelineWriter($stagedAgent))->write($guidelines);

        return (string) file_get_contents($temporary);
    }

    public function skill(Skill $skill, string $temporary): bool
    {
        $writer = new class(new class implements SupportsSkills
        {
            public function skillsPath(): string
            {
                return '.agents/skills';
            }
        }) extends SkillWriter
        {

            public function render(Skill $skill, string $directory): bool
            {
                return $this->copyDirectory($skill->path, $directory);
            }
        };

        return $writer->render($skill, $temporary);
    }

    /** @param array{paths: array<int, string>, title: string, content: string} $file */
    public function rule(array $file): string
    {
        $repository = new class(base_path('.ai/rules')) extends RuleRepository
        {
            public function render(array $file): string
            {
                return $this->renderManagedFile($file['paths'], $file['title'], $file['content']);
            }
        };

        return $repository->render($file);
    }

    public function index(string $temporary, bool $excludeLaracanon = false): string
    {
        $repository = new class(base_path('.ai/rules'), $temporary, $excludeLaracanon) extends RuleRepository
        {
            public function __construct(string $directory, private string $destination, private bool $excludeLaracanon)
            {
                parent::__construct($directory);
            }

            protected function indexPath(): string
            {
                return $this->destination;
            }

            protected function files(): array
            {
                return array_values(array_filter(parent::files(), fn (string $file): bool => ! $this->excludeLaracanon || ! str_starts_with(basename($file), 'laracanon-')));
            }
        };
        $repository->writeIndex();

        return (string) file_get_contents($temporary);
    }
}
