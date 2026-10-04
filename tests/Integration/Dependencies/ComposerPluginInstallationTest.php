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
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/** Native Composer configuration with dependency downloads deliberately mocked. */
final class ComposerPluginInstallationTest extends TestCase
{
    private const PACKAGE = 'nunomaduro/phpinsights';

    private const PLUGIN = 'dealerdirect/phpcodesniffer-composer-installer';

    private string $directory;

    private string $project;

    private string $composer;

    /** @var array<string, string|false> */
    private array $environment;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/laracanon-native-plugin-'.bin2hex(random_bytes(8));
        $this->project = $this->directory.'/application';
        foreach (['application/vendor/composer', 'composer-home', 'composer-cache'] as $path) {
            mkdir($this->directory.'/'.$path, 0777, true);
        }

        $this->environment = [
            'COMPOSER_HOME' => $this->directory.'/composer-home',
            'COMPOSER_CACHE_DIR' => $this->directory.'/composer-cache',
            'COMPOSER_DISABLE_NETWORK' => '1',
            'COMPOSER' => false,
            'COMPOSER_AUTH' => false,
            'COMPOSER_VENDOR_DIR' => false,
        ];
        $composer = (new ExecutableFinder)->find('composer');
        if ($composer === null) {
            self::markTestSkipped('Native Composer is unavailable.');
        }
        $this->composer = $composer;

        $this->writeJson($this->project.'/composer.json', [
            'name' => 'fixture/plugin-application',
            'version' => '1.0.0',
            'type' => 'project',
            'license' => 'MIT',
            'require' => ['php' => '^8.2', 'laravel/framework' => '^12.0'],
            'require-dev' => ['fixture/existing-tool' => '^1.0'],
            'scripts' => ['post-update-cmd' => ['preserve-this-project-command']],
            'config' => ['sort-packages' => true, 'allow-plugins' => ['fixture/existing-plugin' => false]],
        ]);
        $runtime = [['name' => 'laravel/framework', 'version' => 'v12.1.0']];
        $development = [['name' => 'fixture/existing-tool', 'version' => '1.2.3']];
        $this->writeJson($this->project.'/composer.lock', [
            'packages' => $runtime,
            'packages-dev' => $development,
            'plugin-api-version' => '2.9.0',
        ]);
        $this->writeJson($this->project.'/vendor/composer/installed.json', [
            'packages' => array_merge($runtime, $development),
            'dev' => true,
            'dev-package-names' => ['fixture/existing-tool'],
        ]);
    }

    protected function tearDown(): void
    {
        if (! isset($this->directory) || ! is_dir($this->directory)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if ($file->isLink() || ! $file->isDir()) {
                unlink($file->getPathname());
            } else {
                rmdir($file->getPathname());
            }
        }
        rmdir($this->directory);
    }

    public function test_fresh_install_configures_only_named_permission_before_dependency_mutation(): void
    {
        $before = ComposerState::inspect($this->project);
        $runner = $this->runner(true);

        $report = $this->install($runner);

        self::assertSame([], $report['failures'], implode("\n", $report['failures']));
        self::assertSame([self::PACKAGE], $report['available']);
        self::assertSame(['config', 'config', 'require'], array_column($runner->commands, 1));
        self::assertSame('allow-plugins.'.self::PLUGIN, $runner->commands[1][5]);
        self::assertSame('true', $runner->commands[1][6]);
        $expected = $before->manifest;
        $expected['config']['allow-plugins'][self::PLUGIN] = true;
        $expected['require-dev'][self::PACKAGE] = '*@stable';
        self::assertSame($expected, ComposerState::inspect($this->project)->manifest);
        $this->assertExistingVersionsPreserved($before);
        self::assertContains('Allowed item-declared Composer plugin '.self::PLUGIN.' in the project.', $report['changes']);

        foreach ($runner->commands as $command) {
            if ($command[1] === 'config') {
                self::assertContains('--no-plugins', $command);
                self::assertContains('--no-scripts', $command);
                self::assertContains('--no-interaction', $command);
            }
        }
    }

    public function test_already_installed_repeat_performs_no_configuration_or_dependency_processes(): void
    {
        $runner = $this->runner(true);
        $first = $this->install($runner);
        self::assertSame([], $first['failures'], implode("\n", $first['failures']));
        $before = $this->snapshot();
        $commands = $runner->commands;

        $repeat = $this->install($runner);

        self::assertSame([], $repeat['failures']);
        self::assertSame([], $repeat['changes']);
        self::assertSame([self::PACKAGE], $repeat['available']);
        self::assertSame($commands, $runner->commands);
        self::assertSame($before, $this->snapshot());
    }

    public function test_native_global_wildcard_denial_is_preserved_while_installation_continues_disabled(): void
    {
        $globalConfig = $this->directory.'/composer-home/config.json';
        $this->writeJson($globalConfig, ['config' => ['allow-plugins' => ['dealerdirect/*' => false, 'fixture/global-plugin' => true]]]);
        $globalBefore = file_get_contents($globalConfig);
        $before = ComposerState::inspect($this->project);
        $runner = $this->runner(false);

        $report = $this->install($runner);

        self::assertSame([], $report['failures'], implode("\n", $report['failures']));
        self::assertSame([self::PACKAGE], $report['available']);
        self::assertSame(['config', 'require'], array_column($runner->commands, 1));
        self::assertContains('Composer plugin '.self::PLUGIN.': existing deny decision preserved; plugin remains disabled.', $report['notes']);
        $expected = $before->manifest;
        $expected['require-dev'][self::PACKAGE] = '*@stable';
        self::assertSame($expected, ComposerState::inspect($this->project)->manifest);
        self::assertSame($globalBefore, file_get_contents($globalConfig));
        $this->assertExistingVersionsPreserved($before);
    }

    private function runner(bool $expectPermission): NativeConfigDependencyRunner
    {
        return new NativeConfigDependencyRunner($this->composer, $this->environment, $expectPermission);
    }

    private function install(NativeConfigDependencyRunner $runner): array
    {
        return (new ComposerDependencies($runner))->install(
            $this->project,
            [new Dependency(self::PACKAGE, 'development')],
            false,
            [self::PACKAGE => [self::PLUGIN]],
        );
    }

    private function assertExistingVersionsPreserved(ComposerState $before): void
    {
        $after = ComposerState::inspect($this->project);
        foreach ($before->installed as $package => $version) {
            self::assertSame($version, $after->installed[$package]);
            self::assertSame($before->locked[$package], $after->locked[$package]);
        }
    }

    private function snapshot(): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $files[substr($file->getPathname(), strlen($this->directory) + 1)] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($files);

        return $files;
    }

    private function writeJson(string $path, array $value): void
    {
        file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }
}

