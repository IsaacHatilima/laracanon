<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Boost;

use Illuminate\Support\Facades\File;
use Laravel\Boost\Contracts\SupportsGuidelines;
use Laravel\Boost\Contracts\SupportsMcp;
use Laravel\Boost\Contracts\SupportsSkills;
use Laravel\Boost\Install\Agents\Agent;
use Laravel\Boost\Install\Enums\McpInstallationStrategy;
use Laravel\Boost\Install\GuidelineComposer;
use Laravel\Boost\Install\GuidelineConfig;
use Laravel\Boost\Install\Mcp\FileWriter;
use Laravel\Boost\Install\Mcp\TomlFileWriter;
use Laravel\Boost\Install\RuleComposer;
use Laravel\Boost\Install\SkillComposer;
use Laravel\Boost\Install\SkillWriter;
use Laravel\Boost\Install\ThirdPartyPackage;
use Laravel\Boost\Rules\RuleRepository;
use Laravel\Boost\Support\RenderFailures;
use Laravel\Boost\Support\SkillParseFailures;
use Laravel\Roster\ProjectManager;
use RuntimeException;
use Throwable;

/** Runs only inside a freshly bootstrapped target Laravel application. */
class BoostProjectRefresher
{
    /** @var array{resources: list<string>, failures: list<string>, notes: list<string>, conflicts: list<string>} */
    private array $report = ['resources' => [], 'failures' => [], 'notes' => [], 'conflicts' => []];

    private ManagedBoostFiles $files;

    private NativeResourceRenderer $renderer;

    private string $temporary;

