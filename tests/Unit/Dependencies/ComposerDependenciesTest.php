<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Tests\Unit\Dependencies;

use Closure;
use Isaachatilima\Laracanon\Dependencies\ComposerDependencies;
use Isaachatilima\Laracanon\Dependencies\ComposerState;
use Isaachatilima\Laracanon\Items\Dependency;
use Isaachatilima\Laracanon\Support\ProcessResult;
use Isaachatilima\Laracanon\Support\ProcessRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ComposerDependenciesTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir().'/laracanon-dependencies-'.bin2hex(random_bytes(8));
        mkdir($this->project, 0777, true);
        $this->write('composer.json', [
            'name' => 'example/application',
            'require' => ['php' => '^8.2', 'laravel/framework' => '^12.0'],
            'require-dev' => [],
            'minimum-stability' => 'dev',
            'scripts' => ['post-update-cmd' => ['@php artisan package:discover']],
        ]);
        $this->write('composer.lock', [
            'packages' => [['name' => 'laravel/framework', 'version' => 'v12.1.0']],
            'packages-dev' => [],
        ]);
        $this->write('vendor/composer/installed.json', [
            'packages' => [['name' => 'laravel/framework', 'version' => 'v12.1.0']],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->project, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->project);
    }

    public function test_new_runtime_and_development_dependencies_use_correct_sections_and_stable_resolution(): void
    {
        $runner = $this->installingRunner();
        $report = (new ComposerDependencies($runner))->install($this->project, [
            new Dependency('example/runtime', 'runtime'),
            new Dependency('example/tool', 'development'),
        ]);

        self::assertSame([], $report['failures']);
        self::assertSame(['example/runtime', 'example/tool'], $report['available']);
        self::assertSame(['composer', 'require', 'example/runtime:*@stable', '--prefer-stable', '--no-interaction', '--no-progress'], $runner->commands[0]);
        self::assertContains('--dev', $runner->commands[1]);
        self::assertNotContains('--dev', $runner->commands[0]);
        self::assertNotContains('-W', $runner->commands[0]);
        self::assertNotContains('--ignore-platform-reqs', $runner->commands[0]);
        self::assertSame(['@php artisan package:discover'], ComposerState::inspect($this->project)->manifest['scripts']['post-update-cmd']);
    }

    public function test_existing_dependency_preserves_constraint_and_installed_version_without_composer_call(): void
    {
        $this->addPackage('example/existing', 'v2.1.3', 'require', '^2.0 || ^3.0');
        $runner = $this->installingRunner();

        $report = (new ComposerDependencies($runner))->install($this->project, [new Dependency('example/existing', 'runtime')]);

        self::assertSame([], $runner->commands);
        self::assertSame([], $report['changes']);
        self::assertSame(['example/existing'], $report['available']);
        self::assertStringContainsString('^2.0 || ^3.0', $report['notes'][0]);
    }

    public function test_development_item_can_reuse_an_existing_runtime_requirement(): void
    {
        $this->addPackage('example/existing', '2.1.3', 'require', '^2.0');
        $runner = $this->installingRunner();

        $report = (new ComposerDependencies($runner))->install($this->project, [new Dependency('example/existing', 'development')]);

        self::assertSame(['example/existing'], $report['available']);
        self::assertSame([], $runner->commands);
        self::assertSame('require', ComposerState::inspect($this->project)->section('example/existing'));
    }

    public function test_runtime_item_does_not_silently_move_existing_development_requirement(): void
    {
        $this->addPackage('example/existing', '2.1.3', 'require-dev', '^2.0');
        $runner = $this->installingRunner();

        $report = (new ComposerDependencies($runner))->install($this->project, [new Dependency('example/existing', 'runtime')]);

        self::assertSame([], $report['available']);
        self::assertCount(1, $report['failures']);
        self::assertStringContainsString('require-dev', $report['failures'][0]);
        self::assertSame([], $runner->commands);
        self::assertSame('require-dev', ComposerState::inspect($this->project)->section('example/existing'));
    }

    public function test_shared_dependency_is_deduplicated_and_runtime_takes_precedence(): void
    {
        $runner = $this->installingRunner();

        $report = (new ComposerDependencies($runner))->install($this->project, [
            new Dependency('example/shared', 'development'),
            new Dependency('example/shared', 'runtime'),
            new Dependency('example/shared', 'development'),
        ]);

        self::assertCount(1, $runner->commands);
        self::assertSame(['example/shared'], $report['available']);
        self::assertNotContains('--dev', $runner->commands[0]);
    }

    public function test_transitive_installed_dependency_is_promoted_without_changing_version(): void
    {
        $this->addPackage('example/transitive', 'v1.6.4');
        $runner = $this->installingRunner();

        $report = (new ComposerDependencies($runner))->install($this->project, [new Dependency('example/transitive', 'runtime')]);

        self::assertSame([], $report['failures']);
        self::assertSame('example/transitive:v1.6.4', $runner->commands[0][2]);
        self::assertSame('v1.6.4', ComposerState::inspect($this->project)->installed['example/transitive']);
        self::assertSame('require', ComposerState::inspect($this->project)->section('example/transitive'));
    }

    public function test_lock_only_transitive_dependency_is_promoted_at_locked_version(): void
    {
        $this->addPackage('example/transitive', 'v1.6.4', null, null, false);
        $runner = $this->installingRunner();

        $report = (new ComposerDependencies($runner))->install($this->project, [new Dependency('example/transitive', 'development')]);

        self::assertSame([], $report['failures']);
        self::assertSame('example/transitive:v1.6.4', $runner->commands[0][2]);
        self::assertSame('require-dev', ComposerState::inspect($this->project)->section('example/transitive'));
    }

    public function test_existing_transitive_development_branch_is_pinned_to_its_commit(): void
    {
        $this->addPackage('example/transitive', 'dev-main', null, null, true, 'abc123');
        $runner = $this->installingRunner();

        $report = (new ComposerDependencies($runner))->install($this->project, [new Dependency('example/transitive', 'runtime')]);

        self::assertSame([], $report['failures']);
        self::assertSame('example/transitive:dev-main#abc123', $runner->commands[0][2]);
        self::assertSame('abc123', ComposerState::inspect($this->project)->installedReferences['example/transitive']);
    }

    public function test_existing_transitive_development_branch_without_commit_cannot_be_promoted_safely(): void
    {
        $this->addPackage('example/transitive', 'dev-main');
        $runner = $this->installingRunner();

        $report = (new ComposerDependencies($runner))->install($this->project, [new Dependency('example/transitive', 'runtime')]);

        self::assertSame([], $runner->commands);
        self::assertStringContainsString('no commit reference', $report['failures'][0]);
    }

    public function test_declared_but_not_installed_dependency_uses_lockfile_install(): void
    {
        $this->addPackage('example/declared', 'v2.2.0', 'require', '^2.0', false);
        $runner = $this->installingRunner();

        $report = (new ComposerDependencies($runner))->install($this->project, [new Dependency('example/declared', 'runtime')]);

        self::assertSame(['composer', 'install', '--no-interaction', '--no-progress'], $runner->commands[0]);
        self::assertSame([], $report['failures']);
        self::assertSame('^2.0', ComposerState::inspect($this->project)->manifest['require']['example/declared']);
    }

    public function test_declared_package_missing_from_lockfile_uses_scoped_update_and_preserves_constraint(): void
    {
        $manifest = $this->read('composer.json');
        $manifest['require']['example/declared'] = '^2.0 || ^3.0';
        $this->write('composer.json', $manifest);
        $runner = $this->installingRunner();

        $report = (new ComposerDependencies($runner))->install($this->project, [new Dependency('example/declared', 'runtime')]);

        self::assertSame(['composer', 'update', 'example/declared', '--prefer-stable', '--no-interaction', '--no-progress'], $runner->commands[0]);
        self::assertSame([], $report['failures']);
        self::assertSame('^2.0 || ^3.0', ComposerState::inspect($this->project)->manifest['require']['example/declared']);
    }

    public function test_repeat_installation_is_idempotent(): void
    {
        $runner = $this->installingRunner();
        $installer = new ComposerDependencies($runner);
        $dependencies = [new Dependency('example/one', 'runtime'), new Dependency('example/two', 'development')];
        $installer->install($this->project, $dependencies);
        $before = file_get_contents($this->project.'/composer.json');
        $repeat = $installer->install($this->project, $dependencies);

        self::assertCount(2, $runner->commands);
        self::assertSame([], $repeat['changes']);
        self::assertSame([], $repeat['failures']);
        self::assertSame(['example/one', 'example/two'], $repeat['available']);
        self::assertSame($before, file_get_contents($this->project.'/composer.json'));
    }

    public function test_many_new_requirements_use_one_complete_composer_cycle_per_section(): void
    {
        $runner = $this->installingRunner();
        $dependencies = [
            new Dependency('example/boost', 'development'),
            new Dependency('example/data', 'runtime'),
            new Dependency('example/insights', 'development'),
            new Dependency('example/phpstan', 'development'),
            new Dependency('example/larastan', 'development'),
            new Dependency('example/runtime-tool', 'runtime'),
        ];
        $installer = new ComposerDependencies($runner);

        $report = $installer->install($this->project, $dependencies);

        self::assertSame([], $report['failures']);
        self::assertSame(array_map(static fn (Dependency $dependency): string => $dependency->package, $dependencies), $report['available']);
        self::assertCount(2, $runner->commands);
        self::assertSame(['composer', 'require', 'example/boost:*@stable', 'example/insights:*@stable', 'example/phpstan:*@stable', 'example/larastan:*@stable', '--prefer-stable', '--no-interaction', '--no-progress', '--dev'], $runner->commands[0]);
        self::assertSame(['composer', 'require', 'example/data:*@stable', 'example/runtime-tool:*@stable', '--prefer-stable', '--no-interaction', '--no-progress'], $runner->commands[1]);
        foreach ($runner->commands as $command) {
            self::assertNotContains('--no-audit', $command);
            self::assertNotContains('--no-scripts', $command);
            self::assertNotContains('--no-update', $command);
            self::assertNotContains('-W', $command);
            self::assertNotContains('--ignore-platform-reqs', $command);
        }
        $before = array_map(static fn (string $file): string => hash_file('sha256', $file), [
            $this->project.'/composer.json', $this->project.'/composer.lock', $this->project.'/vendor/composer/installed.json',
        ]);
        $repeat = $installer->install($this->project, $dependencies);
        self::assertCount(2, $runner->commands);
        self::assertSame([], $repeat['changes']);
        self::assertSame([], $repeat['failures']);
        self::assertSame($report['available'], $repeat['available']);
        self::assertSame($before, array_map(static fn (string $file): string => hash_file('sha256', $file), [
            $this->project.'/composer.json', $this->project.'/composer.lock', $this->project.'/vendor/composer/installed.json',
        ]));
    }

    public function test_batch_pins_installed_locked_and_branch_promotions(): void
    {
        $this->addPackage('example/installed', 'v2.1.3');
        $this->addPackage('example/locked', 'v3.4.5', null, null, false);
        $this->addPackage('example/branch', 'dev-main', null, null, true, 'abc123');
        $before = ComposerState::inspect($this->project);
        $runner = $this->installingRunner();

        $report = (new ComposerDependencies($runner))->install($this->project, [
            new Dependency('example/installed', 'development'),
            new Dependency('example/locked', 'development'),
            new Dependency('example/branch', 'development'),
        ]);

        self::assertSame([], $report['failures']);
        self::assertCount(1, $runner->commands);
        self::assertSame(['composer', 'require', 'example/installed:v2.1.3', 'example/locked:v3.4.5', 'example/branch:dev-main#abc123', '--prefer-stable', '--no-interaction', '--no-progress', '--dev'], $runner->commands[0]);
        $after = ComposerState::inspect($this->project);
        self::assertSame($before->locked, $after->locked);
        self::assertSame($before->lockedReferences, $after->lockedReferences);
        foreach ($report['available'] as $package) {
            self::assertSame('require-dev', $after->section($package));
        }
    }

    public function test_later_section_pins_a_dependency_installed_transitively_by_earlier_group(): void
    {
        $installing = $this->installingRunner();
        $runner = new DependencyTestRunner(function (array $command, string $cwd) use ($installing): ProcessResult {
            $result = $installing->run($command, $cwd);
            if (str_starts_with($command[2], 'example/runtime-one:')) {
                $this->addPackage('example/shared', 'v7.8.9');
            }

            return $result;
        });

        $report = (new ComposerDependencies($runner))->install($this->project, [
            new Dependency('example/runtime-one', 'runtime'),
            new Dependency('example/runtime-two', 'runtime'),
            new Dependency('example/shared', 'development'),
            new Dependency('example/dev-tool', 'development'),
        ]);

        self::assertSame([], $report['failures']);
        self::assertCount(2, $runner->commands);
        self::assertContains('example/shared:v7.8.9', $runner->commands[1]);
        self::assertSame('v7.8.9', ComposerState::inspect($this->project)->installed['example/shared']);
    }

    public function test_rolled_back_batch_resolution_failure_recovers_independent_dependencies(): void
    {
        $installing = $this->installingRunner();
        $diagnostic = 'Your requirements could not be resolved to an installable set of packages. The incompatible dependency requires PHP 99.';
        $runner = new DependencyTestRunner(function (array $command, string $cwd) use ($installing, $diagnostic): ProcessResult {
            if (in_array('example/incompatible:*@stable', $command, true)) {
                return new ProcessResult(2, $diagnostic);
            }

            return $installing->run($command, $cwd);
        });

        $report = (new ComposerDependencies($runner))->install($this->project, [
            new Dependency('example/incompatible', 'development'),
            new Dependency('example/working', 'development'),
        ]);

        self::assertCount(3, $runner->commands);
        self::assertSame(['example/incompatible: Composer exited 2. '.$diagnostic], $report['failures']);
        self::assertSame(['example/working'], $report['available']);
        self::assertCount(1, $report['changes']);
        self::assertStringContainsString('unchanged Composer files verified', $report['notes'][0]);
        self::assertNull(ComposerState::inspect($this->project)->section('example/incompatible'));
    }

    public function test_failed_batch_with_partial_installation_reports_every_package_without_retrying(): void
    {
        $installing = $this->installingRunner();
        $diagnostic = 'Your requirements could not be resolved to an installable set of packages.';
        $runner = new DependencyTestRunner(function (array $command, string $cwd) use ($installing, $diagnostic): ProcessResult {
            $installing->run($command, $cwd);

            return new ProcessResult(2, $diagnostic);
        });

        $report = (new ComposerDependencies($runner))->install($this->project, [
            new Dependency('example/one', 'development'),
            new Dependency('example/two', 'development'),
        ]);

        self::assertCount(1, $runner->commands);
        self::assertSame([], $report['available']);
        self::assertCount(2, $report['failures']);
        self::assertCount(2, $report['changes']);
        self::assertCount(2, $report['notes']);
        self::assertStringContainsString('example/one is installed', $report['notes'][0]);
        self::assertStringContainsString('example/two is installed', $report['notes'][1]);
    }

    public function test_resolver_error_with_only_lock_metadata_change_does_not_retry(): void
    {
        $runner = new DependencyTestRunner(function (): ProcessResult {
            $lock = $this->read('composer.lock');
            $lock['content-hash'] = 'changed';
            $this->write('composer.lock', $lock);

            return new ProcessResult(2, 'Your requirements could not be resolved to an installable set of packages.');
        });

        $report = (new ComposerDependencies($runner))->install($this->project, [
            new Dependency('example/one', 'development'),
            new Dependency('example/two', 'development'),
        ]);

        self::assertCount(1, $runner->commands);
        self::assertCount(2, $report['failures']);
        self::assertSame([], $report['available']);
        self::assertSame([], $report['notes']);
    }

    public function test_script_error_cannot_trigger_resolver_fallback_even_with_unchanged_composer_files(): void
    {
        $diagnostic = 'Your requirements could not be resolved to an installable set of packages.'
            ."\nScript @php artisan custom handling the pre-update-cmd event returned with error code 2";
        $runner = new DependencyTestRunner(static fn (): ProcessResult => new ProcessResult(2, $diagnostic));

        $report = (new ComposerDependencies($runner))->install($this->project, [
            new Dependency('example/one', 'development'),
            new Dependency('example/two', 'development'),
        ]);

        self::assertCount(1, $runner->commands);
        self::assertSame(['example/one: Composer exited 2. '.$diagnostic, 'example/two: Composer exited 2. '.$diagnostic], $report['failures']);
        self::assertSame([], $report['available']);
        self::assertSame([], $report['notes']);
    }

    public function test_unknown_batch_errors_do_not_retry_unchanged_project(): void
    {
        $runner = new DependencyTestRunner(static fn (): ProcessResult => new ProcessResult(2, 'Network timeout'));

        $report = (new ComposerDependencies($runner))->install($this->project, [
            new Dependency('example/one', 'development'),
            new Dependency('example/two', 'development'),
        ]);

        self::assertCount(1, $runner->commands);
        self::assertSame(['example/one: Composer exited 2. Network timeout', 'example/two: Composer exited 2. Network timeout'], $report['failures']);
        self::assertSame([], $report['available']);
    }

    public function test_batch_integrity_failure_stops_later_section_and_discards_all_availability(): void
    {
        $installing = $this->installingRunner();
        $runner = new DependencyTestRunner(function (array $command, string $cwd) use ($installing): ProcessResult {
            $installing->run($command, $cwd);
            $this->addPackage('laravel/framework', 'v13.0.0', 'require', '^13.0');

            return new ProcessResult(0, 'A project script changed dependencies');
        });

        $report = (new ComposerDependencies($runner))->install($this->project, [
            new Dependency('example/one', 'development'),
            new Dependency('example/two', 'development'),
            new Dependency('example/runtime', 'runtime'),
        ]);

        self::assertCount(1, $runner->commands);
        self::assertCount(4, $report['failures']);
        self::assertSame([], $report['available']);
        self::assertStringContainsString('example/runtime: stopped', $report['failures'][3]);
    }

    public function test_blocked_recovery_of_a_later_group_member_stops_the_queued_batch(): void
    {
        $installing = $this->installingRunner();
        $diagnostic = 'example/plugin contains a Composer plugin which is blocked by your allow-plugins config.';
        $runner = new DependencyTestRunner(function (array $command, string $cwd) use ($installing, $diagnostic): ProcessResult {
            if ($command[1] === 'install') {
                return new ProcessResult(1, $diagnostic);
            }
            $result = $installing->run($command, $cwd);
            $this->addPackage('example/recovery', 'v1.0.0', 'require-dev', '^1.0', false);

            return $result;
        });

        $report = (new ComposerDependencies($runner))->install($this->project, [
            new Dependency('example/runtime', 'runtime'),
            new Dependency('example/queued-one', 'development'),
            new Dependency('example/queued-two', 'development'),
            new Dependency('example/recovery', 'development'),
        ]);

        self::assertSame(['require', 'install'], array_column($runner->commands, 1));
        self::assertSame(['example/runtime'], $report['available']);
        self::assertCount(3, $report['failures']);
        self::assertStringContainsString('example/recovery: Composer exited 1', $report['failures'][0]);
        self::assertStringContainsString('example/queued-one: Composer operation skipped while plugin', $report['failures'][1]);
        self::assertStringContainsString('example/queued-two: Composer operation skipped while plugin', $report['failures'][2]);
        self::assertNull(ComposerState::inspect($this->project)->section('example/queued-one'));
        self::assertNull(ComposerState::inspect($this->project)->section('example/queued-two'));
    }

    public function test_integrity_failure_in_later_group_member_stops_pending_requirements(): void
    {
        $installing = $this->installingRunner();
        $runner = new DependencyTestRunner(function (array $command, string $cwd) use ($installing): ProcessResult {
            $result = $installing->run($command, $cwd);
            if ($command[1] === 'install') {
                $this->addPackage('example/runtime', 'v4.0.0', 'require', '^4.0');
            } else {
                $this->addPackage('example/recovery', 'v1.0.0', 'require-dev', '^1.0', false);
            }

            return $result;
        });

        $report = (new ComposerDependencies($runner))->install($this->project, [
            new Dependency('example/runtime', 'runtime'),
            new Dependency('example/queued-one', 'development'),
            new Dependency('example/queued-two', 'development'),
            new Dependency('example/recovery', 'development'),
        ]);

        self::assertSame(['require', 'install'], array_column($runner->commands, 1));
        self::assertSame([], $report['available']);
        self::assertCount(5, $report['failures']);
        self::assertStringContainsString('example/queued-one: stopped', $report['failures'][3]);
        self::assertStringContainsString('example/queued-two: stopped', $report['failures'][4]);
    }

    /** @param list<string> $packages */
    #[DataProvider('runtimePlacementCases')]
    public function test_runtime_dependencies_moved_by_scripts_to_development_are_not_available(array $packages): void
    {
        $installing = $this->installingRunner();
        $runner = new DependencyTestRunner(function (array $command, string $cwd) use ($installing, $packages): ProcessResult {
            $result = $installing->run($command, $cwd);
            $manifest = $this->read('composer.json');
            foreach ($packages as $package) {
                $manifest['require-dev'][$package] = $manifest['require'][$package];
                unset($manifest['require'][$package]);
            }
            $this->write('composer.json', $manifest);

            return $result;
        });

        $report = (new ComposerDependencies($runner))->install($this->project, array_map(static fn (string $package): Dependency => new Dependency($package, 'runtime'), $packages));

        self::assertCount(1, $runner->commands);
        self::assertSame([], $report['available']);
        self::assertCount(count($packages), $report['failures']);
        foreach ($packages as $index => $package) {
            self::assertStringContainsString($package.': Composer completed, but this runtime dependency is in require-dev', $report['failures'][$index]);
            self::assertSame('require-dev', ComposerState::inspect($this->project)->section($package));
        }
    }

    /** @return array<string, array{list<string>}> */
    public static function runtimePlacementCases(): array
    {
        return ['single' => [['example/one']], 'batch' => [['example/one', 'example/two']]];
    }

    public function test_multiple_dependency_dry_run_keeps_individual_plans_and_never_runs_composer(): void
    {
        $runner = $this->installingRunner();
        $before = file_get_contents($this->project.'/composer.json');

        $report = (new ComposerDependencies($runner))->install($this->project, [
            new Dependency('example/one', 'development'),
            new Dependency('example/two', 'development'),
            new Dependency('example/runtime', 'runtime'),
        ], true);

        self::assertSame([], $runner->commands);
        self::assertSame($before, file_get_contents($this->project.'/composer.json'));
        self::assertCount(3, $report['changes']);
        self::assertStringContainsString('Would add example/one to require-dev', $report['changes'][0]);
        self::assertStringContainsString('Would add example/two to require-dev', $report['changes'][1]);
        self::assertStringContainsString('Would add example/runtime to require', $report['changes'][2]);
        self::assertSame([], $report['available']);
    }

    public function test_dry_run_does_not_write_files_or_call_composer(): void
    {
        $runner = $this->installingRunner();
        $before = file_get_contents($this->project.'/composer.json');

        $report = (new ComposerDependencies($runner))->install($this->project, [new Dependency('example/new', 'development')], true);

        self::assertSame([], $runner->commands);
        self::assertSame($before, file_get_contents($this->project.'/composer.json'));
        self::assertSame([], $report['available']);
        self::assertCount(1, $report['changes']);
        self::assertStringContainsString('Would add example/new to require-dev', $report['changes'][0]);
        self::assertStringContainsString('Compatibility', $report['notes'][0]);
    }

    public function test_dependency_failure_reports_output_and_continues_with_other_packages(): void
    {
        $successful = $this->installingRunner();
        $runner = new DependencyTestRunner(function (array $command, string $cwd) use ($successful): ProcessResult {
            if (str_starts_with($command[2], 'example/incompatible:')) {
                return new ProcessResult(2, 'Your PHP version does not satisfy the package PHP requirement.');
            }

            return $successful->run($command, $cwd);
        });

        $report = (new ComposerDependencies($runner))->install($this->project, [
            new Dependency('example/incompatible', 'runtime'),
            new Dependency('example/working', 'development'),
        ]);

        self::assertCount(2, $runner->commands);
        self::assertCount(1, $report['failures']);
        self::assertStringContainsString('PHP requirement', $report['failures'][0]);
        self::assertSame(['example/working'], $report['available']);
        self::assertCount(1, $report['changes']);
    }

    public function test_failed_composer_scripts_are_reported_even_when_package_was_installed(): void
    {
        $successful = $this->installingRunner();
        $runner = new DependencyTestRunner(function (array $command, string $cwd) use ($successful): ProcessResult {
            $successful->run($command, $cwd);

            return new ProcessResult(1, 'post-update-cmd failed');
        });

        $report = (new ComposerDependencies($runner))->install($this->project, [new Dependency('example/partial', 'runtime')]);

        self::assertSame([], $report['available']);
        self::assertCount(1, $report['failures']);
        self::assertCount(1, $report['changes']);
        self::assertStringContainsString('installed at', $report['notes'][0]);
    }

    public function test_blocked_plugin_reports_recovery_and_skips_further_composer_operations(): void
    {
        $this->addPackage('example/existing', 'v2.1.3', 'require-dev', '^2.0');
        $manifest = $this->read('composer.json');
        $manifest['config']['allow-plugins'] = ['example/denied-plugin' => false, 'example/trusted-plugin' => true];
        $this->write('composer.json', $manifest);
        $successful = $this->installingRunner();
        $diagnostic = "\033[31mdealerdirect/phpcodesniffer-composer-installer contains a Composer plugin\n"
            ."which is blocked by your allow-plugins config.\033[0m\n"
            .'See https://getcomposer.org/allow-plugins';
        $runner = new DependencyTestRunner(function (array $command, string $cwd) use ($successful, $diagnostic): ProcessResult {
            if (str_starts_with($command[2], 'example/blocked:')) {
                $this->addPackage('example/blocked', 'v1.0.0', 'require-dev', '*@stable', false);

                return new ProcessResult(1, $diagnostic);
            }

            return $successful->run($command, $cwd);
        });

        $report = (new ComposerDependencies($runner))->install($this->project, [
            new Dependency('example/working', 'runtime'),
            new Dependency('example/blocked', 'development'),
            new Dependency('example/remaining', 'development'),
            new Dependency('example/existing', 'development'),
        ]);

        self::assertCount(2, $runner->commands);
        self::assertSame(['example/working', 'example/existing'], $report['available']);
        self::assertCount(2, $report['failures']);
        self::assertStringContainsString($diagnostic, $report['failures'][0]);
        self::assertStringContainsString('example/remaining', $report['failures'][1]);
        self::assertStringContainsString($diagnostic, $report['failures'][1]);
        $notes = implode("\n", $report['notes']);
        self::assertStringContainsString('composer config --no-plugins allow-plugins.dealerdirect/phpcodesniffer-composer-installer true', $notes);
        self::assertStringContainsString('composer install', $notes);
        self::assertStringContainsString('canon:install', $notes);
        $after = ComposerState::inspect($this->project);
        self::assertSame($manifest['config'], $after->manifest['config']);
        self::assertSame($manifest['require']['laravel/framework'], $after->manifest['require']['laravel/framework']);
        self::assertSame($manifest['scripts'], $after->manifest['scripts']);
        self::assertSame('require-dev', $after->section('example/blocked'));
        self::assertSame('v1.0.0', $after->locked['example/blocked']);
        self::assertArrayNotHasKey('example/blocked', $after->installed);
        self::assertNull($after->section('example/remaining'));
        self::assertStringContainsString('requirement changed before Composer failed', implode("\n", $report['changes']));
    }

    public function test_other_composer_errors_mentioning_plugin_policy_do_not_block_independent_packages(): void
    {
        $successful = $this->installingRunner();
        $diagnostic = 'Your PHP version does not satisfy the package requirement; config.allow-plugins is unchanged.';
        $runner = new DependencyTestRunner(function (array $command, string $cwd) use ($successful, $diagnostic): ProcessResult {
            return str_starts_with($command[2], 'example/incompatible:')
                ? new ProcessResult(2, $diagnostic)
                : $successful->run($command, $cwd);
        });

        $report = (new ComposerDependencies($runner))->install($this->project, [
            new Dependency('example/incompatible', 'runtime'),
            new Dependency('example/working', 'development'),
        ]);

        self::assertCount(2, $runner->commands);
        self::assertSame(['example/working'], $report['available']);
        self::assertSame(['example/incompatible: Composer exited 2. '.$diagnostic], $report['failures']);
        self::assertSame([], $report['notes']);
    }

    public function test_actual_composer_box_wrapping_inside_which_is_recognized_without_rewriting_the_diagnostic(): void
    {
        $diagnostic = "In PluginManager.php line 821:\n"
            ."                                                                               \n"
            ."  dealerdirect/phpcodesniffer-composer-installer contains a Composer plugin w  \n"
            ."  hich is blocked by your allow-plugins config. You may add it to the list if  \n"
            ."   you consider it safe.                                                       \n"
            ."  You can run \"composer config --no-plugins allow-plugins.dealerdirect/phpcod  \n"
            ."  esniffer-composer-installer [true|false]\" to enable it (true) or disable it  \n"
            ."   explicitly and suppress this exception (false)                              \n"
            .'  See https://getcomposer.org/allow-plugins                                    ';
        $runner = new DependencyTestRunner(static fn (): ProcessResult => new ProcessResult(1, $diagnostic));

        $report = (new ComposerDependencies($runner))->install($this->project, [
            new Dependency('example/blocked', 'development'),
            new Dependency('example/remaining', 'development'),
        ]);

        self::assertCount(1, $runner->commands);
        self::assertSame('example/blocked: Composer exited 1. '.trim($diagnostic), $report['failures'][0]);
        self::assertSame('example/remaining: Composer exited 1. '.trim($diagnostic), $report['failures'][1]);
        self::assertStringContainsString('allow-plugins.dealerdirect/phpcodesniffer-composer-installer true', $report['notes'][0]);
        self::assertSame([], $report['changes']);
        self::assertSame([], $report['available']);
    }

    public function test_plain_spaces_inside_error_words_are_not_treated_as_terminal_wrapping(): void
    {
        $diagnostic = 'example/plugin contains a Composer plugin w hich is blocked by your allow-plugins config.';
        $successful = $this->installingRunner();
        $runner = new DependencyTestRunner(function (array $command, string $cwd) use ($diagnostic, $successful): ProcessResult {
            return str_starts_with($command[2], 'example/first:')
                ? new ProcessResult(1, $diagnostic)
                : $successful->run($command, $cwd);
        });

        $report = (new ComposerDependencies($runner))->install($this->project, [
            new Dependency('example/first', 'development'),
            new Dependency('example/second', 'runtime'),
        ]);

        self::assertCount(2, $runner->commands);
        self::assertSame(['example/first: Composer exited 1. '.$diagnostic], $report['failures']);
        self::assertSame(['example/second'], $report['available']);
        self::assertSame([], $report['notes']);
    }

    public function test_blocked_global_plugin_does_not_recommend_a_project_permission(): void
    {
        $before = file_get_contents($this->project.'/composer.json');
        $diagnostic = 'example/global-plugin (installed globally) contains a Composer plugin which is blocked by your allow-plugins config.';
        $runner = new DependencyTestRunner(static fn (): ProcessResult => new ProcessResult(1, $diagnostic));

        $report = (new ComposerDependencies($runner))->install($this->project, [
            new Dependency('example/first', 'development'),
            new Dependency('example/second', 'development'),
        ]);

        self::assertCount(1, $runner->commands);
        self::assertCount(2, $report['failures']);
        self::assertSame(['example/first: Composer exited 1. '.$diagnostic], array_slice($report['failures'], 0, 1));
        self::assertStringContainsString('example/global-plugin', $report['notes'][0]);
        self::assertStringContainsString('project permission cannot resolve a blocked global plugin', $report['notes'][0]);
        self::assertStringNotContainsString('composer config', $report['notes'][0]);
        self::assertSame([], $report['changes']);
        self::assertSame($before, file_get_contents($this->project.'/composer.json'));
    }

    public function test_an_explicitly_disabled_plugin_is_not_enabled_during_dependency_installation(): void
    {
        $manifest = $this->read('composer.json');
        $manifest['config']['allow-plugins']['dealerdirect/phpcodesniffer-composer-installer'] = false;
        $this->write('composer.json', $manifest);
        $runner = $this->installingRunner();

        $report = (new ComposerDependencies($runner))->install($this->project, [new Dependency('example/tool', 'development')]);

        self::assertCount(1, $runner->commands);
        self::assertSame([], $report['failures']);
        self::assertSame(['example/tool'], $report['available']);
        self::assertSame($manifest['config'], ComposerState::inspect($this->project)->manifest['config']);
    }

    public function test_selected_item_permission_is_prepared_before_recovering_a_locked_dependency(): void
    {
        $this->addPackage('nunomaduro/phpinsights', 'v2.15.0', 'require-dev', '*@stable', false);
        $before = ComposerState::inspect($this->project);
        $installing = $this->installingRunner();
        $runner = new DependencyTestRunner(function (array $command, string $cwd) use ($installing): ProcessResult {
            if ($command[1] === 'config') {
                if (count($command) === 6) {
                    return new ProcessResult(0, '{}');
                }
                $manifest = $this->read('composer.json');
                $manifest['config']['allow-plugins']['dealerdirect/phpcodesniffer-composer-installer'] = true;
                $this->write('composer.json', $manifest);

                return new ProcessResult(0, '');
            }
            self::assertTrue($this->read('composer.json')['config']['allow-plugins']['dealerdirect/phpcodesniffer-composer-installer']);

            return $installing->run($command, $cwd);
        });

        $report = (new ComposerDependencies($runner))->install($this->project, [
            new Dependency('nunomaduro/phpinsights', 'development'),
            new Dependency('phpstan/phpstan', 'development'),
        ], composerPlugins: ['nunomaduro/phpinsights' => ['dealerdirect/phpcodesniffer-composer-installer']]);

        self::assertSame([], $report['failures']);
        self::assertSame(['config', 'config', 'install', 'require'], array_column($runner->commands, 1));
        self::assertSame(['nunomaduro/phpinsights', 'phpstan/phpstan'], $report['available']);
        $after = ComposerState::inspect($this->project);
        self::assertSame('*@stable', $after->manifest['require-dev']['nunomaduro/phpinsights']);
        self::assertSame($before->locked['nunomaduro/phpinsights'], $after->installed['nunomaduro/phpinsights']);
        self::assertSame($before->manifest['scripts'], $after->manifest['scripts']);
    }

    public function test_plugin_configuration_failure_stops_composer_mutations_but_keeps_existing_dependencies_available(): void
    {
        $this->addPackage('example/existing', 'v2.1.3', 'require-dev', '^2.0');
        $before = file_get_contents($this->project.'/composer.json');
        $runner = new DependencyTestRunner(static fn (array $command): ProcessResult => count($command) === 6
            ? new ProcessResult(0, '{}')
            : new ProcessResult(1, 'Cannot write plugin permission'));

        $report = (new ComposerDependencies($runner))->install($this->project, [
            new Dependency('example/first', 'development'),
            new Dependency('example/second', 'runtime'),
            new Dependency('example/existing', 'development'),
        ], composerPlugins: ['example/first' => ['example/plugin']]);

        self::assertSame(['config', 'config'], array_column($runner->commands, 1));
        self::assertSame(['example/existing'], $report['available']);
        self::assertCount(3, $report['failures']);
        self::assertStringContainsString('Cannot write plugin permission', $report['failures'][0]);
        self::assertStringContainsString('example/first', $report['failures'][1]);
        self::assertStringContainsString('example/second', $report['failures'][2]);
        self::assertSame($before, file_get_contents($this->project.'/composer.json'));
    }

    public function test_success_exit_code_is_verified_against_actual_declared_and_installed_packages(): void
    {
        $runner = new DependencyTestRunner(static fn (): ProcessResult => new ProcessResult(0, 'Nothing happened'));

        $report = (new ComposerDependencies($runner))->install($this->project, [new Dependency('example/new', 'runtime')]);

        self::assertSame([], $report['available']);
        self::assertStringContainsString('not both declared and installed', $report['failures'][0]);
    }

    public function test_missing_lockfile_with_installed_packages_is_reported_before_mutation(): void
    {
        unlink($this->project.'/composer.lock');
        $runner = $this->installingRunner();

        $report = (new ComposerDependencies($runner))->install($this->project, [new Dependency('example/new', 'runtime')]);

        self::assertSame([], $runner->commands);
        self::assertStringContainsString('cannot preserve their versions', $report['failures'][0]);
    }

    public function test_installed_version_differing_from_lock_is_rejected_before_mutation(): void
    {
        $this->write('vendor/composer/installed.json', ['packages' => [
            ['name' => 'laravel/framework', 'version' => 'v12.2.0'],
        ]]);
        $runner = $this->installingRunner();

        $report = (new ComposerDependencies($runner))->install($this->project, [new Dependency('example/new', 'runtime')]);

        self::assertSame([], $runner->commands);
        self::assertStringContainsString('differs from composer.lock', $report['failures'][0]);
        self::assertSame('v12.2.0', ComposerState::inspect($this->project)->installed['laravel/framework']);
    }

    public function test_installed_package_missing_from_lock_is_rejected_before_mutation(): void
    {
        $this->write('vendor/composer/installed.json', ['packages' => [
            ['name' => 'laravel/framework', 'version' => 'v12.1.0'],
            ['name' => 'example/unlocked', 'version' => 'v1.0.0'],
        ]]);
        $runner = $this->installingRunner();

        $report = (new ComposerDependencies($runner))->install($this->project, [new Dependency('example/new', 'runtime')]);

        self::assertSame([], $runner->commands);
        self::assertStringContainsString('example/unlocked', $report['failures'][0]);
        self::assertStringContainsString('differs from composer.lock', $report['failures'][0]);
    }

    public function test_installed_revision_differing_from_lock_is_rejected_before_mutation(): void
    {
        $this->addPackage('example/branch', 'dev-main', null, null, true, 'abc123');
        $installed = $this->read('vendor/composer/installed.json');
        $installed['packages'][1]['source']['reference'] = 'other456';
        $this->write('vendor/composer/installed.json', $installed);
        $runner = $this->installingRunner();

        $report = (new ComposerDependencies($runner))->install($this->project, [new Dependency('example/new', 'runtime')]);

        self::assertSame([], $runner->commands);
        self::assertStringContainsString('installed revision', $report['failures'][0]);
    }

    public function test_unexpected_commit_changes_are_detected_even_when_version_text_stays_the_same(): void
    {
        $this->addPackage('example/branch', 'dev-main', 'require', 'dev-main', true, 'abc123');
        $successful = $this->installingRunner();
        $runner = new DependencyTestRunner(function (array $command, string $cwd) use ($successful): ProcessResult {
            $successful->run($command, $cwd);
            $this->addPackage('example/branch', 'dev-main', 'require', 'dev-main', true, 'changed456');

            return new ProcessResult(0, 'Installed');
        });

        $report = (new ComposerDependencies($runner))->install($this->project, [new Dependency('example/new', 'runtime')]);

        self::assertSame([], $report['available']);
        self::assertCount(2, $report['failures']);
        self::assertStringContainsString('installed revision', $report['failures'][0]);
        self::assertStringContainsString('locked revision', $report['failures'][1]);
    }

    public function test_unexpected_existing_constraint_or_version_changes_are_not_reported_as_successful(): void
    {
        $successful = $this->installingRunner();
        $runner = new DependencyTestRunner(function (array $command, string $cwd) use ($successful): ProcessResult {
            $successful->run($command, $cwd);
            $this->addPackage('laravel/framework', 'v13.0.0', 'require', '^13.0');

            return new ProcessResult(0, 'A project script changed dependencies');
        });

        $report = (new ComposerDependencies($runner))->install($this->project, [new Dependency('example/new', 'runtime')]);

        self::assertSame([], $report['available']);
        self::assertCount(3, $report['failures']);
        self::assertStringContainsString('constraint', $report['failures'][0]);
        self::assertStringContainsString('installed version', $report['failures'][1]);
        self::assertStringContainsString('locked version', $report['failures'][2]);
    }

    public function test_later_dependency_cannot_change_a_package_installed_earlier_in_the_same_run(): void
    {
        $this->addPackage('example/three', 'v1.0.0', 'require', '^1.0', false);
        $successful = $this->installingRunner();
        $runner = new DependencyTestRunner(function (array $command, string $cwd) use ($successful): ProcessResult {
            $successful->run($command, $cwd);

            if (str_starts_with($command[2], 'example/two:')) {
                $this->addPackage('example/one', 'v4.0.0', 'require', '^4.0');
            }

            return new ProcessResult(0, 'Installed');
        });

        $report = (new ComposerDependencies($runner))->install($this->project, [
            new Dependency('example/one', 'runtime'),
            new Dependency('example/two', 'development'),
            new Dependency('example/three', 'runtime'),
        ]);

        self::assertSame([], $report['available']);
        self::assertCount(2, $runner->commands);
        self::assertCount(4, $report['failures']);
        self::assertStringContainsString('example/one', $report['failures'][0]);
        self::assertStringContainsString('stopped', $report['failures'][3]);
    }

    public function test_installed_state_supports_custom_vendor_directory_and_composer_one_format(): void
    {
        $manifest = $this->read('composer.json');
        $manifest['config']['vendor-dir'] = 'custom-vendor';
        $this->write('composer.json', $manifest);
        $this->write('custom-vendor/composer/installed.json', [['name' => 'example/package', 'version' => 'v1.0.0']]);

        $state = ComposerState::inspect($this->project);

        self::assertSame(['example/package' => 'v1.0.0'], $state->installed);
        self::assertSame(['laravel/framework' => 'v12.1.0'], $state->locked);
    }

    public function test_invalid_composer_manifest_produces_a_clear_failure(): void
    {
        file_put_contents($this->project.'/composer.json', '{ invalid json');
        $runner = $this->installingRunner();

        $report = (new ComposerDependencies($runner))->install($this->project, [new Dependency('example/new', 'runtime')]);

        self::assertSame([], $runner->commands);
        self::assertStringContainsString('Invalid JSON', $report['failures'][0]);
    }

    private function installingRunner(): DependencyTestRunner
    {
        return new DependencyTestRunner(function (array $command): ProcessResult {
            if ($command[1] === 'require') {
                foreach (array_slice($command, 2) as $argument) {
                    if (str_starts_with($argument, '--')) {
                        continue;
                    }
                    [$package, $constraint] = explode(':', $argument, 2);
                    $state = ComposerState::inspect($this->project);
                    $version = $state->installed[$package] ?? $state->locked[$package] ?? 'v3.2.1';
                    $reference = $state->installedReferences[$package] ?? $state->lockedReferences[$package] ?? null;
                    $section = in_array('--dev', $command, true) ? 'require-dev' : 'require';
                    $this->addPackage($package, $version, $section, $constraint, true, $reference);
                }
            } elseif ($command[1] === 'install') {
                $lock = $this->read('composer.lock');
                $this->write('vendor/composer/installed.json', ['packages' => array_merge($lock['packages'], $lock['packages-dev'])]);
            } elseif ($command[1] === 'update') {
                $this->addPackage($command[2], 'v2.2.0');
            }

            return new ProcessResult(0, 'Dependencies installed');
        });
    }

    private function addPackage(string $package, string $version, ?string $section = null, ?string $constraint = null, bool $installed = true, ?string $reference = null): void
    {
        if ($section !== null) {
            $manifest = $this->read('composer.json');
            $manifest[$section][$package] = $constraint;
            $this->write('composer.json', $manifest);
        }

        $lock = $this->read('composer.lock');

        foreach (['packages', 'packages-dev'] as $lockSection) {
            $lock[$lockSection] = array_values(array_filter($lock[$lockSection], static fn (array $entry): bool => $entry['name'] !== $package));
        }

        $entry = ['name' => $package, 'version' => $version];

        if ($reference !== null) {
            $entry['source']['reference'] = $reference;
        }

        $lock[$section === 'require-dev' ? 'packages-dev' : 'packages'][] = $entry;
        $this->write('composer.lock', $lock);

        if ($installed) {
            $data = $this->read('vendor/composer/installed.json');
            $data['packages'] = array_values(array_filter($data['packages'], static fn (array $entry): bool => $entry['name'] !== $package));
            $data['packages'][] = $entry;
            $this->write('vendor/composer/installed.json', $data);
        }
    }

    /** @param array<mixed> $data */
    private function write(string $path, array $data): void
    {
        $fullPath = $this->project.'/'.$path;

        if (! is_dir(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0777, true);
        }

        file_put_contents($fullPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    /** @return array<mixed> */
    private function read(string $path): array
    {
        return json_decode(file_get_contents($this->project.'/'.$path), true, 512, JSON_THROW_ON_ERROR);
    }
}

final class DependencyTestRunner implements ProcessRunner
{
    /** @var list<list<string>> */
    public array $commands = [];

    public function __construct(private readonly Closure $callback) {}

    public function run(array $command, string $cwd): ProcessResult
    {
        $this->commands[] = $command;

        return ($this->callback)($command, $cwd);
    }
}