/** Uses native config commands only; every dependency operation is a disk fixture. */
final class NativeConfigDependencyRunner implements ProcessRunner
{
    /** @var list<list<string>> */
    public array $commands = [];

    /** @param array<string, string|false> $environment */
    public function __construct(private string $composer, private array $environment, private bool $expectPermission) {}

    public function run(array $command, string $cwd): ProcessResult
    {
        $this->commands[] = $command;
        if ($command[0] !== 'composer') {
            throw new RuntimeException('Unexpected command in native configuration fixture.');
        }
        if ($command[1] === 'config') {
            $command[0] = $this->composer;
            $process = new Process($command, $cwd, $this->environment, null, 30);
            $exitCode = $process->run();

            return new ProcessResult($exitCode, $process->getOutput().$process->getErrorOutput());
        }
        if ($command !== ['composer', 'require', 'nunomaduro/phpinsights:*@stable', '--prefer-stable', '--no-interaction', '--no-progress', '--dev']) {
            throw new RuntimeException('Only the selected mocked dependency installation is permitted.');
        }

        $manifest = $this->read($cwd.'/composer.json');
        if (($manifest['config']['allow-plugins']['dealerdirect/phpcodesniffer-composer-installer'] ?? null) !== ($this->expectPermission ? true : null)) {
            throw new RuntimeException('Item plugin policy was not settled before the dependency operation.');
        }
        $manifest['require-dev']['nunomaduro/phpinsights'] = '*@stable';
        $this->write($cwd.'/composer.json', $manifest);
        $packages = [
            ['name' => 'nunomaduro/phpinsights', 'version' => 'v2.15.0', 'require' => ['dealerdirect/phpcodesniffer-composer-installer' => '^1.2']],
            ['name' => 'dealerdirect/phpcodesniffer-composer-installer', 'version' => 'v1.2.1', 'type' => 'composer-plugin'],
        ];
        $lock = $this->read($cwd.'/composer.lock');
        $lock['packages-dev'] = array_merge($lock['packages-dev'], $packages);
        $this->write($cwd.'/composer.lock', $lock);
        $installed = $this->read($cwd.'/vendor/composer/installed.json');
        $installed['packages'] = array_merge($installed['packages'], $packages);
        $installed['dev-package-names'] = array_merge($installed['dev-package-names'], array_column($packages, 'name'));
        $this->write($cwd.'/vendor/composer/installed.json', $installed);

        return new ProcessResult(0, 'Selected dependency installation mocked; no packages downloaded.');
    }

    private function read(string $path): array
    {
        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function write(string $path, array $value): void
    {
        file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }
}
