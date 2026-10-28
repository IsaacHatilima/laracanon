<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Tests\Integration\Items;

use Illuminate\Support\Facades\File;
use Isaachatilima\Laracanon\Boost\BoostIntegration;
use Isaachatilima\Laracanon\Boost\BoostProjectRefresher;
use Isaachatilima\Laracanon\Dependencies\ComposerDependencies;
use Isaachatilima\Laracanon\Installation\Installer;
use Isaachatilima\Laracanon\Items\ItemCatalog;
use Isaachatilima\Laracanon\Support\ProcessResult;
use Isaachatilima\Laracanon\Support\ProcessRunner;
use Laravel\Boost\BoostManager;
use Laravel\Boost\BoostServiceProvider;
use Laravel\Roster\ProjectManager;
use Orchestra\Testbench\TestCase;

final class CatalogInstallationTest extends TestCase
{
    private string $project;

    private array $commands = [];

    protected function getPackageProviders($app): array
    {
        return [BoostServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = sys_get_temp_dir().'/laracanon-catalog-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->project.'/vendor/composer');
        $this->app->setBasePath($this->project);
        $this->app->useStoragePath($this->project.'/storage');
        $this->app->singleton(ProjectManager::class, fn () => new ProjectManager);
        $this->app->singleton(BoostManager::class, fn () => new BoostManager);
        $this->app['config']->set('view.compiled', $this->project.'/storage/framework/views');
        File::ensureDirectoryExists($this->project.'/storage/framework/views');
        symlink(dirname(__DIR__, 3).'/vendor/laravel', $this->project.'/vendor/laravel');
        $this->write('artisan', '<?php');
        $this->write('bootstrap/app.php', '<?php');
        $this->write('.ai/rules/project-global.md', "---\npaths: ['**/*']\n---\n\n# Existing project rules\n\nKeep this project preference.\n");
        $this->writeJson('composer.json', [
            'name' => 'example/laravel-app',
            'require' => ['php' => '^8.3', 'laravel/framework' => '^13.0'],
            'require-dev' => ['laravel/boost' => '^2.10'],
            'scripts' => ['post-update-cmd' => ['@php artisan existing:workflow']],
        ]);
        $this->writeJson('composer.lock', [
            'packages' => [['name' => 'laravel/framework', 'version' => 'v13.34.0']],
            'packages-dev' => [['name' => 'laravel/boost', 'version' => 'v2.10.1']],
        ]);
        $this->writeJson('vendor/composer/installed.json', ['packages' => [
            ['name' => 'laravel/framework', 'version' => 'v13.34.0'],
            ['name' => 'laravel/boost', 'version' => 'v2.10.1'],
        ]]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->project);
        parent::tearDown();
    }

    public function test_shared_catalog_installs_factory_and_laravel_skills_with_native_agents(): void
    {
        $items = $this->catalog()->all();
        self::assertArrayHasKey('factory', $items);
        self::assertArrayHasKey('laravel-actions', $items);
        self::assertArrayNotHasKey('plans', $items);
        $composer = $this->read('composer.json');
        $report = $this->installer()->install($this->project);

        self::assertTrue($report->successful(), implode("\n", array_merge($report->failures, $report->conflicts)));
        self::assertSame($composer, $this->read('composer.json'));
        self::assertSame([], $this->commands);
        self::assertCount(count($items), $report->items);
        foreach ($items as $item) {
            self::assertTrue($item->name === 'factory' || str_starts_with($item->name, 'laravel-'));
            foreach ($item->files as $path => $contents) {
                self::assertSame($contents, $this->read($path), $path);
            }
        }
        self::assertCount(8, glob($this->project.'/.claude/agents/*.md'));
        self::assertCount(8, glob($this->project.'/.codex/agents/*.toml'));
        self::assertStringContainsString('disable-model-invocation: true', $this->read('.agents/skills/factory/SKILL.md'));
        self::assertStringNotContainsString('| nest/', $this->read('.agents/skills/catalog.md'));
        foreach (['.ai', '.claude'] as $host) {
            self::assertTrue(is_link($this->project.'/'.$host.'/skills/laravel-actions'));
            self::assertSame(realpath($this->project.'/.agents/skills/laravel-actions'), realpath($this->project.'/'.$host.'/skills/laravel-actions'));
            self::assertTrue(is_link($this->project.'/'.$host.'/skills/factory'));
        }
        self::assertDirectoryDoesNotExist($this->project.'/.codex/skills');
    }

    private function installLegacyCopies(): void
    {
        $root = $this->project.'/legacy-library';
        File::copyDirectory(dirname(__DIR__, 3).'/resources/agent-library', $root);
        $catalog = json_decode((string) file_get_contents($root.'/catalog.json'), true);
        $catalog['schema'] = 1;
        foreach ($catalog['items'] as &$entry) {
            unset($entry['links']);
            foreach ($entry['files'] as $path => $source) {
                if (str_starts_with($path, '.agents/skills/')) {
                    foreach (['.ai', '.claude'] as $host) {
                        $entry['files'][preg_replace('/^\\.agents/', $host, $path)] = $source;
                    }
                }
            }
        }
        unset($entry);
        file_put_contents($root.'/catalog.json', json_encode($catalog, JSON_THROW_ON_ERROR));
        $report = $this->installer(new ItemCatalog($root))->install($this->project);
        self::assertTrue($report->successful(), implode("\n", array_merge($report->failures, $report->conflicts)));
    }

    public function test_upgrade_replaces_verified_copies_with_links_and_boost_refresh_keeps_them(): void
    {
        $this->installLegacyCopies();
        self::assertFalse(is_link($this->project.'/.claude/skills/laravel-actions'));
        $before = $this->snapshot();
        $preview = $this->installer()->install($this->project, dryRun: true);
        self::assertTrue($preview->successful(), implode("\n", array_merge($preview->failures, $preview->conflicts)));
        self::assertSame($before, $this->snapshot());
        $report = $this->installer()->install($this->project);
        self::assertTrue($report->successful(), implode("\n", array_merge($report->failures, $report->conflicts)));
        foreach (['.ai', '.claude'] as $host) {
            self::assertTrue(is_link($this->project.'/'.$host.'/skills/laravel-actions'));
            self::assertTrue(is_link($this->project.'/'.$host.'/skills/factory'));
        }
        $before = $this->snapshot();
        $refresh = (new BoostProjectRefresher)->refresh();
        self::assertSame([], $refresh['failures']);
        self::assertSame([], $refresh['conflicts']);
        self::assertSame($before, $this->snapshot());
    }

    public function test_upgrade_preserves_edited_legacy_copy_and_can_retry_after_reconciliation(): void
    {
        $this->installLegacyCopies();
        $this->write('.claude/skills/laravel-actions/SKILL.md', 'My local instructions.');
        $report = $this->installer()->install($this->project, ['laravel-actions']);
        self::assertFalse($report->successful());
        self::assertSame('My local instructions.', $this->read('.claude/skills/laravel-actions/SKILL.md'));
        self::assertFalse(is_link($this->project.'/.claude/skills/laravel-actions'));
        $this->write('.claude/skills/laravel-actions/SKILL.md', $this->read('.agents/skills/laravel-actions/SKILL.md'));
        $report = $this->installer()->install($this->project, ['laravel-actions']);
        self::assertTrue($report->successful(), implode("\n", array_merge($report->failures, $report->conflicts)));
        self::assertTrue(is_link($this->project.'/.claude/skills/laravel-actions'));
    }

    public function test_unrelated_alias_symlink_is_refused_before_installing_files_or_dependencies(): void
    {
        File::ensureDirectoryExists($this->project.'/.claude/skills');
        File::ensureDirectoryExists($this->project.'/outside');
        symlink($this->project.'/outside', $this->project.'/.claude/skills/factory');
        $report = $this->installer()->install($this->project, ['factory']);
        self::assertFalse($report->successful());
        self::assertSame([], $this->commands);
        self::assertFileDoesNotExist($this->project.'/.agents/skills/factory/SKILL.md');
    }

    public function test_selected_skill_installs_support_files_without_modifying_project_configuration(): void
    {
        $this->write('config/insights.php', '<?php return ["project" => true];');
        $this->write('phpstan.neon', "parameters:\n    level: 7\n");
        $report = $this->installer()->install($this->project, ['laravel-insights', 'laravel-phpstan', 'laravel-api-responses']);

        self::assertTrue($report->successful(), implode("\n", array_merge($report->failures, $report->conflicts)));
        self::assertSame([], $this->commands);
        self::assertSame('<?php return ["project" => true];', $this->read('config/insights.php'));
        self::assertSame("parameters:\n    level: 7\n", $this->read('phpstan.neon'));
        self::assertFileExists($this->project.'/.agents/skills/laravel-insights/insights.php');
        self::assertFileExists($this->project.'/.agents/skills/laravel-phpstan/phpstan.neon');
        self::assertFileExists($this->project.'/.agents/skills/laravel-api-responses/templates/ApiResponse.php');
        self::assertFileDoesNotExist($this->project.'/.agents/skills/factory/SKILL.md');
    }

    public function test_repeat_install_is_idempotent_and_preserves_a_locally_edited_native_skill(): void
    {
        $installer = $this->installer();
        self::assertTrue($installer->install($this->project)->successful());
        $before = $this->snapshot();
        $configuration = $this->read('boost.json');
        self::assertTrue($installer->install($this->project)->successful());
        self::assertSame($configuration, $this->read('boost.json'));
        self::assertSame($before, $this->snapshot());

        $path = '.agents/skills/factory/AGENT.md';
        $this->write($path, 'Local workflow changes.');
        $report = $installer->install($this->project, ['factory']);
        self::assertFalse($report->successful());
        self::assertNotEmpty($report->conflicts);
        self::assertSame('Local workflow changes.', $this->read($path));
    }

    public function test_shared_catalog_dry_run_has_no_file_or_process_side_effects(): void
    {
        $before = $this->snapshot();
        $report = $this->installer()->install($this->project, dryRun: true);
        self::assertTrue($report->successful(), implode("\n", array_merge($report->failures, $report->conflicts)));
        self::assertNotEmpty($report->skills);
        self::assertSame([], $this->commands);
        self::assertSame($before, $this->snapshot());
    }

    public function test_shared_skill_does_not_override_a_dependency_skill_with_the_same_name(): void
    {
        $composer = json_decode($this->read('composer.json'), true);
        $composer['require-dev']['example/skill-package'] = '^1.0';
        $this->writeJson('composer.json', $composer);
        $this->write('vendor/example/skill-package/resources/boost/skills/laravel-actions/SKILL.md', "---\nname: laravel-actions\ndescription: Dependency skill.\n---\n\nDependency instructions.\n");

        $report = $this->installer()->install($this->project, ['laravel-actions']);
        self::assertFalse($report->successful());
        self::assertStringContainsString('package-provided skill preserved', implode("\n", $report->conflicts));
        self::assertFileDoesNotExist($this->project.'/.ai/skills/laravel-actions/SKILL.md');
    }

    private function catalog(): ItemCatalog
    {
        return new ItemCatalog(dirname(__DIR__, 3).'/resources/agent-library');
    }

    public function test_package_only_installs_only_its_runtime_dependency_and_package_skill_and_repeats_without_changes(): void
    {
        $catalog = $this->packageOnlyCatalog();
        $packageSkillPath = 'vendor/example/package-tool/resources/boost/skills/package-tool-development/SKILL.md';
        // Mock the dependency's installed resource while exercising Boost's
        // native third-party skill discovery and rendering.
        $packageSkill = "---\nname: package-tool-development\ndescription: Follow the dependency-provided package-tool workflow.\n---\n\n# Package workflow\n\nUse the package-authored implementation workflow.\n";
        $this->write($packageSkillPath, $packageSkill);
        $report = $this->installer($catalog)->install($this->project, ['fixture-package-only']);

        self::assertTrue($report->successful(), implode("\n", array_merge($report->failures, $report->conflicts)));
        self::assertSame(['fixture-package-only' => 'installed'], $report->items);
        self::assertCount(1, $this->commands);
        self::assertSame('example/package-tool:*@stable', $this->commands[0][2]);
        self::assertNotContains('--dev', $this->commands[0]);
        $composer = json_decode($this->read('composer.json'), true);
        self::assertSame('*@stable', $composer['require']['example/package-tool']);
        self::assertArrayNotHasKey('example/package-tool', $composer['require-dev']);
        self::assertSame('^13.0', $composer['require']['laravel/framework']);
        self::assertSame('^2.10', $composer['require-dev']['laravel/boost']);
        self::assertSame(['@php artisan existing:workflow'], $composer['scripts']['post-update-cmd']);
        self::assertSame([], $report->rules);
        self::assertSame([], $report->skills);
        self::assertFileDoesNotExist($this->project.'/.ai/rules/laracanon-fixture-package-only.md');
        self::assertFileDoesNotExist($this->project.'/.ai/skills/laracanon-fixture-package-only/SKILL.md');
        self::assertFileDoesNotExist($this->project.'/.agents/skills/laracanon-fixture-package-only/SKILL.md');
        self::assertStringContainsString('Use the package-authored implementation workflow.', $this->read('.agents/skills/package-tool-development/SKILL.md'));
        self::assertSame($packageSkill, $this->read($packageSkillPath));
        self::assertContains('example/package-tool', json_decode($this->read('boost.json'), true)['packages']);
        self::assertStringNotContainsString('laracanon-fixture-package-only.md', $this->read('.ai/rules/index.md'));

        $before = $this->snapshot();
        $repeat = $this->installer($catalog)->install($this->project, ['fixture-package-only']);

        self::assertTrue($repeat->successful(), implode("\n", array_merge($repeat->failures, $repeat->conflicts)));
        self::assertCount(1, $this->commands, 'An installed dependency is not resolved again.');
        self::assertSame($before, $this->snapshot(), 'Repeated installation preserves the dependency version and every installed resource.');
    }

    public function test_package_only_dry_run_launches_no_process_and_writes_no_files(): void
    {
        $catalog = $this->packageOnlyCatalog();
        $before = $this->snapshot();
        $report = $this->installer($catalog)->install($this->project, ['fixture-package-only'], true);

        self::assertTrue($report->successful(), implode("\n", array_merge($report->failures, $report->conflicts)));
        self::assertSame(['fixture-package-only' => 'planned'], $report->items);
        self::assertSame([], $this->commands);
        self::assertSame($before, $this->snapshot());
        self::assertSame([], $report->rules);
        self::assertSame([], $report->skills);
    }

    public function test_rules_only_selection_installs_no_data_dependency_or_authored_workflow(): void
    {
        $report = $this->installer($this->fixtureCatalog())->install($this->project, ['fixture-rules-only']);

        self::assertTrue($report->successful(), implode("\n", array_merge($report->failures, $report->conflicts)));
        self::assertSame([], $this->commands);
        self::assertCount(1, $report->rules);
        self::assertSame([], $report->skills);
        $composer = json_decode($this->read('composer.json'), true);
        self::assertArrayNotHasKey('spatie/laravel-data', $composer['require']);
        self::assertArrayNotHasKey('spatie/laravel-data', $composer['require-dev']);
        self::assertFileDoesNotExist($this->project.'/.ai/rules/laracanon-data.md');
        self::assertDirectoryDoesNotExist($this->project.'/.ai/skills');
        self::assertStringContainsString('.ai/rules/laracanon-fixture-rules-only.md', $this->read('.ai/rules/index.md'));
        self::assertFileDoesNotExist($this->project.'/.ai/rules/laracanon-architecture.md');
        self::assertFileDoesNotExist($this->project.'/.ai/rules/laracanon-models.md');
    }

    public function test_fixture_dependency_items_install_runtime_and_development_requirements_separately(): void
    {
        $report = $this->installer($this->fixtureCatalog())->install($this->project, ['fixture-runtime-package', 'fixture-dev-package']);

        self::assertTrue($report->successful(), implode("\n", array_merge($report->failures, $report->conflicts)));
        self::assertSame(['fixture-runtime-package' => 'installed', 'fixture-dev-package' => 'installed'], $report->items);
        self::assertCount(2, $this->commands);
        self::assertSame('example/runtime-tool:*@stable', $this->commands[0][2]);
        self::assertNotContains('--dev', $this->commands[0]);
        self::assertSame('example/dev-tool:*@stable', $this->commands[1][2]);
        self::assertContains('--dev', $this->commands[1]);
        $composer = json_decode($this->read('composer.json'), true);
        self::assertSame('*@stable', $composer['require']['example/runtime-tool']);
        self::assertArrayNotHasKey('example/runtime-tool', $composer['require-dev']);
        self::assertSame('*@stable', $composer['require-dev']['example/dev-tool']);
        self::assertArrayNotHasKey('example/dev-tool', $composer['require']);
        self::assertSame(['@php artisan existing:workflow'], $composer['scripts']['post-update-cmd']);
        self::assertCount(2, $report->rules);
        self::assertSame([], $report->skills);
        $index = $this->read('.ai/rules/index.md');
        self::assertStringContainsString('.ai/rules/laracanon-fixture-runtime-package.md', $index);
        self::assertStringContainsString('.ai/rules/laracanon-fixture-dev-package.md', $index);
        self::assertFileDoesNotExist($this->project.'/.ai/rules/laracanon-data.md');
    }

    private function fixtureCatalog(): ItemCatalog
    {
        return new ItemCatalog(dirname(__DIR__, 2).'/Fixtures/Items');
    }

    private function packageOnlyCatalog(): ItemCatalog
    {
        $this->write('package-only-items/fixture-package-only.md', <<<'MARKDOWN'
---
name: fixture-package-only
description: An isolated package-only integration test item.
paths:
  - 'app/**'
dependencies:
  runtime:
    - example/package-tool
---
MARKDOWN);

        return new ItemCatalog($this->project.'/package-only-items');
    }

    private function installer(?ItemCatalog $catalog = null): Installer
    {
        $runner = new class($this) implements ProcessRunner
        {
            public function __construct(private CatalogInstallationTest $test) {}

            public function run(array $command, string $cwd): ProcessResult
            {
                return $this->test->runComposer($command);
            }
        };
        $boost = new class($runner) extends BoostIntegration
        {
            public function refresh(string $projectPath, bool $dryRun = false, array $selectedPackages = []): array
            {
                if ($dryRun) {
                    return parent::refresh($projectPath, true, $selectedPackages);
                }

                return (new BoostProjectRefresher)->refresh($selectedPackages);
            }
        };

        return new Installer($catalog ?? $this->catalog(), new ComposerDependencies($runner), $boost);
    }

    public function runComposer(array $command): ProcessResult
    {
        $this->commands[] = $command;
        if ($command[1] === 'config') {
            self::assertSame(['composer', 'config', '--no-plugins', '--no-scripts', '--no-interaction'], array_slice($command, 0, 5));
            $composer = json_decode($this->read('composer.json'), true);
            if ($command[5] === 'allow-plugins') {
                self::assertCount(6, $command);

                return new ProcessResult(0, json_encode($composer['config']['allow-plugins'] ?? new \stdClass));
            }
            self::assertSame('allow-plugins.dealerdirect/phpcodesniffer-composer-installer', $command[5]);
            self::assertSame('true', $command[6]);
            $composer['config']['allow-plugins']['dealerdirect/phpcodesniffer-composer-installer'] = true;
            $this->writeJson('composer.json', $composer);

            return new ProcessResult(0, 'Mocked declared named plugin configuration.');
        }
        self::assertSame(['composer', 'require'], array_slice($command, 0, 2));
        $arguments = $this->packageArguments($command);
        self::assertNotEmpty($arguments);
        foreach ($arguments as $argument) {
            [$package, $constraint] = explode(':', $argument, 2);
            if ($package === 'nunomaduro/phpinsights') {
                self::assertArrayHasKey('dealerdirect/phpcodesniffer-composer-installer', json_decode($this->read('composer.json'), true)['config']['allow-plugins'] ?? [], 'The fresh PHP Insights install must resolve its plugin policy before Composer require.');
            }
            $version = match ($package) {
                'spatie/laravel-data' => '4.21.0',
                'nunomaduro/phpinsights' => '2.15.0',
                'phpstan/phpstan' => '2.2.16',
                'larastan/larastan' => 'v3.12.2',
                default => '1.2.3',
            };
            $this->simulateDependencyInstall($package, in_array('--dev', $command, true) ? 'development' : 'runtime', $constraint, $version);
        }

        return new ProcessResult(0, 'Mocked compatible stable dependency installation.');
    }

    private function packageArguments(array $command): array
    {
        return array_values(array_filter(array_slice($command, 2), static fn (string $argument): bool => ! str_starts_with($argument, '--')));
    }

    private function simulateDependencyInstall(string $package, string $type, string $constraint, string $version): void
    {
        $composer = json_decode($this->read('composer.json'), true);
        $composer[$type === 'development' ? 'require-dev' : 'require'][$package] = $constraint;
        $this->writeJson('composer.json', $composer);
        $installed = json_decode($this->read('vendor/composer/installed.json'), true);
        $installed['packages'][] = ['name' => $package, 'version' => $version];
        $this->writeJson('vendor/composer/installed.json', $installed);
        $lock = json_decode($this->read('composer.lock'), true);
        $lock[$type === 'development' ? 'packages-dev' : 'packages'][] = ['name' => $package, 'version' => $version];
        $this->writeJson('composer.lock', $lock);
    }

    private function write(string $path, string $contents): void
    {
        File::ensureDirectoryExists(dirname($this->project.'/'.$path));
        file_put_contents($this->project.'/'.$path, $contents);
    }

    private function writeJson(string $path, array $value): void
    {
        $this->write($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function read(string $path): string
    {
        return (string) file_get_contents($this->project.'/'.$path);
    }

    private function snapshot(): array
    {
        $files = [];
        foreach (File::allFiles($this->project, true) as $file) {
            $files[$file->getRelativePathname()] = hash_file('sha256', $file->getPathname());
        }
        ksort($files);

        return $files;
    }
}