    /** @param list<string> $selectedPackages */
    public function refresh(array $selectedPackages = []): array
    {
        if (! class_exists(RuleComposer::class) || ! class_exists(RuleRepository::class)) {
            return ['resources' => [], 'failures' => ['Installed Laravel Boost lacks the supported project-rule APIs. Install a compatible stable Boost 2 release with RuleRepository and RuleComposer; Laravel and PHP were not upgraded.'], 'notes' => [], 'conflicts' => []];
        }

        try {
            $this->files = new ManagedBoostFiles(base_path());
        } catch (Throwable $exception) {
            $this->report['failures'][] = $exception->getMessage();

            return $this->report;
        }
        $this->renderer = new NativeResourceRenderer;
        $this->temporary = sys_get_temp_dir().'/laracanon-boost-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->temporary);

        try {
            $configuration = $this->configuration();
            $originalPackages = $configuration['packages'] ?? [];
            $newConfiguration = ! file_exists(base_path('boost.json'));
            app(ProjectManager::class)->fresh();
            app(RenderFailures::class)->flush();
            app(SkillParseFailures::class)->flush();
            $discovered = ThirdPartyPackage::discover(app(ProjectManager::class));

            foreach ($discovered as $package) {
                $this->report['resources'][] = 'Package '.$package->name.': '.$package->featureLabel().' discovered';
            }

            $optedIn = $newConfiguration ? $discovered->keys()->all() : array_intersect($selectedPackages, $discovered->keys()->all());
            $configuration['packages'] = array_values(array_unique(array_merge($configuration['packages'] ?? [], $optedIn)));
            $configuration['agents'] = $configuration['agents'] ?: ['codex'];
            $agents = [];

            foreach ($configuration['agents'] as $name) {
                $agent = Agent::fromName($name);

                if ($agent === null) {
                    $this->report['failures'][] = 'Unsupported Boost agent '.$name.'; other agents will still be refreshed.';
                } else {
                    $agents[] = $agent;
                }
            }

            $options = new GuidelineConfig;
            $options->enforceTests = $this->testEnforcement($agents);
            $options->aiGuidelines = $configuration['packages'];
            $options->usesSail = (bool) ($configuration['sail'] ?? false);
            $options->usesCloud = (bool) ($configuration['cloud'] ?? false);
            $options->hasSkills = true;
            $options->hasMcp = (bool) ($configuration['mcp'] ?? false);

            // Establish the native pre-Laracanon index as a safe first-adoption
            // baseline. This allows a normal existing Boost index to gain rows.
            $baselineIndex = $this->renderer->index($this->temporary.'/index-baseline.md', true);
            $composer = app(GuidelineComposer::class)->config($options);

            if (config('boost.rules.enabled', true) && config('boost.rules.scoped_guidelines', false)) {
                $managed = (new RuleComposer($composer))->composeManaged();
                $kept = [];
                $scopedConflict = false;

                foreach ($managed as $slug => $rule) {
                    $path = '.ai/rules/boost/'.$slug.'.md';
                    $kept[] = $path;
                    $scopedConflict = ! $this->files->write($path, $this->renderer->rule($rule)) || $scopedConflict;
                }

                if ($scopedConflict) {
                    // Keep new package guidance readable inline when extraction
                    // would overwrite an edited previously-generated rule.
                    $composer->withoutRuleExtraction();
                    $this->report['notes'][] = 'Scoped guideline conflicts were preserved; current package guidance remains inline.';
                } else {
                    $this->files->removeStale('.ai/rules/boost/', $kept);
                }
            }

            $index = $this->renderer->index($this->temporary.'/index.md');

            if ($this->files->write('.ai/rules/index.md', $index, $baselineIndex)) {
                $this->report['resources'][] = 'Rule index .ai/rules/index.md regenerated through Boost RuleRepository';
            }

            if (! config('boost.rules.enabled', true)) {
                $this->report['failures'][] = 'Boost project rules are disabled by project configuration; the index was generated, but agents will not receive Boost\'s index-reading instruction.';
            }

            $guidelines = $composer->compose();
            $this->report['resources'] = array_merge($this->report['resources'], array_map(fn ($key): string => 'Guideline '.$key.' discovered', $composer->used()));

            $guidelinePaths = [];
            foreach ($agents as $agent) {
                if ($agent instanceof SupportsGuidelines && ($configuration['guidelines'] ?? false)) {
                    $this->attempt(function () use ($agent, $guidelines, $options, $originalPackages, &$guidelinePaths): void {
                        $path = $this->relative($agent->guidelinesPath());
                        $absolute = $this->files->absolute($path);
                        $destination = (realpath(dirname($absolute)) ?: dirname($absolute)).'/'.basename($absolute);
                        if (isset($guidelinePaths[$destination])) {
                            return;
                        }
                        $guidelinePaths[$destination] = true;
                        $existing = file_exists($absolute) ? (string) file_get_contents($absolute) : '';
                        $rendered = $this->renderer->guideline($agent, $guidelines, $existing, $this->temporary.'/guideline.md');
                        $baseline = $this->guidelineBaseline($agent, $existing, $options, $originalPackages);

                        if ($this->files->writeGuideline($path, $rendered, $baseline)) {
                            $this->report['resources'][] = 'Boost guidelines refreshed in '.$path;
                        }
                    });
                }
            }

            if (! ($configuration['guidelines'] ?? false)) {
                $this->report['notes'][] = 'Existing Boost guidelines=false preference preserved; agent guideline files were not changed.';
            }

            $skills = app(SkillComposer::class)->config($options)->skills();
            $skillNames = [];
            $agentSkillFiles = [];
            $previousOwnedSkillNames = [];

            foreach ($agents as $agent) {
                if ($agent instanceof SupportsSkills) {
                    $prefix = rtrim($this->relative($agent->skillsPath()), '/').'/';
                    $agentSkillFiles[$prefix] = [];
                    $previousOwnedSkillNames = array_merge($previousOwnedSkillNames, $this->files->ownedSkillNames($prefix));
                }
            }

            foreach ($skills as $skill) {
                $this->attempt(function () use ($skill, $agents, &$skillNames, &$agentSkillFiles): void {
                    if (! SkillWriter::isValidSkillName($skill->name)) {
                        throw new RuntimeException('Invalid dependency-provided skill name '.$skill->name);
                    }

                    $staged = $this->temporary.'/skills/'.$skill->name;
                    $priorRenderFailures = app(RenderFailures::class)->paths();

                    if (! $this->renderer->skill($skill, $staged) || app(RenderFailures::class)->paths() !== $priorRenderFailures) {
                        throw new RuntimeException('Cannot render skill '.$skill->name);
                    }

                    $skillNames[] = $skill->name;
                    $this->report['resources'][] = ($skill->custom ? 'Custom' : 'Package').' skill '.$skill->name.' discovered';

                    foreach ($agents as $agent) {
                        if (! $agent instanceof SupportsSkills) {
                            continue;
                        }

                        $agentPrefix = rtrim($this->relative($agent->skillsPath()), '/').'/';
                        $prefix = $agentPrefix.$skill->name.'/';
                        $kept = [];

                        foreach (File::allFiles($staged, hidden: true) as $file) {
                            $path = $prefix.str_replace('\\', '/', $file->getRelativePathname());
                            $kept[] = $path;
                            $agentSkillFiles[$agentPrefix][] = $path;
                            $this->files->write($path, (string) file_get_contents($file->getPathname()));
                        }

                        $this->files->removeStale($prefix, $kept);
                    }
                });
            }

            foreach (app(RenderFailures::class)->paths() as $path) {
                $this->report['failures'][] = 'Boost could not render package guidance or skill '.$path;
            }

            foreach (app(SkillParseFailures::class)->all() as $failure) {
                $this->report['failures'][] = 'Boost could not parse skill '.$failure['path'].': '.$failure['reason'];
            }

            if ($this->report['failures'] === []) {
                $remainingOwnedNames = [];

                foreach ($agentSkillFiles as $prefix => $kept) {
                    $this->files->retireSkills($prefix, $kept);
                    $remainingOwnedNames = array_merge($remainingOwnedNames, $this->files->ownedSkillNames($prefix));
                }

                $configuration['skills'] = array_values(array_unique(array_merge(array_diff($configuration['skills'] ?? [], $previousOwnedSkillNames), $skillNames, $remainingOwnedNames)));
            } else {
                // A malformed or unrenderable source must not retire any skill.
                $configuration['skills'] = array_values(array_unique(array_merge($configuration['skills'] ?? [], $skillNames)));
            }

            foreach ($agents as $agent) {
                if ($agent instanceof SupportsMcp && ($configuration['mcp'] ?? false)) {
                    $this->attempt(fn () => $this->configureMcp($agent, $configuration));
                }
            }

            // Write only additive feature selections, keeping every unrelated
            // key, false value, empty array and Composer script intact.
            $this->writeConfiguration($configuration);
        } catch (Throwable $exception) {
            $this->report['failures'][] = 'Boost refresh failed: '.$exception->getMessage();
        } finally {
            $this->attempt(fn () => $this->files->save());
            File::deleteDirectory($this->temporary);
        }

        $this->report['conflicts'] = array_values(array_unique($this->files->conflicts));

        return $this->report;
    }

