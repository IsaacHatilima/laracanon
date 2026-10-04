<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Tests\Integration\Dependencies;

use Isaachatilima\Laracanon\Dependencies\ComposerDependencies;
use Isaachatilima\Laracanon\Dependencies\ComposerState;
use Isaachatilima\Laracanon\Items\Dependency;
use Isaachatilima\Laracanon\Support\ProcessResult;
use Isaachatilima\Laracanon\Support\ProcessRunner;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/** Native resolution against fake local metapackages; no external packages or downloads. */
final class ComposerBatchInstallationTest extends TestCase
{
    private string $directory;

    private string $project;

    private OfflineComposerBatchRunner $runner;

    protected function setUp(): void
    {
        $composer = (new ExecutableFinder)->find('composer');
        if ($composer === null) {
            self::markTestSkipped('Native Composer is unavailable.');
        }
        $this->directory = sys_get_temp_dir().'/laracanon-native-batch-'.bin2hex(random_bytes(8));
        $this->project = $this->directory.'/application';
        foreach (['application', 'composer-home', 'composer-cache'] as $path) {
            mkdir($this->directory.'/'.$path, 0777, true);
        }
        $this->runner = new OfflineComposerBatchRunner($composer, [
            'COMPOSER_HOME' => $this->directory.'/composer-home',
            'COMPOSER_CACHE_DIR' => $this->directory.'/composer-cache',
            'COMPOSER_DISABLE_NETWORK' => '1',
            // The fake repository has no advisory endpoint; production flags remain untouched.
            'COMPOSER_NO_AUDIT' => '1',
            'COMPOSER' => false,
            'COMPOSER_AUTH' => false,
            'COMPOSER_VENDOR_DIR' => false,
            'COMPOSER_NO_DEV' => false,
            'COMPOSER_IGNORE_PLATFORM_REQ' => false,
            'COMPOSER_IGNORE_PLATFORM_REQS' => false,
            'COMPOSER_WITH_DEPENDENCIES' => false,
            'COMPOSER_WITH_ALL_DEPENDENCIES' => false,
            'COMPOSER_SKIP_SCRIPTS' => false,
        ]);
        $packages = [
            $this->package('laravel/framework', '12.0.0', ['fixture/shared' => '1.0.0']),
            $this->package('fixture/shared', '1.0.0'),
            $this->package('fixture/shared', '1.2.0'),
            $this->package('fixture/runtime-one', '1.0.0', ['fixture/runtime-two' => '^1.0']),
            $this->package('fixture/runtime-two', '1.0.0'),
            $this->package('fixture/runtime-two', '2.0.0'),
            $this->package('fixture/dev-one', '1.0.0'),
            $this->package('fixture/dev-two', '1.0.0'),
            $this->package('fixture/incompatible', '1.0.0', ['php' => '>=99.0']),
        ];
        $manifest = [
            'name' => 'fixture/batch-application',
            'version' => '1.0.0',
            'license' => 'MIT',
            'require' => ['php' => '^8.2', 'laravel/framework' => '12.0.0'],
            'require-dev' => new \stdClass,
            'repositories' => [['type' => 'package', 'package' => $packages], ['packagist.org' => false]],
            'scripts' => [
                'post-update-cmd' => ['@php -r "file_put_contents(\'cycles.log\', \'cycle\', FILE_APPEND);"'],
                'post-autoload-dump' => ['@php -r "file_put_contents(\'autoload.log\', \'autoload\', FILE_APPEND);"'],
            ],
        ];
        file_put_contents($this->project.'/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        $seed = $this->runner->run(['composer', 'install', '--no-interaction', '--no-progress'], $this->project);
        self::assertTrue($seed->successful(), $seed->output);
        foreach (['cycles.log', 'autoload.log'] as $path) {
            if (is_file($this->project.'/'.$path)) {
                unlink($this->project.'/'.$path);
            }
        }
        $this->runner->commands = [];
    }

    protected function tearDown(): void
    {
        if (! isset($this->directory) || ! is_dir($this->directory)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function test_native_batches_resolve_related_packages_preserve_versions_and_run_scripts_once_per_section(): void
    {
        $before = ComposerState::inspect($this->project);
        $installer = new ComposerDependencies($this->runner);
        $selected = [
            new Dependency('fixture/runtime-one', 'runtime'),
            new Dependency('fixture/runtime-two', 'runtime'),
            new Dependency('fixture/dev-one', 'development'),
            new Dependency('fixture/dev-two', 'development'),
            new Dependency('fixture/shared', 'development'),
        ];

        $report = $installer->install($this->project, $selected);

        self::assertSame([], $report['failures'], implode("\n", $report['failures']));
        self::assertCount(2, $this->runner->commands);
        self::assertNotContains('--dev', $this->runner->commands[0]);
        self::assertContains('--dev', $this->runner->commands[1]);
        self::assertContains('fixture/shared:1.0.0', $this->runner->commands[1]);
        foreach ($this->runner->commands as $command) {
            self::assertNotContains('--no-scripts', $command);
            self::assertNotContains('--no-audit', $command);
            self::assertNotContains('--with-all-dependencies', $command);
        }
        $after = ComposerState::inspect($this->project);
        foreach ($before->installed as $package => $version) {
            self::assertSame($version, $after->installed[$package]);
            self::assertSame($version, $after->locked[$package]);
        }
        self::assertSame('1.0.0', $after->installed['fixture/runtime-two']);
        self::assertSame('require', $after->section('fixture/runtime-one'));
        self::assertSame('require-dev', $after->section('fixture/shared'));
        self::assertSame($before->manifest['scripts'], $after->manifest['scripts']);
        self::assertSame('cyclecycle', file_get_contents($this->project.'/cycles.log'));
        self::assertSame('autoloadautoload', file_get_contents($this->project.'/autoload.log'));

        $repeat = $installer->install($this->project, $selected);
        self::assertSame([], $repeat['failures']);
        self::assertSame([], $repeat['changes']);
        self::assertCount(2, $this->runner->commands);
        self::assertSame('cyclecycle', file_get_contents($this->project.'/cycles.log'));
    }

    public function test_native_solver_rollback_allows_an_independent_package_to_complete(): void
    {
        $before = ComposerState::inspect($this->project);

        $report = (new ComposerDependencies($this->runner))->install($this->project, [
            new Dependency('fixture/incompatible', 'development'),
            new Dependency('fixture/dev-one', 'development'),
        ]);

        self::assertCount(3, $this->runner->commands);
        self::assertSame(['fixture/dev-one'], $report['available']);
        self::assertCount(1, $report['failures']);
        self::assertStringContainsString('fixture/incompatible', $report['failures'][0]);
        self::assertStringContainsString('php >=99.0', $report['failures'][0]);
        $after = ComposerState::inspect($this->project);
        self::assertNull($after->section('fixture/incompatible'));
        self::assertArrayNotHasKey('fixture/incompatible', $after->locked);
        self::assertSame('require-dev', $after->section('fixture/dev-one'));
        self::assertSame($before->manifest['require'], $after->manifest['require']);
        self::assertSame('cycle', file_get_contents($this->project.'/cycles.log'));
        self::assertSame('autoload', file_get_contents($this->project.'/autoload.log'));
    }

    private function package(string $name, string $version, array $requirements = []): array
    {
        return ['name' => $name, 'version' => $version, 'type' => 'metapackage', 'require' => (object) $requirements];
    }
}

final class OfflineComposerBatchRunner implements ProcessRunner
{
    /** @var list<list<string>> */
    public array $commands = [];

    public function __construct(private string $composer, private array $environment) {}

    public function run(array $command, string $cwd): ProcessResult
    {
        $this->commands[] = $command;
        $command[0] = $this->composer;
        $process = new Process($command, $cwd, $this->environment, null, 30);
        $exitCode = $process->run();

        return new ProcessResult($exitCode, $process->getOutput().$process->getErrorOutput());
    }
}
