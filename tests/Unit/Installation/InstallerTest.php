<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Tests\Unit\Installation;

use Closure;
use Isaachatilima\Laracanon\Boost\BoostIntegration;
use Isaachatilima\Laracanon\Dependencies\ComposerDependencies;
use Isaachatilima\Laracanon\Installation\Installer;
use Isaachatilima\Laracanon\Items\ItemCatalog;
use Isaachatilima\Laracanon\Support\ProcessResult;
use Isaachatilima\Laracanon\Support\ProcessRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InstallerTest extends TestCase
{
    private string $root;

    private string $items;

    private array $commands = [];

    private array $boostCalls = [];

    private array $packageSkills = [];

    private array $boostFailures = [];

    private array $boostConflicts = [];

    private ?Closure $process = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/laracanon-install-'.bin2hex(random_bytes(8));
        $this->items = $this->root.'/item-sources';
        mkdir($this->items, 0777, true);
        mkdir($this->root.'/bootstrap');
        mkdir($this->root.'/vendor/composer', 0777, true);
        file_put_contents($this->root.'/artisan', '<?php');
        file_put_contents($this->root.'/bootstrap/app.php', '<?php');
        $this->json('composer.json', ['require' => ['laravel/framework' => '^13.0', 'php' => '^8.3'], 'require-dev' => ['laravel/boost' => '^2.10'], 'scripts' => ['post-update-cmd' => ['@php artisan custom:command']]]);
        $packages = [['name' => 'laravel/framework', 'version' => 'v13.34.0'], ['name' => 'laravel/boost', 'version' => 'v2.10.1']];
        $this->json('vendor/composer/installed.json', ['packages' => $packages]);
        $this->json('composer.lock', ['packages' => $packages, 'packages-dev' => []]);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function test_multiple_items_install_only_explicit_rules_and_skills(): void
    {
        $this->item('rules', "## Rules\n\nFollow a project rule.\n\n## Examples\n\nUse this illustration.");
        $this->item('workflow', "## Skill\n\n1. Inspect the change.\n2. Validate behavior.");
        $this->item('package-only', '', "dependencies:\n  development:\n    - vendor/tool\n");
        $this->process = function ($command) {
            $this->addPackage('vendor/tool');

            return new ProcessResult(0, 'installed');
        };

        $report = $this->installer()->install($this->root, ['rules', 'workflow', 'package-only', 'rules']);

        self::assertTrue($report->successful(), implode('\n', $report->failures));
        self::assertCount(3, $report->items);
        self::assertFileExists($this->root.'/.ai/rules/laracanon-rules.md');
        self::assertFileExists($this->root.'/.ai/skills/laracanon-workflow/SKILL.md');
        self::assertFileDoesNotExist($this->root.'/.ai/skills/laracanon-rules/SKILL.md');
        self::assertFileDoesNotExist($this->root.'/.ai/rules/laracanon-package-only.md');
        self::assertStringContainsString('Follow explicit project rules', file_get_contents($this->root.'/.ai/rules/laracanon-rules.md'));
        self::assertContains('vendor/tool', $this->boostCalls[0][2]);
        self::assertCount(1, $this->commands);
    }

    public function test_no_names_install_every_catalog_item_and_its_dependencies(): void
    {
        $this->item('rules', "## Rules\n\nFollow a project rule.");
        $this->item('workflow', "## Skill\n\nFollow this authored workflow.");
        $this->item('package-only', '', "dependencies:\n  development:\n    - vendor/tool\n");
        $this->process = function () {
            $this->addPackage('vendor/tool');

            return new ProcessResult(0, 'installed');
        };

        $report = $this->installer()->install($this->root, []);

        self::assertTrue($report->successful(), implode('\n', $report->failures));
        self::assertSame(['package-only' => 'installed', 'rules' => 'installed', 'workflow' => 'installed'], $report->items);
        self::assertCount(1, $this->commands);
        self::assertContains('--dev', $this->commands[0]);
        self::assertFileExists($this->root.'/.ai/rules/laracanon-rules.md');
        self::assertFileExists($this->root.'/.ai/skills/laracanon-workflow/SKILL.md');
        self::assertContains('vendor/tool', $this->boostCalls[0][2]);
    }

    public function test_multiple_selected_items_combine_named_plugin_permissions_before_the_shared_dependency(): void
    {
        $this->item('first', '', "dependencies:\n  development:\n    - example/shared-tool\ncomposer_plugins:\n  example/shared-tool:\n    - example/first-plugin\n");
        $this->item('second', '', "dependencies:\n  development:\n    - example/shared-tool\ncomposer_plugins:\n  example/shared-tool:\n    - example/first-plugin\n    - example/second-plugin\n");
        $this->item('unselected', '', "dependencies:\n  development:\n    - example/unselected-tool\ncomposer_plugins:\n  example/unselected-tool:\n    - example/unselected-plugin\n");
        $composer = json_decode(file_get_contents($this->root.'/composer.json'), true);
        $composer['config'] = ['sort-packages' => true, 'allow-plugins' => ['example/project-plugin' => true]];
        $this->json('composer.json', $composer);
        $this->process = function ($command) {
            if ($command[1] === 'config') {
                return $this->configurePlugin($command);
            }
            self::assertSame('example/shared-tool:*@stable', $command[2]);
            $manifest = json_decode(file_get_contents($this->root.'/composer.json'), true);
            self::assertTrue($manifest['config']['allow-plugins']['example/first-plugin']);
            self::assertTrue($manifest['config']['allow-plugins']['example/second-plugin']);
            $this->addPackage('example/shared-tool');

            return new ProcessResult(0, 'installed');
        };

        $report = $this->installer()->install($this->root, ['first', 'second']);

        self::assertTrue($report->successful(), implode("\n", $report->failures));
        self::assertSame(['first' => 'installed', 'second' => 'installed'], $report->items);
        self::assertCount(4, $this->commands);
        self::assertSame('allow-plugins', $this->commands[0][5]);
        self::assertSame(['allow-plugins.example/first-plugin', 'allow-plugins.example/second-plugin'], array_column(array_slice($this->commands, 1, 2), 5));
        $after = json_decode(file_get_contents($this->root.'/composer.json'), true);
        self::assertSame($composer['require'], $after['require']);
        self::assertSame($composer['scripts'], $after['scripts']);
        self::assertTrue($after['config']['sort-packages']);
        self::assertSame(['example/project-plugin' => true, 'example/first-plugin' => true, 'example/second-plugin' => true], $after['config']['allow-plugins']);
        self::assertSame('^2.10', $after['require-dev']['laravel/boost']);
        self::assertArrayNotHasKey('example/unselected-tool', $after['require-dev']);
    }

    public function test_unselected_item_plugin_permissions_do_not_change_the_project(): void
    {
        $this->item('rules', "## Rules\n\nSelected convention.");
        $this->item('package', '', "dependencies:\n  development:\n    - example/package-tool\ncomposer_plugins:\n  example/package-tool:\n    - example/package-plugin\n");
        $manifest = file_get_contents($this->root.'/composer.json');

        $report = $this->installer()->install($this->root, ['rules']);

        self::assertTrue($report->successful(), implode("\n", $report->failures));
        self::assertSame(['rules' => 'installed'], $report->items);
        self::assertSame([], $this->commands);
        self::assertSame($manifest, file_get_contents($this->root.'/composer.json'));
    }

    public function test_no_name_dry_run_plans_all_dependency_types_without_file_or_process_changes(): void
    {
        $this->item('rules', "## Rules\n\nRule.", "dependencies:\n  runtime:\n    - vendor/library\n");
        $this->item('tool', '', "dependencies:\n  development:\n    - vendor/tool\n");
        $this->item('workflow', "## Skill\n\nAuthored steps.");
        $before = $this->snapshot();

        $report = $this->installer()->install($this->root, [], true);

        self::assertTrue($report->successful());
        self::assertSame(['rules' => 'planned', 'tool' => 'planned', 'workflow' => 'planned'], $report->items);
        self::assertCount(2, $report->dependencies);
        self::assertStringContainsString('vendor/library to require ', $report->dependencies[0]);
        self::assertStringContainsString('vendor/tool to require-dev ', $report->dependencies[1]);
        self::assertSame([], $this->commands);
        self::assertSame($before, $this->snapshot());
        self::assertTrue($this->boostCalls[0][1]);
    }

    public function test_installing_all_is_idempotent_discovers_new_markdown_and_preserves_existing_local_edits(): void
    {
        $this->item('rules', "## Rules\n\nInitial convention.");
        $installer = $this->installer();
        self::assertTrue($installer->install($this->root, [])->successful());
        $before = $this->snapshot();

        $repeat = $installer->install($this->root, []);

        self::assertTrue($repeat->successful());
        self::assertSame($before, $this->snapshot());
        $this->item('new-workflow', "## Skill\n\nNew authored workflow.");
        file_put_contents($this->root.'/.ai/rules/laracanon-rules.md', 'local project preference');

        $all = $installer->install($this->root, []);

        self::assertFalse($all->successful());
        self::assertSame(['new-workflow' => 'installed', 'rules' => 'conflict'], $all->items);
        self::assertCount(1, $all->conflicts);
        self::assertSame('local project preference', file_get_contents($this->root.'/.ai/rules/laracanon-rules.md'));
        self::assertFileExists($this->root.'/.ai/skills/laracanon-new-workflow/SKILL.md');
        $state = json_decode(file_get_contents($this->root.'/.ai/laracanon/state.json'), true);
        self::assertSame('rules', $state['files']['.ai/rules/laracanon-rules.md']['owner']);
        self::assertSame('new-workflow', $state['files']['.ai/skills/laracanon-new-workflow/SKILL.md']['owner']);
        self::assertSame([], $this->commands);
    }

    public function test_no_names_with_empty_catalog_fails_before_dependency_boost_or_file_changes(): void
    {
        $before = $this->snapshot();

        foreach ([false, true] as $dryRun) {
            $report = $this->installer()->install($this->root, [], $dryRun);

            self::assertFalse($report->successful());
            self::assertSame([], $report->items);
            self::assertStringContainsString('No items available', $report->failures[0]);
            self::assertSame($before, $this->snapshot());
        }

        self::assertSame([], $this->commands);
        self::assertSame([], $this->boostCalls);
    }

    public function test_repeat_install_and_source_updates_preserve_unrelated_files(): void
    {
        $this->item('rules', "## Rules\n\nVersion one.");
        mkdir($this->root.'/.ai/rules', 0777, true);
        file_put_contents($this->root.'/.ai/rules/own.md', 'my own rule');
        $composer = file_get_contents($this->root.'/composer.json');
        $first = $this->installer()->install($this->root, ['rules']);
        $state = file_get_contents($this->root.'/.ai/laracanon/state.json');
        $second = $this->installer()->install($this->root, ['rules']);
        self::assertTrue($first->successful());
        self::assertTrue($second->successful());
        self::assertStringContainsString('(unchanged)', $second->rules[0]);
        self::assertSame($state, file_get_contents($this->root.'/.ai/laracanon/state.json'));

        $this->item('rules', "## Rules\n\nVersion two.");
        $updated = $this->installer()->install($this->root, ['rules']);
        self::assertStringContainsString('(updated)', $updated->rules[0]);
        self::assertStringContainsString('Version two.', file_get_contents($this->root.'/.ai/rules/laracanon-rules.md'));
        self::assertSame('my own rule', file_get_contents($this->root.'/.ai/rules/own.md'));
        self::assertSame($composer, file_get_contents($this->root.'/composer.json'));
        self::assertSame([], $this->commands);
    }

    public function test_local_edits_are_preserved_across_retries(): void
    {
        $this->item('rules', "## Rules\n\nOriginal.");
        $this->installer()->install($this->root, ['rules']);
        $path = $this->root.'/.ai/rules/laracanon-rules.md';
        file_put_contents($path, 'local edit');
        $this->item('rules', "## Rules\n\nNew source.");

        foreach ([1, 2] as $attempt) {
            $report = $this->installer()->install($this->root, ['rules']);
            self::assertFalse($report->successful());
            self::assertCount(1, $report->conflicts);
            self::assertSame('local edit', file_get_contents($path));
            self::assertSame('conflict', $report->items['rules']);
        }
    }

    public function test_dry_run_runs_no_processes_and_changes_no_files(): void
    {
        $this->item('rules', "## Rules\n\nProject rule.", "dependencies:\n  runtime:\n    - vendor/library\n");
        $before = $this->snapshot();
        $report = $this->installer()->install($this->root, ['rules'], true);
        self::assertTrue($report->successful());
        self::assertTrue($report->dryRun);
        self::assertSame('planned', $report->items['rules']);
        self::assertCount(1, $report->dependencies);
        self::assertSame([], $this->commands);
        self::assertSame($before, $this->snapshot());
        self::assertTrue($this->boostCalls[0][1]);
    }

    public function test_failed_dependency_skips_only_its_item(): void
    {
        $this->item('bad', "## Rules\n\nNeeds package.", "dependencies:\n  runtime:\n    - vendor/incompatible\n");
        $this->item('good', "## Rules\n\nIndependent.");
        $this->process = fn () => new ProcessResult(2, 'PHP version is incompatible; Composer restored the manifest.');
        $report = $this->installer()->install($this->root, ['bad', 'good']);
        self::assertFalse($report->successful());
        self::assertSame('failed dependencies', $report->items['bad']);
        self::assertSame('installed', $report->items['good']);
        self::assertFileDoesNotExist($this->root.'/.ai/rules/laracanon-bad.md');
        self::assertFileExists($this->root.'/.ai/rules/laracanon-good.md');
        self::assertStringContainsString('incompatible', $report->failures[0]);
        self::assertCount(1, $this->boostCalls);
    }

    public function test_boost_failure_does_not_discard_completed_item_files(): void
    {
        $this->item('rules', "## Rules\n\nConvention.");
        $this->boostFailures = ['Boost version lacks the rule-index API.'];
        $report = $this->installer()->install($this->root, ['rules']);
        self::assertFalse($report->successful());
        self::assertFileExists($this->root.'/.ai/rules/laracanon-rules.md');
        self::assertFileExists($this->root.'/.ai/laracanon/state.json');
        self::assertSame($this->boostFailures, $report->failures);
    }

    public function test_dependency_skill_collision_requires_explicit_override(): void
    {
        $this->packageSkills = ['package-workflow'];
        $this->item('workflow', "## Skill\n\nFollow authored steps.", "skill_name: package-workflow\n");
        $report = $this->installer()->install($this->root, ['workflow']);
        self::assertCount(1, $report->conflicts);
        self::assertFileDoesNotExist($this->root.'/.ai/skills/package-workflow/SKILL.md');
        $this->item('workflow', "## Skill\n\nFollow authored steps.", "skill_name: package-workflow\noverrides_skill: true\n");
        $report = $this->installer()->install($this->root, ['workflow']);
        self::assertTrue($report->successful());
        self::assertFileExists($this->root.'/.ai/skills/package-workflow/SKILL.md');
    }

    public function test_retired_sections_remove_only_unmodified_owned_files(): void
    {
        $this->item('workflow', "## Rules\n\nConvention.\n\n## Skill\n\nDo these steps.");
        $this->installer()->install($this->root, ['workflow']);
        file_put_contents($this->root.'/.ai/rules/laracanon-workflow.md', 'edited rule');
        $this->item('workflow', '');
        $report = $this->installer()->install($this->root, ['workflow']);
        self::assertCount(1, $report->conflicts);
        self::assertSame('edited rule', file_get_contents($this->root.'/.ai/rules/laracanon-workflow.md'));
        self::assertFileDoesNotExist($this->root.'/.ai/skills/laracanon-workflow/SKILL.md');
    }

    public function test_invalid_target_and_unknown_selection_fail_before_mutations(): void
    {
        $this->item('rules', "## Rules\n\nConvention.");
        $before = $this->snapshot();
        $report = $this->installer()->install($this->root, ['rules', 'unknown']);
        self::assertFalse($report->successful());
        self::assertSame($before, $this->snapshot());
        unlink($this->root.'/artisan');
        $report = $this->installer()->install($this->root, ['rules']);
        self::assertStringContainsString('not a Laravel application', $report->failures[0]);
        self::assertSame([], $this->commands);
    }

    public function test_unowned_files_and_symlinks_are_never_overwritten(): void
    {
        $this->item('rules', "## Rules\n\nConvention.");
        mkdir($this->root.'/.ai/rules', 0777, true);
        file_put_contents($this->root.'/.ai/rules/laracanon-rules.md', 'unowned');
        $report = $this->installer()->install($this->root, ['rules']);
        self::assertCount(1, $report->conflicts);
        self::assertSame('unowned', file_get_contents($this->root.'/.ai/rules/laracanon-rules.md'));
        unlink($this->root.'/.ai/rules/laracanon-rules.md');
        symlink($this->root.'/composer.json', $this->root.'/.ai/rules/laracanon-rules.md');
        $composer = file_get_contents($this->root.'/composer.json');
        $report = $this->installer()->install($this->root, ['rules']);
        self::assertFalse($report->successful());
        self::assertStringContainsString('symlink', $report->failures[0]);
        self::assertSame($composer, file_get_contents($this->root.'/composer.json'));
    }

    public function test_selected_item_output_collisions_fail_before_dependency_or_file_changes(): void
    {
        $extra = "skill_name: shared-workflow\ndependencies:\n  runtime:\n    - vendor/new-library\n";
        $this->item('first', "## Skill\n\nFirst workflow.", $extra);
        $this->item('second', "## Skill\n\nSecond workflow.", $extra);
        $before = $this->snapshot();

        foreach ([['first', 'second'], []] as $names) {
            foreach ([false, true] as $dryRun) {
                $report = $this->installer()->install($this->root, $names, $dryRun);
                self::assertFalse($report->successful());
                self::assertStringContainsString('selected items first and second both target', $report->conflicts[0]);
                self::assertSame($before, $this->snapshot());
            }
        }
        self::assertSame([], $this->commands);
        self::assertSame([], $this->boostCalls);
    }

    public function test_another_installed_item_cannot_claim_or_replace_an_owned_skill(): void
    {
        $this->item('first', "## Skill\n\nFirst workflow.", "skill_name: shared-workflow\n");
        self::assertTrue($this->installer()->install($this->root, ['first'])->successful());
        $this->item('second', "## Skill\n\nSecond workflow.", "skill_name: shared-workflow\ndependencies:\n  runtime:\n    - vendor/new-library\n");
        $before = $this->snapshot();

        $report = $this->installer()->install($this->root, ['second']);

        self::assertFalse($report->successful());
        self::assertStringContainsString('managed by item first', $report->conflicts[0]);
        self::assertSame($before, $this->snapshot());
        self::assertSame([], $this->commands);
        self::assertCount(1, $this->boostCalls);
        $state = json_decode(file_get_contents($this->root.'/.ai/laracanon/state.json'), true);
        self::assertSame('first', $state['files']['.ai/skills/shared-workflow/SKILL.md']['owner']);
    }

    public function test_partial_file_failure_records_completed_paths_for_later_retirement(): void
    {
        $this->item('partial', "## Rules\n\nA completed rule.\n\n## Skill\n\nAn authored workflow.");
        mkdir($this->root.'/.ai/skills', 0777, true);
        file_put_contents($this->root.'/.ai/skills/laracanon-partial', 'a user file blocking the skill directory');

        $report = $this->installer()->install($this->root, ['partial']);

        self::assertSame('partial failure', $report->items['partial']);
        self::assertStringContainsString('Cannot create', $report->failures[0]);
        self::assertFileExists($this->root.'/.ai/rules/laracanon-partial.md');
        self::assertCount(1, $report->rules);
        $state = json_decode(file_get_contents($this->root.'/.ai/laracanon/state.json'), true);
        self::assertSame(['.ai/rules/laracanon-partial.md'], $state['items']['partial']['files']);
        self::assertSame('partial failure', $state['items']['partial']['status']);
        self::assertSame('partial', $state['files']['.ai/rules/laracanon-partial.md']['owner']);

        $this->item('partial', '');
        $retry = $this->installer()->install($this->root, ['partial']);
        self::assertTrue($retry->successful());
        self::assertFileDoesNotExist($this->root.'/.ai/rules/laracanon-partial.md');
        self::assertSame('a user file blocking the skill directory', file_get_contents($this->root.'/.ai/skills/laracanon-partial'));
    }

    public function test_previous_owned_paths_survive_a_failed_replacement_and_can_be_retired(): void
    {
        $this->item('workflow', "## Skill\n\nOriginal workflow.", "skill_name: original-workflow\n");
        self::assertTrue($this->installer()->install($this->root, ['workflow'])->successful());
        file_put_contents($this->root.'/.ai/skills/replacement-workflow', 'blocking file');
        $this->item('workflow', "## Rules\n\nNew convention.\n\n## Skill\n\nNew workflow.", "skill_name: replacement-workflow\n");

        $report = $this->installer()->install($this->root, ['workflow']);

        self::assertSame('partial failure', $report->items['workflow']);
        $state = json_decode(file_get_contents($this->root.'/.ai/laracanon/state.json'), true);
        self::assertSame(['.ai/skills/original-workflow/SKILL.md', '.ai/rules/laracanon-workflow.md'], $state['items']['workflow']['files']);
        self::assertFileExists($this->root.'/.ai/skills/original-workflow/SKILL.md');
        $this->item('workflow', '');
        self::assertTrue($this->installer()->install($this->root, ['workflow'])->successful());
        self::assertFileDoesNotExist($this->root.'/.ai/skills/original-workflow/SKILL.md');
        self::assertFileDoesNotExist($this->root.'/.ai/rules/laracanon-workflow.md');
    }

    #[DataProvider('malformedOwnershipStates')]
    public function test_malformed_nested_ownership_state_fails_before_dependency_changes(array $state): void
    {
        $this->item('rules', "## Rules\n\nA rule.", "dependencies:\n  runtime:\n    - vendor/new-library\n");
        mkdir($this->root.'/.ai/laracanon', 0777, true);
        $this->json('.ai/laracanon/state.json', $state);
        $before = $this->snapshot();

        $report = $this->installer()->install($this->root, ['rules']);

        self::assertFalse($report->successful());
        self::assertSame([], $this->commands);
        self::assertSame([], $this->boostCalls);
        self::assertSame($before, $this->snapshot());
    }

    public static function malformedOwnershipStates(): array
    {
        $hash = str_repeat('a', 64);
        $path = '.ai/rules/laracanon-rules.md';
        $item = ['source_hash' => $hash, 'files' => [$path], 'status' => 'installed'];

        return [
            'file metadata scalar' => [['schema' => 1, 'files' => [$path => 'invalid'], 'items' => ['rules' => $item]]],
            'file hash missing' => [['schema' => 1, 'files' => [$path => []], 'items' => ['rules' => $item]]],
            'file hash invalid' => [['schema' => 1, 'files' => [$path => ['hash' => 'invalid']], 'items' => ['rules' => $item]]],
            'file owner inconsistent' => [['schema' => 1, 'files' => [$path => ['hash' => $hash, 'owner' => 'other']], 'items' => ['rules' => $item]]],
            'file owner null' => [['schema' => 1, 'files' => [$path => ['hash' => $hash, 'owner' => null]], 'items' => ['rules' => $item]]],
            'item scalar' => [['schema' => 1, 'files' => [], 'items' => ['rules' => 'invalid']]],
            'item source hash invalid' => [['schema' => 1, 'files' => [], 'items' => ['rules' => ['source_hash' => 'invalid', 'files' => [], 'status' => 'installed']]]],
            'item files not list' => [['schema' => 1, 'files' => [], 'items' => ['rules' => ['source_hash' => $hash, 'files' => ['path' => $path], 'status' => 'installed']]]],
            'item file not string' => [['schema' => 1, 'files' => [], 'items' => ['rules' => ['source_hash' => $hash, 'files' => [true], 'status' => 'installed']]]],
            'item file parent traversal' => [['schema' => 1, 'files' => [], 'items' => ['rules' => ['source_hash' => $hash, 'files' => ['.ai/rules/../../composer.json'], 'status' => 'installed']]]],
            'item status invalid' => [['schema' => 1, 'files' => [], 'items' => ['rules' => ['source_hash' => $hash, 'files' => [], 'status' => true]]]],
            'multiple item claims' => [['schema' => 1, 'files' => [$path => ['hash' => $hash]], 'items' => ['rules' => $item, 'other' => $item]]],
        ];
    }

    public function test_legacy_schema_one_ownership_is_inferred_from_item_paths(): void
    {
        $this->item('rules', "## Rules\n\nVersion one.");
        self::assertTrue($this->installer()->install($this->root, ['rules'])->successful());
        $state = json_decode(file_get_contents($this->root.'/.ai/laracanon/state.json'), true);
        unset($state['files']['.ai/rules/laracanon-rules.md']['owner']);
        $this->json('.ai/laracanon/state.json', $state);
        $this->item('rules', "## Rules\n\nVersion two.");

        $report = $this->installer()->install($this->root, ['rules']);

        self::assertTrue($report->successful());
        self::assertStringContainsString('Version two.', file_get_contents($this->root.'/.ai/rules/laracanon-rules.md'));
        $state = json_decode(file_get_contents($this->root.'/.ai/laracanon/state.json'), true);
        self::assertSame('rules', $state['files']['.ai/rules/laracanon-rules.md']['owner']);
    }

    public function test_symlinked_install_lock_is_rejected_before_dependency_changes(): void
    {
        $this->item('rules', "## Rules\n\nA rule.", "dependencies:\n  runtime:\n    - vendor/new-library\n");
        mkdir($this->root.'/.ai/laracanon', 0777, true);
        symlink($this->root.'/composer.json', $this->root.'/.ai/laracanon/install.lock');
        $before = $this->snapshot();

        $report = $this->installer()->install($this->root, ['rules']);

        self::assertFalse($report->successful());
        self::assertStringContainsString('symlink', $report->failures[0]);
        self::assertSame([], $this->commands);
        self::assertSame([], $this->boostCalls);
        self::assertSame($before, $this->snapshot());
    }

    public function test_dropping_a_package_skill_override_retires_only_unmodified_custom_sources(): void
    {
        $this->packageSkills = ['package-workflow'];
        $this->item('workflow', "## Skill\n\nExplicit replacement.", "skill_name: package-workflow\noverrides_skill: true\n");
        self::assertTrue($this->installer()->install($this->root, ['workflow'])->successful());
        $this->item('workflow', "## Skill\n\nExplicit replacement.", "skill_name: package-workflow\n");

        $report = $this->installer()->install($this->root, ['workflow']);

        self::assertCount(1, $report->conflicts);
        self::assertFileDoesNotExist($this->root.'/.ai/skills/package-workflow/SKILL.md');
        $this->item('workflow', "## Skill\n\nExplicit replacement.", "skill_name: package-workflow\noverrides_skill: true\n");
        self::assertTrue($this->installer()->install($this->root, ['workflow'])->successful());
        file_put_contents($this->root.'/.ai/skills/package-workflow/SKILL.md', 'local workflow edit');
        $this->item('workflow', "## Skill\n\nExplicit replacement.", "skill_name: package-workflow\n");

        $report = $this->installer()->install($this->root, ['workflow']);

        self::assertCount(2, $report->conflicts);
        self::assertSame('local workflow edit', file_get_contents($this->root.'/.ai/skills/package-workflow/SKILL.md'));
        self::assertStringContainsString('may continue to shadow', implode('\n', $report->notes));
    }

    public function test_configuration_installs_literal_bytes_after_its_development_dependency_is_available(): void
    {
        $contents = "<?php\nfile_put_contents(__DIR__.'/executed', 'must not execute');\nreturn ['enabled' => true];\n";
        $this->configurationItem('quality', ['config/quality.php' => $contents], "dependencies:\n  development:\n    - example/quality-tool\n");
        $composer = json_decode(file_get_contents($this->root.'/composer.json'), true);
        $this->process = function ($command) {
            self::assertSame('example/quality-tool:*@stable', $command[2]);
            self::assertContains('--dev', $command);
            self::assertFileDoesNotExist($this->root.'/config/quality.php', 'Configuration waits for its package installation.');
            $this->addPackage('example/quality-tool');

            return new ProcessResult(0, 'installed');
        };

        $report = $this->installer()->install($this->root, ['quality']);

        self::assertTrue($report->successful(), implode("\n", $report->failures));
        self::assertSame(['config/quality.php (installed)'], $report->files);
        self::assertSame([], $report->rules);
        self::assertSame([], $report->skills);
        self::assertSame($contents, file_get_contents($this->root.'/config/quality.php'));
        self::assertFileDoesNotExist($this->root.'/config/executed');
        $installedComposer = json_decode(file_get_contents($this->root.'/composer.json'), true);
        self::assertSame($composer['require'], $installedComposer['require']);
        self::assertSame($composer['scripts'], $installedComposer['scripts']);
        self::assertSame('*@stable', $installedComposer['require-dev']['example/quality-tool']);
        $state = json_decode(file_get_contents($this->root.'/.ai/laracanon/state.json'), true);
        self::assertSame(['hash' => hash('sha256', $contents), 'owner' => 'quality'], $state['files']['config/quality.php']);
        self::assertSame(['config/quality.php'], $state['items']['quality']['files']);
    }

    public function test_configuration_repeat_and_source_updates_preserve_local_edits_across_retries(): void
    {
        $firstContents = "<?php\nreturn ['version' => 1];\n";
        $this->configurationItem('quality', ['config/quality.php' => $firstContents]);
        $installer = $this->installer();
        self::assertTrue($installer->install($this->root, ['quality'])->successful());
        $before = $this->snapshot();

        $repeat = $installer->install($this->root, ['quality']);

        self::assertTrue($repeat->successful());
        self::assertSame(['config/quality.php (unchanged)'], $repeat->files);
        self::assertSame($before, $this->snapshot());
        $newContents = "<?php\nreturn ['version' => 2];\n";
        $this->configurationItem('quality', ['config/quality.php' => $newContents]);
        $updated = $installer->install($this->root, ['quality']);
        self::assertTrue($updated->successful());
        self::assertSame(['config/quality.php (updated)'], $updated->files);
        self::assertSame($newContents, file_get_contents($this->root.'/config/quality.php'));

        file_put_contents($this->root.'/config/quality.php', 'local project configuration');
        $this->configurationItem('quality', ['config/quality.php' => "<?php\nreturn ['version' => 3];\n"]);
        foreach ([1, 2] as $attempt) {
            $report = $installer->install($this->root, ['quality']);
            self::assertFalse($report->successful());
            self::assertSame('conflict', $report->items['quality']);
            self::assertCount(1, $report->conflicts);
            self::assertSame('local project configuration', file_get_contents($this->root.'/config/quality.php'));
        }
        self::assertSame([], $this->commands);
    }

    public function test_retired_configuration_removes_only_unchanged_owned_files(): void
    {
        $this->configurationItem('quality', ['config/keep.php' => "<?php\nreturn [];\n", 'config/remove.php' => "<?php\nreturn [];\n"]);
        $installer = $this->installer();
        self::assertTrue($installer->install($this->root, ['quality'])->successful());
        file_put_contents($this->root.'/config/keep.php', 'edited configuration');
        $this->item('quality', '');

        foreach ([1, 2] as $attempt) {
            $report = $installer->install($this->root, ['quality']);
            self::assertCount(1, $report->conflicts);
            self::assertSame('edited configuration', file_get_contents($this->root.'/config/keep.php'));
            self::assertFileDoesNotExist($this->root.'/config/remove.php');
            $state = json_decode(file_get_contents($this->root.'/.ai/laracanon/state.json'), true);
            self::assertSame(['config/keep.php'], $state['items']['quality']['files']);
            self::assertArrayNotHasKey('config/remove.php', $state['files']);
        }
    }

    public function test_unowned_configuration_is_preserved_unless_it_matches_the_authored_bytes(): void
    {
        $contents = "<?php\nreturn ['enabled' => true];\n";
        $this->configurationItem('quality', ['config/quality.php' => $contents]);
        mkdir($this->root.'/config');
        file_put_contents($this->root.'/config/quality.php', 'existing project configuration');
        $installer = $this->installer();

        $conflict = $installer->install($this->root, ['quality']);

        self::assertCount(1, $conflict->conflicts);
        self::assertSame('existing project configuration', file_get_contents($this->root.'/config/quality.php'));
        $state = json_decode(file_get_contents($this->root.'/.ai/laracanon/state.json'), true);
        self::assertSame([], $state['items']['quality']['files']);
        file_put_contents($this->root.'/config/quality.php', $contents);

        $adopted = $installer->install($this->root, ['quality']);

        self::assertTrue($adopted->successful());
        self::assertSame(['config/quality.php (unchanged)'], $adopted->files);
        $state = json_decode(file_get_contents($this->root.'/.ai/laracanon/state.json'), true);
        self::assertSame('quality', $state['files']['config/quality.php']['owner']);
    }

    #[DataProvider('configurationOutputPaths')]
    public function test_shared_configuration_destinations_fail_before_composer_or_file_changes(string $path): void
    {
        $extra = "dependencies:\n  development:\n    - example/quality-tool\n";
        $this->configurationItem('first', [$path => "<?php\nreturn [];\n"], $extra);
        $this->configurationItem('second', [$path => "<?php\nreturn [];\n"], $extra);
        $before = $this->snapshot();

        foreach ([false, true] as $dryRun) {
            $report = $this->installer()->install($this->root, ['first', 'second'], $dryRun);
            self::assertFalse($report->successful());
            self::assertStringContainsString('selected items first and second both target', $report->conflicts[0]);
            self::assertSame($before, $this->snapshot());
        }
        self::assertSame([], $this->commands);
        self::assertSame([], $this->boostCalls);
    }

    #[DataProvider('configurationOutputPaths')]
    public function test_another_item_cannot_claim_owned_configuration_before_installing_its_dependency(string $path): void
    {
        $contents = "<?php\nreturn [];\n";
        $this->configurationItem('first', [$path => $contents]);
        self::assertTrue($this->installer()->install($this->root, ['first'])->successful());
        $this->configurationItem('second', [$path => $contents], "dependencies:\n  development:\n    - example/quality-tool\n");
        $before = $this->snapshot();

        $report = $this->installer()->install($this->root, ['second']);

        self::assertFalse($report->successful());
        self::assertStringContainsString('managed by item first', $report->conflicts[0]);
        self::assertSame([], $this->commands);
        self::assertSame($before, $this->snapshot());
    }

    public static function configurationOutputPaths(): array
    {
        return [['config/shared.php'], ['phpstan.neon.dist']];
    }

    public function test_root_neon_installation_repeats_updates_and_preserves_edits_when_the_source_is_retired(): void
    {
        $initial = "parameters:\n    level: 6\n";
        $this->configurationItem('quality', ['phpstan.neon.dist' => $initial]);
        $installer = $this->installer();
        $first = $installer->install($this->root, ['quality']);
        self::assertTrue($first->successful(), implode("\n", $first->failures));
        self::assertSame(['phpstan.neon.dist (installed)'], $first->files);
        $before = $this->snapshot();

        $repeat = $installer->install($this->root, ['quality']);

        self::assertTrue($repeat->successful());
        self::assertSame(['phpstan.neon.dist (unchanged)'], $repeat->files);
        self::assertSame($before, $this->snapshot());
        $updated = "parameters:\n    level: 7\n";
        $this->configurationItem('quality', ['phpstan.neon.dist' => $updated]);
        self::assertTrue($installer->install($this->root, ['quality'])->successful());
        self::assertSame($updated, file_get_contents($this->root.'/phpstan.neon.dist'));
        $state = json_decode(file_get_contents($this->root.'/.ai/laracanon/state.json'), true);
        self::assertSame(['hash' => hash('sha256', $updated), 'owner' => 'quality'], $state['files']['phpstan.neon.dist']);
        file_put_contents($this->root.'/phpstan.neon.dist', 'local project configuration');
        $this->item('quality', '');

        $retired = $installer->install($this->root, ['quality']);

        self::assertCount(1, $retired->conflicts);
        self::assertSame('local project configuration', file_get_contents($this->root.'/phpstan.neon.dist'));
        file_put_contents($this->root.'/phpstan.neon.dist', $updated);
        self::assertTrue($installer->install($this->root, ['quality'])->successful());
        self::assertFileDoesNotExist($this->root.'/phpstan.neon.dist');
        self::assertSame([], $this->commands);
    }

    public function test_root_neon_dry_run_changes_no_files_or_processes_and_symlink_preflight_rejects_installation(): void
    {
        $this->configurationItem('quality', ['phpstan.neon.dist' => "parameters:\n    level: 6\n"], "dependencies:\n  development:\n    - example/quality-tool\n");
        $before = $this->snapshot();
        $installer = $this->installer();

        $dryRun = $installer->install($this->root, ['quality'], true);

        self::assertTrue($dryRun->successful(), implode("\n", $dryRun->failures));
        self::assertSame(['phpstan.neon.dist (installed)'], $dryRun->files);
        self::assertSame($before, $this->snapshot());
        symlink($this->root.'/composer.json', $this->root.'/phpstan.neon.dist');
        $before = $this->snapshot();

        foreach ([false, true] as $preview) {
            $rejected = $installer->install($this->root, ['quality'], $preview);
            self::assertFalse($rejected->successful());
            self::assertStringContainsString('Refusing symlink', $rejected->failures[0]);
            self::assertSame($before, $this->snapshot());
        }
        self::assertSame([], $this->commands);
    }

    #[DataProvider('configurationSymlinks')]
    public function test_configuration_symlinks_fail_before_dependency_installation(string $relative): void
    {
        $this->configurationItem('quality', ['config/quality/quality.php' => "<?php\nreturn [];\n"], "dependencies:\n  development:\n    - example/quality-tool\n");
        mkdir($this->root.'/original');
        file_put_contents($this->root.'/original/quality.php', 'original configuration');
        if ($relative !== 'config') {
            mkdir($this->root.'/config/quality', 0777, true);
        }
        if ($relative === 'config/quality') {
            rmdir($this->root.'/config/quality');
        }
        symlink($this->root.'/original'.($relative === 'config/quality/quality.php' ? '/quality.php' : ''), $this->root.'/'.$relative);
        $before = $this->snapshot();

        foreach ([false, true] as $dryRun) {
            $report = $this->installer()->install($this->root, ['quality'], $dryRun);
            self::assertFalse($report->successful());
            self::assertStringContainsString('Refusing symlink', $report->failures[0]);
            self::assertSame($before, $this->snapshot());
        }
        self::assertSame([], $this->commands);
        self::assertSame('original configuration', file_get_contents($this->root.'/original/quality.php'));
    }

    public static function configurationSymlinks(): array
    {
        return [['config'], ['config/quality'], ['config/quality/quality.php']];
    }

    public function test_configuration_path_traversal_fails_before_dependency_or_file_changes(): void
    {
        $this->configurationItem('quality', ['config/../composer.json' => "<?php\nreturn [];\n"], "dependencies:\n  development:\n    - example/quality-tool\n");
        $before = $this->snapshot();

        $report = $this->installer()->install($this->root, ['quality']);

        self::assertFalse($report->successful());
        self::assertStringContainsString('canonical relative config/', $report->failures[0]);
        self::assertSame([], $this->commands);
        self::assertSame([], $this->boostCalls);
        self::assertSame($before, $this->snapshot());
    }

    public function test_configuration_dry_run_plans_dependency_and_file_without_mutations(): void
    {
        $this->configurationItem('quality', ['config/quality.php' => "<?php\nreturn [];\n"], "dependencies:\n  development:\n    - example/quality-tool\n");
        $before = $this->snapshot();

        $report = $this->installer()->install($this->root, ['quality'], true);

        self::assertTrue($report->successful());
        self::assertSame(['quality' => 'planned'], $report->items);
        self::assertSame(['config/quality.php (installed)'], $report->files);
        self::assertStringContainsString('example/quality-tool to require-dev', $report->dependencies[0]);
        self::assertSame([], $this->commands);
        self::assertSame($before, $this->snapshot());
    }

    public function test_failed_configuration_dependency_preserves_existing_configuration_and_allows_retry(): void
    {
        $this->configurationItem('quality', ['config/quality.php' => "<?php\nreturn ['version' => 1];\n"]);
        $installer = $this->installer();
        self::assertTrue($installer->install($this->root, ['quality'])->successful());
        $previous = file_get_contents($this->root.'/config/quality.php');
        $new = "<?php\nreturn ['version' => 2];\n";
        $this->configurationItem('quality', ['config/quality.php' => $new, 'config/new.php' => $new], "dependencies:\n  development:\n    - example/incompatible-tool\n");
        $this->item('independent', "## Rules\n\nThis item can complete.");
        $this->process = fn () => new ProcessResult(2, 'PHP version is incompatible; Composer restored the manifest.');

        $failed = $installer->install($this->root, ['quality', 'independent']);

        self::assertSame('failed dependencies', $failed->items['quality']);
        self::assertSame('installed', $failed->items['independent']);
        self::assertSame([], $failed->files);
        self::assertSame($previous, file_get_contents($this->root.'/config/quality.php'));
        self::assertFileDoesNotExist($this->root.'/config/new.php');
        self::assertFileExists($this->root.'/.ai/rules/laracanon-independent.md');
        $this->process = function () {
            $this->addPackage('example/incompatible-tool');

            return new ProcessResult(0, 'installed');
        };

        $retried = $installer->install($this->root, ['quality']);

        self::assertTrue($retried->successful());
        self::assertSame($new, file_get_contents($this->root.'/config/quality.php'));
        self::assertSame($new, file_get_contents($this->root.'/config/new.php'));
    }

    #[DataProvider('incompatibleInstalledVersions')]
    public function test_known_incompatible_minimum_versions_preserve_the_project_before_composer(string $version): void
    {
        $this->configurationItem('quality', ['phpstan.neon.dist' => "parameters:\n    level: 10\n"], "dependencies:\n  development:\n    - example/versioned-tool\n    - example/other-tool\nminimum_versions:\n  example/versioned-tool: '2.0.0'\n");
        $this->addPackage('example/versioned-tool', $version, '^1.0');
        file_put_contents($this->root.'/phpstan.neon.dist', 'existing project configuration');
        $before = $this->snapshot();

        foreach ([false, true] as $dryRun) {
            $report = $this->installer()->install($this->root, ['quality'], $dryRun);

            self::assertFalse($report->successful());
            self::assertSame(['quality' => 'incompatible dependencies'], $report->items);
            self::assertStringContainsString('example/versioned-tool', $report->failures[0]);
            self::assertStringContainsString('2.0.0', $report->failures[0]);
            self::assertStringContainsString($version, $report->failures[0]);
            self::assertSame($before, $this->snapshot());
        }
        self::assertSame([], $this->commands);
        self::assertSame([], $this->boostCalls);
    }

    public static function incompatibleInstalledVersions(): array
    {
        return [['1.12.0'], ['v1.12.0'], ['dev-main'], ['2.0.0-RC1']];
    }

    public function test_locked_legacy_minimum_version_is_rejected_before_composer_when_not_installed(): void
    {
        $this->configurationItem('quality', ['phpstan.neon.dist' => "parameters:\n    level: 10\n"], "dependencies:\n  development:\n    - example/versioned-tool\nminimum_versions:\n  example/versioned-tool: '2.0.0'\n");
        $this->addPackage('example/versioned-tool', '1.12.0', '^1.0');
        $this->removeInstalledPackage('example/versioned-tool');
        $before = $this->snapshot();

        foreach ([false, true] as $dryRun) {
            $report = $this->installer()->install($this->root, ['quality'], $dryRun);

            self::assertFalse($report->successful());
            self::assertSame(['quality' => 'incompatible dependencies'], $report->items);
            self::assertStringContainsString('minimum 2.0.0, but 1.12.0 is locked', $report->failures[0]);
            self::assertSame($before, $this->snapshot());
        }
        self::assertSame([], $this->commands);
        self::assertSame([], $this->boostCalls);
    }

    public function test_compatible_locked_minimum_version_can_be_planned_without_an_unknown_version_note(): void
    {
        $this->configurationItem('quality', ['phpstan.neon.dist' => "parameters:\n    level: 10\n"], "dependencies:\n  development:\n    - example/versioned-tool\nminimum_versions:\n  example/versioned-tool: '2.0.0'\n");
        $this->addPackage('example/versioned-tool', 'v2.1.0', '^2.0');
        $this->removeInstalledPackage('example/versioned-tool');
        $before = $this->snapshot();

        $report = $this->installer()->install($this->root, ['quality'], true);

        self::assertTrue($report->successful(), implode("\n", $report->failures));
        self::assertSame(['quality' => 'planned'], $report->items);
        self::assertSame(['phpstan.neon.dist (installed)'], $report->files);
        self::assertStringContainsString('from composer.lock', $report->dependencies[0]);
        self::assertStringNotContainsString('no installed or locked version is available', implode("\n", $report->notes));
        self::assertSame([], $this->commands);
        self::assertSame($before, $this->snapshot());
    }

    public function test_an_incompatible_item_excludes_its_dependencies_and_allows_independent_items_to_complete(): void
    {
        $this->configurationItem('quality', ['phpstan.neon.dist' => "parameters:\n    level: 10\n"], "dependencies:\n  development:\n    - example/versioned-tool\n    - example/other-tool\nminimum_versions:\n  example/versioned-tool: '2.0.0'\ncomposer_plugins:\n  example/other-tool:\n    - example/excluded-plugin\n");
        $this->addPackage('example/versioned-tool', '1.12.0', '^1.0');
        $this->item('independent', "## Rules\n\nThis item can complete.", "dependencies:\n  development:\n    - example/independent-tool\n");
        $manifest = json_decode(file_get_contents($this->root.'/composer.json'), true);
        $this->process = function ($command) {
            self::assertSame(['composer', 'require', 'example/independent-tool:*@stable'], array_slice($command, 0, 3));
            $this->addPackage('example/independent-tool');

            return new ProcessResult(0, 'installed');
        };

        $report = $this->installer()->install($this->root, ['quality', 'independent']);

        self::assertFalse($report->successful());
        self::assertSame(['quality' => 'incompatible dependencies', 'independent' => 'installed'], $report->items);
        self::assertCount(1, $report->failures, 'Dependency reporting must retain the compatibility preflight failure.');
        self::assertFileExists($this->root.'/.ai/rules/laracanon-independent.md');
        self::assertFileDoesNotExist($this->root.'/phpstan.neon.dist');
        self::assertCount(1, $this->commands);
        $after = json_decode(file_get_contents($this->root.'/composer.json'), true);
        self::assertSame($manifest['config'] ?? [], $after['config'] ?? [], 'An incompatible item cannot add its plugin permission when another selected package installs.');
        self::assertSame($manifest['require'], $after['require']);
        self::assertSame($manifest['scripts'], $after['scripts']);
        self::assertArrayNotHasKey('example/other-tool', $after['require-dev']);
        self::assertSame(['laravel/boost', 'example/independent-tool'], $this->boostCalls[0][2]);
    }

    public function test_missing_minimum_versions_are_explained_during_a_dry_run_without_mutations(): void
    {
        $this->configurationItem('quality', ['phpstan.neon.dist' => "parameters:\n    level: 10\n"], "dependencies:\n  development:\n    - example/versioned-tool\nminimum_versions:\n  example/versioned-tool: '2.0.0'\n");
        $before = $this->snapshot();

        $report = $this->installer()->install($this->root, ['quality'], true);

        self::assertTrue($report->successful(), implode("\n", $report->failures));
        self::assertSame(['quality' => 'planned'], $report->items);
        self::assertStringContainsString('example/versioned-tool minimum 2.0.0 will be verified after dependency installation', implode("\n", $report->notes));
        self::assertSame([], $this->commands);
        self::assertSame($before, $this->snapshot());
    }

    #[DataProvider('resolvedMinimumVersions')]
    public function test_minimum_versions_are_rechecked_after_composer_before_configuration_writes(string $version, bool $compatible): void
    {
        $contents = "parameters:\n    level: 10\n";
        $this->configurationItem('quality', ['phpstan.neon.dist' => $contents], "dependencies:\n  development:\n    - example/versioned-tool\nminimum_versions:\n  example/versioned-tool: '2.0.0'\n");
        $this->item('independent', "## Rules\n\nThis item can complete.");
        $this->process = function ($command) use ($version) {
            self::assertSame('example/versioned-tool:*@stable', $command[2]);
            $this->addPackage('example/versioned-tool', $version);

            return new ProcessResult(0, 'installed');
        };

        $report = $this->installer()->install($this->root, ['quality', 'independent']);

        self::assertSame($compatible, $report->successful(), implode("\n", $report->failures));
        self::assertSame($compatible ? 'installed' : 'incompatible dependencies', $report->items['quality']);
        self::assertSame('installed', $report->items['independent']);
        self::assertFileExists($this->root.'/.ai/rules/laracanon-independent.md');
        if ($compatible) {
            self::assertSame($contents, file_get_contents($this->root.'/phpstan.neon.dist'));
        } else {
            self::assertFileDoesNotExist($this->root.'/phpstan.neon.dist');
            self::assertStringContainsString($version, $report->failures[0]);
        }
        self::assertCount(1, $this->commands);
    }

    public static function resolvedMinimumVersions(): array
    {
        return [['2.1.0', true], ['v2.2.0', true], ['1.9.0', false], ['dev-main', false]];
    }

    private function installer(): Installer
    {
        $runner = new class($this) implements ProcessRunner
        {
            public function __construct(private InstallerTest $test) {}

            public function run(array $command, string $cwd): ProcessResult
            {
                return $this->test->runProcess($command);
            }
        };
        $boost = new class($runner, $this) extends BoostIntegration
        {
            public function __construct(ProcessRunner $runner, private InstallerTest $test)
            {
                parent::__construct($runner);
            }

            public function packageSkillNames(string $projectPath): array
            {
                return $this->test->skillNames();
            }

            public function refresh(string $projectPath, bool $dryRun = false, array $selectedPackages = []): array
            {
                return $this->test->refreshBoost($projectPath, $dryRun, $selectedPackages);
            }
        };

        return new Installer(new ItemCatalog($this->items), new ComposerDependencies($runner), $boost);
    }

    public function runProcess(array $command): ProcessResult
    {
        $this->commands[] = $command;

        return $this->process !== null ? ($this->process)($command) : new ProcessResult(1, 'Unexpected external process');
    }

    public function skillNames(): array
    {
        return $this->packageSkills;
    }

    public function refreshBoost(string $project, bool $dryRun, array $selected): array
    {
        $this->boostCalls[] = [$project, $dryRun, $selected];

        return ['resources' => ['rules index regenerated'], 'failures' => $this->boostFailures, 'conflicts' => $this->boostConflicts, 'notes' => []];
    }

    private function item(string $name, string $body, string $extra = ''): void
    {
        file_put_contents($this->items.'/'.$name.'.md', "---\nname: {$name}\ndescription: A test item.\npaths:\n  - app/**/*.php\n{$extra}---\n\n{$body}\n");
    }

    /** @param array<string, string> $files */
    private function configurationItem(string $name, array $files, string $extra = ''): void
    {
        $body = "## Files\n";
        foreach ($files as $path => $contents) {
            $body .= "\n### {$path}\n\n```php\n{$contents}```\n";
        }
        $this->item($name, $body, $extra);
    }

    private function json(string $path, array $value): void
    {
        file_put_contents($this->root.'/'.$path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function configurePlugin(array $command): ProcessResult
    {
        self::assertSame(['composer', 'config', '--no-plugins', '--no-scripts', '--no-interaction'], array_slice($command, 0, 5));
        $manifest = json_decode(file_get_contents($this->root.'/composer.json'), true);
        if ($command[5] === 'allow-plugins') {
            self::assertCount(6, $command);

            return new ProcessResult(0, json_encode($manifest['config']['allow-plugins'] ?? new \stdClass));
        }
        self::assertSame('true', $command[6]);
        self::assertStringStartsWith('allow-plugins.', $command[5]);
        $plugin = substr($command[5], strlen('allow-plugins.'));
        $manifest['config']['allow-plugins'][$plugin] = true;
        $this->json('composer.json', $manifest);

        return new ProcessResult(0, 'Configured the declared named plugin permission.');
    }

    private function addPackage(string $name, string $version = '1.0.0', string $constraint = '*@stable'): void
    {
        $composer = json_decode(file_get_contents($this->root.'/composer.json'), true);
        $composer['require-dev'][$name] = $constraint;
        $this->json('composer.json', $composer);
        $installed = json_decode(file_get_contents($this->root.'/vendor/composer/installed.json'), true);
        $installed['packages'][] = ['name' => $name, 'version' => $version];
        $this->json('vendor/composer/installed.json', $installed);
        $this->json('composer.lock', ['packages' => $installed['packages'], 'packages-dev' => []]);
    }

    private function removeInstalledPackage(string $name): void
    {
        $installed = json_decode(file_get_contents($this->root.'/vendor/composer/installed.json'), true);
        $installed['packages'] = array_values(array_filter($installed['packages'], fn ($package) => $package['name'] !== $name));
        $this->json('vendor/composer/installed.json', $installed);
    }

    private function snapshot(): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[$file->getPathname()] = $file->isLink() ? 'symlink:'.readlink($file->getPathname()) : file_get_contents($file->getPathname());
        }
        ksort($files);

        return $files;
    }
}