    /** @param list<Agent> $agents */
    private function testEnforcement(array $agents): bool
    {
        if (config('boost.enforce_tests') !== null) {
            return (bool) config('boost.enforce_tests');
        }
        foreach ($agents as $agent) {
            if (! $agent instanceof SupportsGuidelines) {
                continue;
            }
            try {
                $path = $this->files->absolute($this->relative($agent->guidelinesPath()));
                $existing = is_file($path) ? (string) file_get_contents($path) : '';
                if (preg_match('/<laravel-boost-guidelines>(.*?)<\/laravel-boost-guidelines>/s', $existing, $block)
                    && preg_match('/^=== tests rules ===$/m', $block[1])) {
                    return true;
                }
            } catch (Throwable) {
                // Invalid destinations are reported in the per-agent refresh.
            }
        }

        return false;
    }

    /** @param list<string> $originalPackages */
    private function guidelineBaseline(SupportsGuidelines $agent, string $existing, GuidelineConfig $options, array $originalPackages): ?string
    {
        if (! preg_match('/<laravel-boost-guidelines>(.*?)<\/laravel-boost-guidelines>/s', $existing, $block)) {
            return null;
        }
        $baseline = clone $options;
        $baseline->aiGuidelines = $originalPackages;
        $baseline->enforceTests = preg_match('/^=== tests rules ===$/m', $block[1]) === 1;
        $baseline->hasSkills = preg_match('/^## Skills Activation$/m', $block[1]) === 1;
        $baseline->hasMcp = preg_match('/^=== boost rules ===\n(.*?)(?=^=== |\z)/ms', $block[1], $boost)
            && preg_match('/^## Tools$/m', $boost[1]);
        $guidelines = $this->renderer->composeGuidelines($baseline);

        return $this->renderer->guideline($agent, $guidelines, $existing, $this->temporary.'/guideline-baseline.md');
    }

