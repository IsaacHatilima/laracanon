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
use Laravel\Boost\Rules\RuleFrontmatter;
use Laravel\Roster\ProjectManager;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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

    public function test_complete_catalog_installs_distinct_rules_and_only_authored_workflows_with_native_index(): void
    {
        $catalog = $this->catalog();
        $items = $catalog->all();
        self::assertArrayHasKey('architecture', $items);
        self::assertArrayHasKey('actions', $items);
        self::assertArrayHasKey('data', $items);
        $report = $this->installer()->install($this->project);

        self::assertTrue($report->successful(), implode("\n", array_merge($report->failures, $report->conflicts)));
        self::assertCount(count($items), $report->items);
        $requires = array_values(array_filter($this->commands, static fn (array $command): bool => $command[1] === 'require'));
        self::assertCount(2, $requires, 'The complete catalog resolves new dependencies once per Composer requirement section.');
        self::assertSame(['spatie/laravel-data:*@stable'], $this->packageArguments($requires[0]));
        self::assertNotContains('--dev', $requires[0]);
        self::assertSame(['nunomaduro/phpinsights:*@stable', 'phpstan/phpstan:*@stable', 'larastan/larastan:*@stable'], $this->packageArguments($requires[1]));
        self::assertContains('--dev', $requires[1]);
        $index = $this->read('.ai/rules/index.md');

        foreach ($items as $item) {
            self::assertFalse($item->sample, 'The production catalog contains conventions rather than test demonstrations.');
            if ($item->rules !== null) {
                $rule = '.ai/rules/laracanon-'.$item->name.'.md';
                self::assertFileExists($this->project.'/'.$rule);
                $rendered = RuleFrontmatter::parse($this->read($rule));
                self::assertNotEmpty($rendered['paths']);
                self::assertStringContainsString('| '.implode(', ', $rendered['paths']).' | '.$rule.' |', $index);
            } else {
                self::assertFileDoesNotExist($this->project.'/.ai/rules/laracanon-'.$item->name.'.md');
            }

            if ($item->skill === null) {
                self::assertFileDoesNotExist($this->project.'/.ai/skills/laracanon-'.$item->name.'/SKILL.md');
            } else {
                foreach (['.ai/skills/', '.agents/skills/'] as $directory) {
                    $path = $directory.$item->skillName.'/SKILL.md';
                    self::assertFileExists($this->project.'/'.$path);
                    self::assertStringContainsString($item->skill, $this->read($path));
                }
            }

            foreach ($item->files as $path => $contents) {
                self::assertFileExists($this->project.'/'.$path);
                self::assertSame($contents, $this->read($path));
            }
        }

        self::assertStringContainsString('| **/* | .ai/rules/project-global.md |', $index);
        self::assertStringContainsString('@.ai/rules/index.md', $this->read('AGENTS.md'));
    }

    public function test_insights_installs_only_its_development_dependency_and_literal_configuration_with_native_boost(): void
    {
        $item = $this->catalog()->find('insights');
        $contents = $item->files['config/insights.php'];
        self::assertSame(5743, strlen($contents));
        self::assertSame('9b51c64f64e8c8349ad6b33bb330b270b3a77292130a1eb3ef3d8351dd597623', hash('sha256', $contents));
        $composer = json_decode($this->read('composer.json'), true);
        $composer['config'] = ['sort-packages' => true, 'allow-plugins' => ['pestphp/pest-plugin' => true]];
        $this->writeJson('composer.json', $composer);
        $report = $this->installer()->install($this->project, ['insights']);

        self::assertTrue($report->successful(), implode("\n", array_merge($report->failures, $report->conflicts)));
        self::assertCount(3, $this->commands);
        self::assertSame(['composer', 'config', '--no-plugins', '--no-scripts', '--no-interaction', 'allow-plugins'], $this->commands[0]);
        self::assertSame(['composer', 'config', '--no-plugins', '--no-scripts', '--no-interaction', 'allow-plugins.dealerdirect/phpcodesniffer-composer-installer', 'true'], $this->commands[1]);
        self::assertSame('nunomaduro/phpinsights:*@stable', $this->commands[2][2]);
        self::assertContains('--dev', $this->commands[2]);
        $composer = json_decode($this->read('composer.json'), true);
        self::assertSame('*@stable', $composer['require-dev']['nunomaduro/phpinsights']);
        self::assertArrayNotHasKey('nunomaduro/phpinsights', $composer['require']);
        self::assertSame(['php' => '^8.3', 'laravel/framework' => '^13.0'], $composer['require']);
        self::assertSame('^2.10', $composer['require-dev']['laravel/boost']);
        self::assertSame(['post-update-cmd' => ['@php artisan existing:workflow']], $composer['scripts']);
        self::assertSame(['sort-packages' => true, 'allow-plugins' => ['pestphp/pest-plugin' => true, 'dealerdirect/phpcodesniffer-composer-installer' => true]], $composer['config']);
        self::assertSame($contents, $this->read('config/insights.php'));
        self::assertSame(['config/insights.php (installed)'], $report->files);
        self::assertSame([], $report->rules);
        self::assertSame([], $report->skills);
        self::assertFileDoesNotExist($this->project.'/.ai/rules/laracanon-insights.md');
        self::assertFileDoesNotExist($this->project.'/.ai/skills/laracanon-insights/SKILL.md');
        self::assertStringContainsString('| **/* | .ai/rules/project-global.md |', $this->read('.ai/rules/index.md'));
        self::assertStringNotContainsString('laracanon-insights.md', $this->read('.ai/rules/index.md'));
        $state = json_decode($this->read('.ai/laracanon/state.json'), true);
        self::assertSame(['hash' => hash('sha256', $contents), 'owner' => 'insights'], $state['files']['config/insights.php']);
        $before = $this->snapshot();

        $repeat = $this->installer()->install($this->project, ['insights']);

        self::assertTrue($repeat->successful(), implode("\n", array_merge($repeat->failures, $repeat->conflicts)));
        self::assertCount(3, $this->commands, 'Repeated installation does not read or rewrite the plugin policy.');
        self::assertSame($before, $this->snapshot());
        self::assertSame(['config/insights.php (unchanged)'], $repeat->files);
    }

    public function test_insights_preserves_an_existing_project_configuration_and_reports_a_conflict(): void
    {
        $this->simulateDependencyInstall('nunomaduro/phpinsights', 'development', '^2.14', '2.15.0');
        $manifest = $this->read('composer.json');
        $lock = $this->read('composer.lock');
        $this->write('config/insights.php', "<?php\nreturn ['preset' => 'project'];\n");
        $report = $this->installer()->install($this->project, ['insights']);

        self::assertFalse($report->successful());
        self::assertSame('conflict', $report->items['insights']);
        self::assertCount(1, $report->conflicts);
        self::assertStringContainsString('config/insights.php: locally modified or unowned file preserved', $report->conflicts[0]);
        self::assertSame("<?php\nreturn ['preset' => 'project'];\n", $this->read('config/insights.php'));
        self::assertSame([], $this->commands);
        self::assertSame($manifest, $this->read('composer.json'));
        self::assertSame($lock, $this->read('composer.lock'));
        $state = json_decode($this->read('.ai/laracanon/state.json'), true);
        self::assertSame([], $state['items']['insights']['files']);
        self::assertArrayNotHasKey('config/insights.php', $state['files']);
    }

    public function test_insights_dry_run_plans_configuration_without_launching_processes_or_writing_files(): void
    {
        $before = $this->snapshot();
        $report = $this->installer()->install($this->project, ['insights'], true);

        self::assertTrue($report->successful(), implode("\n", array_merge($report->failures, $report->conflicts)));
        self::assertSame(['insights' => 'planned'], $report->items);
        self::assertStringContainsString('nunomaduro/phpinsights to require-dev', implode("\n", $report->dependencies));
        self::assertStringContainsString('dealerdirect/phpcodesniffer-composer-installer', implode("\n", array_merge($report->dependencies, $report->notes)));
        self::assertSame(['config/insights.php (installed)'], $report->files);
        self::assertSame([], $this->commands);
        self::assertSame($before, $this->snapshot());
    }

    public function test_fresh_insights_and_phpstan_install_together_after_the_named_plugin_is_configured(): void
    {
        $report = $this->installer()->install($this->project, ['insights', 'phpstan']);

        self::assertTrue($report->successful(), implode("\n", array_merge($report->failures, $report->conflicts)));
        self::assertSame(['insights' => 'installed', 'phpstan' => 'installed'], $report->items);
        self::assertCount(3, $this->commands);
        self::assertSame(['config', 'config', 'require'], array_column($this->commands, 1));
        self::assertSame(['nunomaduro/phpinsights:*@stable', 'phpstan/phpstan:*@stable', 'larastan/larastan:*@stable'], $this->packageArguments($this->commands[2]));
        self::assertContains('--dev', $this->commands[2]);
        self::assertSame(['config/insights.php (installed)', 'phpstan.neon (installed)'], $report->files);
        self::assertFileExists($this->project.'/.ai/rules/index.md');
        self::assertSame(['post-update-cmd' => ['@php artisan existing:workflow']], json_decode($this->read('composer.json'), true)['scripts']);
    }

    public function test_phpstan_installs_development_dependencies_and_the_exact_root_configuration_with_native_boost(): void
    {
        $expected = <<<'NEON'
includes:
    - vendor/larastan/larastan/extension.neon
    - vendor/nesbot/carbon/extension.neon

parameters:
    level: 10
    paths:
        - app
    excludePaths:
        - tests/*
        - database/*
        - config/*
        - vendor/*
        - app/Providers/*
NEON;
        $this->simulateDependencyInstall('nesbot/carbon', 'runtime', '^3.10', '3.14.2');
        $existingRequirements = json_decode($this->read('composer.json'), true)['require'];
        $report = $this->installer()->install($this->project, ['phpstan']);

        self::assertTrue($report->successful(), implode("\n", array_merge($report->failures, $report->conflicts)));
        self::assertCount(1, $this->commands);
        self::assertSame(['phpstan/phpstan:*@stable', 'larastan/larastan:*@stable'], $this->packageArguments($this->commands[0]));
        self::assertContains('--dev', $this->commands[0]);
        $composer = json_decode($this->read('composer.json'), true);
        self::assertSame($existingRequirements, $composer['require']);
        self::assertSame(['laravel/boost' => '^2.10', 'phpstan/phpstan' => '*@stable', 'larastan/larastan' => '*@stable'], $composer['require-dev']);
        self::assertSame(['post-update-cmd' => ['@php artisan existing:workflow']], $composer['scripts']);
        self::assertSame($expected."\n", $this->read('phpstan.neon'));
        self::assertFileDoesNotExist($this->project.'/phpstan.neon.dist');
        self::assertFileDoesNotExist($this->project.'/phpstan.dist.neon');
        self::assertSame(['phpstan.neon (installed)'], $report->files);
        self::assertSame([], $report->rules);
        self::assertSame([], $report->skills);
        self::assertFileDoesNotExist($this->project.'/.ai/rules/laracanon-phpstan.md');
        self::assertFileDoesNotExist($this->project.'/.ai/skills/laracanon-phpstan/SKILL.md');
        self::assertStringContainsString('| **/* | .ai/rules/project-global.md |', $this->read('.ai/rules/index.md'));
        self::assertStringNotContainsString('laracanon-phpstan.md', $this->read('.ai/rules/index.md'));
        $state = json_decode($this->read('.ai/laracanon/state.json'), true);
        self::assertSame(['hash' => hash('sha256', $expected."\n"), 'owner' => 'phpstan'], $state['files']['phpstan.neon']);
        $before = $this->snapshot();

        $repeat = $this->installer()->install($this->project, ['phpstan']);

        self::assertTrue($repeat->successful(), implode("\n", array_merge($repeat->failures, $repeat->conflicts)));
        self::assertCount(1, $this->commands, 'Repeated installation does not resolve the installed development dependencies again.');
        self::assertSame($before, $this->snapshot());
        self::assertSame(['phpstan.neon (unchanged)'], $repeat->files);
    }

    public function test_phpstan_preserves_existing_dependency_versions_and_reuses_unowned_configuration(): void
    {
        $this->simulateDependencyInstall('phpstan/phpstan', 'development', '^2.1', 'v2.2.16');
        $this->simulateDependencyInstall('larastan/larastan', 'development', '^3.10', 'v3.12.2');
        $manifest = $this->read('composer.json');
        $lock = $this->read('composer.lock');
        $this->write('phpstan.neon.dist', "parameters:\n    level: 8\n");
        $report = $this->installer()->install($this->project, ['phpstan']);

        self::assertTrue($report->successful(), implode("\n", array_merge($report->failures, $report->conflicts)));
        self::assertSame('installed', $report->items['phpstan']);
        self::assertSame([], $report->conflicts);
        self::assertSame("parameters:\n    level: 10\n", $this->read('phpstan.neon.dist'));
        self::assertFileDoesNotExist($this->project.'/phpstan.neon');
        self::assertSame([], $this->commands);
        self::assertSame($manifest, $this->read('composer.json'));
        self::assertSame($lock, $this->read('composer.lock'));
        $state = json_decode($this->read('.ai/laracanon/state.json'), true);
        self::assertSame(['phpstan.neon.dist'], $state['items']['phpstan']['files']);
        self::assertSame('phpstan', $state['files']['phpstan.neon.dist']['owner']);
    }

    public function test_phpstan_dry_run_plans_root_configuration_and_development_dependencies_without_mutations(): void
    {
        $before = $this->snapshot();
        $report = $this->installer()->install($this->project, ['phpstan'], true);

        self::assertTrue($report->successful(), implode("\n", array_merge($report->failures, $report->conflicts)));
        self::assertSame(['phpstan' => 'planned'], $report->items);
        self::assertCount(2, $report->dependencies);
        self::assertStringContainsString('phpstan/phpstan to require-dev', $report->dependencies[0]);
        self::assertStringContainsString('larastan/larastan to require-dev', $report->dependencies[1]);
        self::assertStringContainsString('phpstan/phpstan minimum 2.0.0 will be verified after dependency installation', implode("\n", $report->notes));
        self::assertSame(['phpstan.neon (installed)'], $report->files);
        self::assertSame([], $this->commands);
        self::assertSame($before, $this->snapshot());
    }

    public function test_phpstan_one_is_rejected_before_composer_boost_or_configuration_mutations(): void
    {
        $this->simulateDependencyInstall('phpstan/phpstan', 'development', '^1.12', '1.12.33');
        $this->simulateDependencyInstall('larastan/larastan', 'development', '^2.11', '2.11.2');
        $this->write('phpstan.neon.dist', "parameters:\n    level: 6\n");
        $before = $this->snapshot();

        foreach ([false, true] as $dryRun) {
            $report = $this->installer()->install($this->project, ['phpstan'], $dryRun);

            self::assertFalse($report->successful());
            self::assertSame(['phpstan' => 'incompatible dependencies'], $report->items);
            self::assertStringContainsString('phpstan/phpstan requires minimum 2.0.0, but 1.12.33 is installed', $report->failures[0]);
            self::assertSame([], $report->files);
            self::assertSame([], $report->resources);
            self::assertSame($before, $this->snapshot());
        }
        self::assertSame([], $this->commands);
    }

    #[DataProvider('existingPhpstanConfigurations')]
    public function test_phpstan_changes_only_the_level_in_an_existing_configuration(string $path): void
    {
        $contents = $this->existingPhpstanConfiguration();
        $expected = str_replace('level: 7 # Project analysis level.', 'level: 10 # Project analysis level.', $contents);
        $this->write($path, $contents);

        $report = $this->installer()->install($this->project, ['phpstan']);

        self::assertTrue($report->successful(), implode("\n", array_merge($report->failures, $report->conflicts)));
        self::assertSame(['phpstan' => 'installed'], $report->items);
        self::assertSame($expected, $this->read($path), 'Only the level scalar changes; comments, includes, paths, exclusions, and formatting remain byte-for-byte.');
        self::assertSame([$path.' (updated)'], $report->files);
        foreach (['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'] as $candidate) {
            if ($candidate !== $path) {
                self::assertFileDoesNotExist($this->project.'/'.$candidate, 'An existing PHPStan config must not produce a duplicate.');
            }
        }
        $before = $this->snapshot();
        $commands = $this->commands;

        $repeat = $this->installer()->install($this->project, ['phpstan']);

        self::assertTrue($repeat->successful(), implode("\n", array_merge($repeat->failures, $repeat->conflicts)));
        self::assertSame([$path.' (unchanged)'], $repeat->files);
        self::assertSame($commands, $this->commands);
        self::assertSame($before, $this->snapshot());
    }

    /** @return array<string, array{string}> */
    public static function existingPhpstanConfigurations(): array
    {
        return [
            'local configuration' => ['phpstan.neon'],
            'distribution configuration' => ['phpstan.neon.dist'],
            'alternate distribution configuration' => ['phpstan.dist.neon'],
        ];
    }

    public function test_phpstan_updates_the_active_config_and_preserves_unowned_secondary_configs(): void
    {
        $contents = $this->existingPhpstanConfiguration();
        $distribution = "# Shared fallback\nparameters:\n    level: 3\n    paths: [app/Shared]\n";
        $alternate = "parameters:\n    level: 4\n    paths: [app/Legacy]\n";
        $this->write('phpstan.neon', $contents);
        $this->write('phpstan.neon.dist', $distribution);
        $this->write('phpstan.dist.neon', $alternate);

        $report = $this->installer()->install($this->project, ['phpstan']);

        self::assertTrue($report->successful(), implode("\n", array_merge($report->failures, $report->conflicts)));
        self::assertSame(str_replace('level: 7 # Project analysis level.', 'level: 10 # Project analysis level.', $contents), $this->read('phpstan.neon'));
        self::assertSame($distribution, $this->read('phpstan.neon.dist'));
        self::assertSame($alternate, $this->read('phpstan.dist.neon'));
        self::assertSame(['phpstan.neon (updated)'], $report->files);
        $state = json_decode($this->read('.ai/laracanon/state.json'), true);
        self::assertSame(['phpstan.neon'], $state['items']['phpstan']['files']);
        self::assertArrayNotHasKey('phpstan.neon.dist', $state['files']);
        self::assertArrayNotHasKey('phpstan.dist.neon', $state['files']);
    }

    public function test_phpstan_prefers_the_standard_distribution_config_to_the_alternate_filename(): void
    {
        $distribution = "parameters:\n    level: 5\n";
        $alternate = "parameters:\n    level: 8\n";
        $this->write('phpstan.neon.dist', $distribution);
        $this->write('phpstan.dist.neon', $alternate);

        $report = $this->installer()->install($this->project, ['phpstan']);

        self::assertTrue($report->successful(), implode("\n", array_merge($report->failures, $report->conflicts)));
        self::assertSame("parameters:\n    level: 10\n", $this->read('phpstan.neon.dist'));
        self::assertSame($alternate, $this->read('phpstan.dist.neon'));
        self::assertFileDoesNotExist($this->project.'/phpstan.neon');
    }

    public function test_phpstan_preserves_unrelated_edits_in_an_adopted_configuration_on_repeat(): void
    {
        $this->write('phpstan.neon', $this->existingPhpstanConfiguration());
        $first = $this->installer()->install($this->project, ['phpstan']);
        self::assertTrue($first->successful(), implode("\n", array_merge($first->failures, $first->conflicts)));
        $edited = str_replace('app/Domain', 'app/ChangedDomain', $this->read('phpstan.neon'))."# A developer added this comment after installation.\n";
        $this->write('phpstan.neon', $edited);
        $before = $this->snapshot();

        $repeat = $this->installer()->install($this->project, ['phpstan']);

        self::assertTrue($repeat->successful(), implode("\n", array_merge($repeat->failures, $repeat->conflicts)));
        self::assertSame(['phpstan.neon (unchanged)'], $repeat->files);
        self::assertSame($edited, $this->read('phpstan.neon'));
        self::assertSame($before, $this->snapshot(), 'Ownership of parameters.level must not claim the rest of an existing configuration.');
    }

    public function test_phpstan_reports_an_owned_level_edit_and_preserves_the_developer_value(): void
    {
        $this->write('phpstan.neon', $this->existingPhpstanConfiguration());
        $first = $this->installer()->install($this->project, ['phpstan']);
        self::assertTrue($first->successful(), implode("\n", array_merge($first->failures, $first->conflicts)));
        $edited = str_replace('level: 10 # Project analysis level.', 'level: 8 # Project analysis level.', $this->read('phpstan.neon'));
        $this->write('phpstan.neon', $edited);
        $composer = $this->read('composer.json');
        $lock = $this->read('composer.lock');
        $commands = $this->commands;

        foreach ([false, true] as $dryRun) {
            $repeat = $this->installer()->install($this->project, ['phpstan'], $dryRun);

            self::assertFalse($repeat->successful());
            self::assertSame('conflict', $repeat->items['phpstan']);
            self::assertCount(1, $repeat->conflicts);
            self::assertStringContainsString('phpstan.neon', $repeat->conflicts[0]);
            self::assertSame($edited, $this->read('phpstan.neon'));
            self::assertSame($composer, $this->read('composer.json'));
            self::assertSame($lock, $this->read('composer.lock'));
            self::assertSame($commands, $this->commands);
        }
        self::assertFileDoesNotExist($this->project.'/phpstan.neon.dist');
    }

    public function test_phpstan_retains_full_file_ownership_when_it_created_the_configuration(): void
    {
        $first = $this->installer()->install($this->project, ['phpstan']);
        self::assertTrue($first->successful(), implode("\n", array_merge($first->failures, $first->conflicts)));
        $edited = $this->read('phpstan.neon')."# A local edit to a file created by Laracanon.\n";
        $this->write('phpstan.neon', $edited);

        $repeat = $this->installer()->install($this->project, ['phpstan']);

        self::assertFalse($repeat->successful());
        self::assertSame('conflict', $repeat->items['phpstan']);
        self::assertCount(1, $repeat->conflicts);
        self::assertSame($edited, $this->read('phpstan.neon'));
    }

    public function test_phpstan_dry_run_previews_only_the_existing_level_without_mutations(): void
    {
        $this->write('phpstan.neon', $this->existingPhpstanConfiguration());
        $before = $this->snapshot();

        $report = $this->installer()->install($this->project, ['phpstan'], true);

        self::assertTrue($report->successful(), implode("\n", array_merge($report->failures, $report->conflicts)));
        self::assertSame(['phpstan' => 'planned'], $report->items);
        self::assertSame(['phpstan.neon (updated)'], $report->files);
        self::assertSame([], $this->commands);
        self::assertSame($before, $this->snapshot());
        self::assertFileDoesNotExist($this->project.'/phpstan.neon.dist');
    }

    public function test_malformed_phpstan_config_is_preserved_while_other_selected_items_complete(): void
    {
        $malformed = "parameters:\n    level: [\n";
        $this->write('phpstan.neon', $malformed);

        $report = $this->installer()->install($this->project, ['phpstan', 'actions']);

        self::assertFalse($report->successful());
        self::assertSame('partial failure', $report->items['phpstan']);
        self::assertSame('installed', $report->items['actions']);
        self::assertNotEmpty($report->failures);
        self::assertSame($malformed, $this->read('phpstan.neon'));
        self::assertFileDoesNotExist($this->project.'/phpstan.neon.dist');
        self::assertFileExists($this->project.'/.ai/rules/laracanon-actions.md');
        self::assertFileExists($this->project.'/.ai/skills/laracanon-actions/SKILL.md');
        self::assertStringContainsString('laracanon-actions.md', $this->read('.ai/rules/index.md'));
        $state = json_decode($this->read('.ai/laracanon/state.json'), true);
        self::assertSame([], $state['items']['phpstan']['files']);
        self::assertArrayNotHasKey('phpstan.neon', $state['files']);
    }

    public function test_phpstan_upgrade_reuses_a_legacy_managed_distribution_file_without_a_duplicate(): void
    {
        $legacy = array_values($this->catalog()->find('phpstan')->files)[0];
        $this->writeLegacyPhpstanState($legacy);

        $report = $this->installer()->install($this->project, ['phpstan']);

        self::assertTrue($report->successful(), implode("\n", array_merge($report->failures, $report->conflicts)));
        self::assertSame($legacy, $this->read('phpstan.neon.dist'));
        self::assertSame(['phpstan.neon.dist (unchanged)'], $report->files);
        self::assertFileDoesNotExist($this->project.'/phpstan.neon');
        $before = $this->snapshot();

        $repeat = $this->installer()->install($this->project, ['phpstan']);

        self::assertTrue($repeat->successful(), implode("\n", array_merge($repeat->failures, $repeat->conflicts)));
        self::assertSame($before, $this->snapshot());
    }

    public function test_phpstan_upgrade_removes_an_unchanged_old_managed_dist_when_a_local_config_is_active(): void
    {
        $legacy = array_values($this->catalog()->find('phpstan')->files)[0];
        $this->writeLegacyPhpstanState($legacy);
        $this->write('vendor/larastan/larastan/extension.neon', "# Mock installed extension with no includes.\n");
        $this->write('project-analysis.neon', "# Mock existing project analysis configuration.\n");
        $local = $this->existingPhpstanConfiguration();
        $this->write('phpstan.neon', $local);
        $before = $this->snapshot();
        $preview = $this->installer()->install($this->project, ['phpstan'], true);
        self::assertTrue($preview->successful(), implode("\n", array_merge($preview->failures, $preview->conflicts)));
        self::assertSame($before, $this->snapshot());

        $report = $this->installer()->install($this->project, ['phpstan']);

        self::assertTrue($report->successful(), implode("\n", array_merge($report->failures, $report->conflicts)));
        self::assertSame(str_replace('level: 7 # Project analysis level.', 'level: 10 # Project analysis level.', $local), $this->read('phpstan.neon'));
        self::assertFileDoesNotExist($this->project.'/phpstan.neon.dist');
        self::assertStringContainsString('phpstan.neon.dist: removed retired managed file.', implode("\n", $report->notes));
        $state = json_decode($this->read('.ai/laracanon/state.json'), true);
        self::assertSame(['phpstan.neon'], $state['items']['phpstan']['files']);
        self::assertArrayNotHasKey('phpstan.neon.dist', $state['files']);
    }

    public function test_phpstan_upgrade_preserves_and_reports_an_edited_old_managed_distribution_file(): void
    {
        $legacy = array_values($this->catalog()->find('phpstan')->files)[0];
        $this->writeLegacyPhpstanState($legacy);
        $this->write('vendor/larastan/larastan/extension.neon', "# Mock installed extension with no includes.\n");
        $this->write('project-analysis.neon', "# Mock existing project analysis configuration.\n");
        $edited = $legacy."# Preserve this old managed configuration edit.\n";
        $this->write('phpstan.neon.dist', $edited);
        $local = $this->existingPhpstanConfiguration();
        $this->write('phpstan.neon', $local);

        $report = $this->installer()->install($this->project, ['phpstan']);

        self::assertFalse($report->successful());
        self::assertSame('conflict', $report->items['phpstan']);
        self::assertCount(1, $report->conflicts);
        self::assertStringContainsString('phpstan.neon.dist', $report->conflicts[0]);
        self::assertSame($edited, $this->read('phpstan.neon.dist'));
        self::assertSame(str_replace('level: 7 # Project analysis level.', 'level: 10 # Project analysis level.', $local), $this->read('phpstan.neon'));
        $state = json_decode($this->read('.ai/laracanon/state.json'), true);
        self::assertContains('phpstan.neon.dist', $state['items']['phpstan']['files']);
        self::assertContains('phpstan.neon', $state['items']['phpstan']['files']);
        self::assertSame(hash('sha256', $legacy), $state['files']['phpstan.neon.dist']['hash']);
    }

    public function test_phpstan_upgrade_preserves_a_legacy_managed_dist_included_by_the_active_config(): void
    {
        $legacy = array_values($this->catalog()->find('phpstan')->files)[0];
        $this->writeLegacyPhpstanState($legacy);
        $local = "includes:\n    - phpstan.neon.dist\n\nparameters:\n    level: 7\n    paths: [app/Domain]\n";
        $this->write('phpstan.neon', $local);

        $report = $this->installer()->install($this->project, ['phpstan']);

        self::assertTrue($report->successful(), implode("\n", array_merge($report->failures, $report->conflicts)));
        self::assertSame(str_replace('level: 7', 'level: 10', $local), $this->read('phpstan.neon'));
        self::assertSame($legacy, $this->read('phpstan.neon.dist'), 'A referenced fallback config must remain available so the existing includes still work.');
        $state = json_decode($this->read('.ai/laracanon/state.json'), true);
        self::assertContains('phpstan.neon.dist', $state['items']['phpstan']['files']);
        self::assertContains('phpstan.neon', $state['items']['phpstan']['files']);
        $before = $this->snapshot();

        $repeat = $this->installer()->install($this->project, ['phpstan']);

        self::assertTrue($repeat->successful(), implode("\n", array_merge($repeat->failures, $repeat->conflicts)));
        self::assertSame($before, $this->snapshot());
    }

    public function test_data_installs_runtime_dependency_and_project_workflow_without_overriding_package_skill(): void
    {
        $this->write('boost.json', '{"agents":["codex"],"guidelines":true,"mcp":false,"packages":[],"skills":[],"custom":{"keep":true}}');
        // Simulate a dependency shipping its own guidance and skill in a future
        // release; these fixtures are not claims about today's Spatie package.
        $this->write('vendor/spatie/laravel-data/resources/boost/guidelines/core.md', "# Package usage\n\nGeneral package advice remains available.\n");
        $this->write('vendor/spatie/laravel-data/resources/boost/skills/laravel-data-package/SKILL.md', "---\nname: laravel-data-package\ndescription: Follow the package-authored workflow.\n---\n\n# Package workflow\n\nUse package workflow instructions.\n");
        $report = $this->installer()->install($this->project, ['data']);

        self::assertTrue($report->successful(), implode("\n", array_merge($report->failures, $report->conflicts)));
        $composer = json_decode($this->read('composer.json'), true);
        self::assertSame('*@stable', $composer['require']['spatie/laravel-data']);
        self::assertArrayNotHasKey('spatie/laravel-data', $composer['require-dev']);
        self::assertSame('^13.0', $composer['require']['laravel/framework']);
        self::assertSame('^2.10', $composer['require-dev']['laravel/boost']);
        self::assertSame(['@php artisan existing:workflow'], $composer['scripts']['post-update-cmd']);
        self::assertFileExists($this->project.'/.ai/rules/laracanon-data.md');
        self::assertFileExists($this->project.'/.ai/skills/laracanon-data/SKILL.md');
        self::assertStringContainsString('Use package workflow instructions.', $this->read('.agents/skills/laravel-data-package/SKILL.md'));
        self::assertFileDoesNotExist($this->project.'/.ai/skills/laravel-data-package/SKILL.md');
        self::assertStringContainsString('General package advice remains available.', $this->read('AGENTS.md'));
        $boost = json_decode($this->read('boost.json'), true);
        self::assertContains('spatie/laravel-data', $boost['packages']);
        self::assertSame(['keep' => true], $boost['custom']);
        $rule = $this->read('.ai/rules/laracanon-data.md');
        self::assertStringContainsString('fromRequest()', $rule);
        self::assertStringContainsString('fromModel()', $rule);
        self::assertStringContainsString('readonly', $rule);
        self::assertStringContainsString("'name' => \$data->name", $rule);
        self::assertStringContainsString("'phone_number' => \$data->phoneNumber", $rule);
        self::assertStringContainsString('Data and Resources never query, write, or lazy-load.', $rule);
        self::assertStringNotContainsString('## Examples', $rule);

        $skill = $this->read('.ai/skills/laracanon-data/SKILL.md');
        $agentSkill = $this->read('.agents/skills/laracanon-data/SKILL.md');
        foreach (['CreateUserRequest', 'CreateUserData', 'CreateUser', 'CreateUserController'] as $exampleClass) {
            $classDefinition = '/\bfinal class '.preg_quote($exampleClass, '/').'\b/';
            self::assertDoesNotMatchRegularExpression($classDefinition, $rule, 'Implementation examples belong in the authored skill.');
            self::assertMatchesRegularExpression($classDefinition, $skill);
            self::assertMatchesRegularExpression($classDefinition, $agentSkill);
        }
        self::assertStringContainsString('canon:install data --dry-run', $skill);
        self::assertStringContainsString("'phone_number' => \$data->phoneNumber", $skill);
        self::assertStringNotContainsString('canon:install data --dry-run', $rule);
        self::assertFileDoesNotExist($this->project.'/.ai/rules/laracanon-actions.md');
    }

    public function test_data_repeat_install_preserves_existing_dependency_constraint_and_version(): void
    {
        $this->simulateComposerInstall('^4.15', '4.21.0');
        $manifest = $this->read('composer.json');
        $lock = $this->read('composer.lock');
        $first = $this->installer()->install($this->project, ['data']);
        $rule = $this->read('.ai/rules/laracanon-data.md');
        $skill = $this->read('.ai/skills/laracanon-data/SKILL.md');
        $second = $this->installer()->install($this->project, ['data']);

        self::assertTrue($first->successful(), implode("\n", $first->failures));
        self::assertTrue($second->successful(), implode("\n", $second->failures));
        self::assertSame([], $this->commands);
        self::assertSame($manifest, $this->read('composer.json'));
        self::assertSame($lock, $this->read('composer.lock'));
        self::assertSame($rule, $this->read('.ai/rules/laracanon-data.md'));
        self::assertSame($skill, $this->read('.ai/skills/laracanon-data/SKILL.md'));
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

    public function test_actions_routes_implementation_to_its_authored_skill_without_installing_data(): void
    {
        $composer = $this->read('composer.json');
        $report = $this->installer()->install($this->project, ['actions']);

        self::assertTrue($report->successful(), implode("\n", array_merge($report->failures, $report->conflicts)));
        self::assertSame(['actions' => 'installed'], $report->items);
        self::assertSame([], $this->commands);
        self::assertSame($composer, $this->read('composer.json'));
        self::assertCount(1, $report->rules);
        self::assertCount(1, $report->skills);
        $rule = $this->read('.ai/rules/laracanon-actions.md');
        $skill = $this->read('.ai/skills/laracanon-actions/SKILL.md');
        $agentSkill = $this->read('.agents/skills/laracanon-actions/SKILL.md');
        $actionDefinition = '/\bfinal class CreateUser\b/';

        self::assertDoesNotMatchRegularExpression($actionDefinition, $rule);
        self::assertMatchesRegularExpression($actionDefinition, $skill);
        self::assertMatchesRegularExpression($actionDefinition, $agentSkill);
        self::assertMatchesRegularExpression('/^\d+\.\s/m', $skill, 'An authored implementation workflow accompanies the action example.');
        self::assertStringContainsString('| app/Actions/** | .ai/rules/laracanon-actions.md |', $this->read('.ai/rules/index.md'));
        self::assertFileDoesNotExist($this->project.'/.ai/rules/laracanon-data.md');
        self::assertFileDoesNotExist($this->project.'/.ai/skills/laracanon-data/SKILL.md');
    }

    public function test_controllers_routes_web_and_api_examples_to_its_skill_without_dependencies(): void
    {
        $composer = $this->read('composer.json');
        $report = $this->installer()->install($this->project, ['controllers']);

        self::assertTrue($report->successful(), implode("\n", array_merge($report->failures, $report->conflicts)));
        self::assertSame(['controllers' => 'installed'], $report->items);
        self::assertSame([], $this->commands);
        self::assertSame($composer, $this->read('composer.json'));
        self::assertCount(1, $report->rules);
        self::assertCount(1, $report->skills);
        $rule = $this->read('.ai/rules/laracanon-controllers.md');
        $controllerDefinition = '/\bfinal class CreateUserController\b/';
        self::assertDoesNotMatchRegularExpression($controllerDefinition, $rule);

        foreach (['.ai/skills/laracanon-controllers/SKILL.md', '.agents/skills/laracanon-controllers/SKILL.md'] as $path) {
            $skill = $this->read($path);
            self::assertSame(2, preg_match_all($controllerDefinition, $skill), 'Both transport examples reach each installed skill copy.');
            self::assertMatchesRegularExpression('/namespace\s+App\\\\Http\\\\Controllers\\\\Users\s*;/', $skill);
            self::assertMatchesRegularExpression('/namespace\s+App\\\\Http\\\\Controllers\\\\(?:Api|API)\\\\[^;]+;/', $skill);
            self::assertMatchesRegularExpression('/^\d+\.\s/m', $skill);
        }

        self::assertStringContainsString('| app/Http/Controllers/** | .ai/rules/laracanon-controllers.md |', $this->read('.ai/rules/index.md'));
        self::assertFileDoesNotExist($this->project.'/.ai/rules/laracanon-actions.md');
        self::assertFileDoesNotExist($this->project.'/.ai/rules/laracanon-data.md');
        self::assertFileDoesNotExist($this->project.'/.ai/skills/laracanon-data/SKILL.md');
    }

    public function test_controllers_and_api_responses_install_separate_authored_skills_without_dependencies(): void
    {
        $composer = $this->read('composer.json');
        $lock = $this->read('composer.lock');
        $selected = ['controllers', 'api-responses'];
        $report = $this->installer()->install($this->project, $selected);

        self::assertTrue($report->successful(), implode("\n", array_merge($report->failures, $report->conflicts)));
        self::assertSame(array_fill_keys($selected, 'installed'), $report->items);
        self::assertSame([], $this->commands);
        self::assertSame($composer, $this->read('composer.json'));
        self::assertSame($lock, $this->read('composer.lock'));
        self::assertCount(2, $report->rules);
        self::assertCount(2, $report->skills);
        $index = $this->read('.ai/rules/index.md');
        $helperDefinition = '/\\b(?:final\\s+)?class ApiResponse\\b/';

        foreach ($selected as $name) {
            $path = '.ai/rules/laracanon-'.$name.'.md';
            $rule = $this->read($path);
            $rendered = RuleFrontmatter::parse($rule);
            self::assertStringContainsString('| '.implode(', ', $rendered['paths']).' | '.$path.' |', $index);
            self::assertDoesNotMatchRegularExpression($helperDefinition, $rule, 'The response helper implementation belongs in an authored skill.');
            self::assertDoesNotMatchRegularExpression('/\\bfinal class CreateUserController\\b/', $rule);
            $canonicalSkill = $this->read('.ai/skills/laracanon-'.$name.'/SKILL.md');
            $agentSkill = $this->read('.agents/skills/laracanon-'.$name.'/SKILL.md');
            self::assertMatchesRegularExpression('/^\\d+\\.\\s/m', $canonicalSkill);
            self::assertStringContainsString($this->catalog()->all()[$name]->skill, $canonicalSkill);
            self::assertStringContainsString($this->catalog()->all()[$name]->skill, $agentSkill);
        }

        self::assertMatchesRegularExpression($helperDefinition, $this->read('.ai/skills/laracanon-api-responses/SKILL.md'));
        self::assertMatchesRegularExpression($helperDefinition, $this->read('.agents/skills/laracanon-api-responses/SKILL.md'));
        self::assertFileDoesNotExist($this->project.'/.ai/rules/laracanon-data.md');
        self::assertFileDoesNotExist($this->project.'/.ai/skills/laracanon-data/SKILL.md');
    }

    private function catalog(): ItemCatalog
    {
        return new ItemCatalog(dirname(__DIR__, 3).'/resources/items');
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

    /** @return list<string> */
    private function packageArguments(array $command): array
    {
        return array_values(array_filter(array_slice($command, 2), static fn (string $argument): bool => ! str_starts_with($argument, '--')));
    }

    private function simulateComposerInstall(string $constraint, string $version): void
    {
        $this->simulateDependencyInstall('spatie/laravel-data', 'runtime', $constraint, $version);
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

    private function existingPhpstanConfiguration(): string
    {
        return <<<'NEON'
# Project configuration: preserve this comment and its spacing.
includes:
    - vendor/larastan/larastan/extension.neon
    - project-analysis.neon

parameters:
    # A comment mentioning level: 2 is not the setting.
    level: 7 # Project analysis level.
    paths:
        - app/Domain
        - app/Services
    excludePaths:
        analyse:
            - app/Legacy/*
    tmpDir: storage/phpstan-cache
    reportUnmatchedIgnoredErrors: false

NEON;
    }

    private function writeLegacyPhpstanState(string $contents): void
    {
        $this->write('phpstan.neon.dist', $contents);
        $this->writeJson('.ai/laracanon/state.json', [
            'schema' => 1,
            'files' => [
                'phpstan.neon.dist' => ['hash' => hash('sha256', $contents), 'owner' => 'phpstan'],
            ],
            'items' => [
                'phpstan' => [
                    'source_hash' => hash('sha256', 'Laracanon v0.1.0 phpstan item'),
                    'files' => ['phpstan.neon.dist'],
                    'status' => 'installed',
                ],
            ],
        ]);
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

    /** @return array<string, string> */
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