    /** @return array<string, mixed> */
    private function configuration(): array
    {
        if (! file_exists(base_path('boost.json'))) {
            return ['agents' => ['codex'], 'guidelines' => true, 'mcp' => true, 'packages' => [], 'skills' => [], 'cloud' => false, 'nightwatch' => false, 'sail' => false];
        }

        $contents = (string) file_get_contents(base_path('boost.json'));
        $value = json_decode($contents, flags: JSON_THROW_ON_ERROR);

        if (! is_object($value)) {
            throw new RuntimeException('boost.json must contain an object; preserved without changes.');
        }

        $config = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        foreach (['agents', 'packages', 'skills'] as $key) {
            if (isset($config[$key]) && (! is_array($config[$key]) || count(array_filter($config[$key], 'is_string')) !== count($config[$key]))) {
                throw new RuntimeException('boost.json '.$key.' must be an array of names; preserved without changes.');
            }
        }

        $config['agents'] ??= [];

        return $config;
    }

    private function configureMcp(Agent&SupportsMcp $agent, array $configuration): void
    {
        if ($agent->mcpInstallationStrategy() !== McpInstallationStrategy::FILE || $agent->mcpConfigPath() === null) {
            $this->report['notes'][] = 'Boost MCP for '.$agent->name().' requires external agent registration; use php artisan boost:install for that agent.';

            return;
        }

        $path = $this->relative($agent->mcpConfigPath());
        $absolute = $this->files->absolute($path);
        $contents = file_exists($absolute) ? (string) file_get_contents($absolute) : '';

        // Preserve an existing server's command, environment and user settings.
        if ($this->hasMcpServer($contents, $agent->mcpConfigKey(), str_ends_with($path, '.toml'))) {
            $this->report['notes'][] = 'Existing laravel-boost MCP configuration preserved in '.$path;

            return;
        }

        if ($configuration['sail'] ?? false) {
            $this->report['notes'][] = 'Sail MCP preference preserved; use boost:install to register the server for '.$agent->name().'.';

            return;
        }

        $stage = $this->temporary.'/mcp'.(str_ends_with($path, '.toml') ? '.toml' : '.json');
        file_put_contents($stage, $contents);
        $writer = str_ends_with($path, '.toml') ? new TomlFileWriter($stage, $agent->defaultMcpConfig()) : new FileWriter($stage, $agent->defaultMcpConfig());
        $success = $writer->configKey($agent->mcpConfigKey())
            ->addServerConfig('laravel-boost', $agent->mcpServerConfig($agent->getPhpPath(), [$agent->getArtisanPath(), 'boost:mcp']))
            ->save();

        if (! $success) {
            throw new RuntimeException('Cannot configure Boost MCP in '.$path.'; existing file preserved.');
        }

        if ($this->files->write($path, (string) file_get_contents($stage), $contents)) {
            $this->report['resources'][] = 'Boost MCP configured in '.$path;
        }
    }

    private function relative(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', base_path()), '/');
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $root.'/') ? substr($path, strlen($root) + 1) : $path;
    }

    private function hasMcpServer(string $contents, string $key, bool $toml): bool
    {
        if ($toml) {
            return preg_match('/^\s*\['.preg_quote($key, '/').'\.laravel-boost\]\s*(?:#.*)?$/m', $contents) === 1;
        }

        $json = json_decode($contents, true);

        if (is_array($json)) {
            return isset($json[$key]['laravel-boost']);
        }

        // Reuse Boost's JSON5 comment masking and brace matching, rather than
        // mistaking a commented-out server or a string mentioning Boost for one.
        $inspector = new class('unused') extends FileWriter
        {
            public function containsServer(string $contents, string $key): bool
            {
                $masked = $this->maskUnquotedComments($contents);
                $pattern = '/(?:["\']'.preg_quote($key, '/').'["\']|\b'.preg_quote($key, '/').')\s*:\s*\{/m';

                if (preg_match($pattern, $masked, $match, PREG_OFFSET_CAPTURE) !== 1) {
                    return false;
                }

                $open = strpos($masked, '{', $match[0][1]);
                $close = $open === false ? false : $this->findMatchingClosingBrace($masked, $open);

                return $close !== false && $this->serverExistsInContent(substr($masked, $open + 1, $close - $open - 1), 'laravel-boost');
            }
        };

        return $inspector->containsServer($contents, $key);
    }

    private function writeConfiguration(array $configuration): void
    {
        $path = $this->files->absolute('boost.json');
        $current = file_exists($path) ? json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : null;

        if ($current !== $configuration && file_put_contents($path, json_encode($configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n") === false) {
            throw new RuntimeException('Cannot save boost.json');
        }
    }

    private function attempt(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            $this->report['failures'][] = 'Boost refresh failed: '.$exception->getMessage();
        }
    }
}
