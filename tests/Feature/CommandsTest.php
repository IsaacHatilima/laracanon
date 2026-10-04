<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Tests\Feature;

use Illuminate\Support\Facades\File;
use Isaachatilima\Laracanon\Boost\BoostIntegration;
use Isaachatilima\Laracanon\Commands\InstallCommand;
use Isaachatilima\Laracanon\Dependencies\ComposerDependencies;
use Isaachatilima\Laracanon\Installation\Installer;
use Isaachatilima\Laracanon\Items\ItemCatalog;
use Isaachatilima\Laracanon\LaracanonServiceProvider;
use Isaachatilima\Laracanon\Support\InstallationProgress;
use Isaachatilima\Laracanon\Support\ProcessResult;
use Isaachatilima\Laracanon\Support\ProcessRunner;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

final class CommandsTest extends TestCase
{
    private ?string $project = null;

    protected function getPackageProviders($app): array
    {
        return [LaracanonServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('laracanon.items_path', dirname(__DIR__).'/Fixtures/Items');
    }

    protected function tearDown(): void
    {
        if ($this->project !== null) {
            File::deleteDirectory($this->project);
        }

        parent::tearDown();
    }

    public function test_list_labels_isolated_fixtures_and_shows_dependency_types(): void
    {
        $this->withoutMockingConsoleOutput();
        $status = $this->app['Illuminate\\Contracts\\Console\\Kernel']->call('canon:list');
        $output = $this->app['Illuminate\\Contracts\\Console\\Kernel']->output();
        self::assertSame(0, $status);
        foreach (['fixture-rules-only', 'fixture-runtime-package', 'fixture-dev-package', 'example/runtime-tool (runtime)', 'example/dev-tool (development)', 'Sample'] as $expected) {
            self::assertStringContainsString($expected, $output);
        }
    }

    public function test_install_accepts_an_optional_list_of_items(): void
    {
        $argument = $this->app->make(InstallCommand::class)->getDefinition()->getArgument('items');

        self::assertFalse($argument->isRequired());
        self::assertTrue($argument->isArray());
        self::assertSame([], $argument->getDefault());
    }

    public function test_install_summary_reports_configuration_files(): void
    {
        $this->prepareProject();
        [$status, $output] = $this->install(['items' => ['quality']]);

        self::assertSame(0, $status);
        self::assertStringContainsString('Configuration files:', $output);
        self::assertStringContainsString('config/quality.php (installed)', $output);
        self::assertSame("<?php\nreturn ['enabled' => true];\n", file_get_contents($this->project.'/config/quality.php'));
        $this->assertInOrder($output, [
            'Preparing Laracanon installation',
            'Checking Laravel application',
            'Loading selected items',
            'Checking dependencies',
            'Discovering package skills',
            'Installing item quality',
            'Saving installation state',
            'Refreshing Laravel Boost rules and skills',
            'Installation complete',
            'Laracanon installation summary',
        ]);
    }

    public function test_dry_run_labels_preview_progress_without_starting_processes_or_writing_files(): void
    {
        $this->prepareProject();
        [$status, $output] = $this->install(['items' => ['quality'], '--dry-run' => true]);

        self::assertSame(0, $status);
        $this->assertInOrder($output, [
            'Preparing Laracanon preview',
            'Previewing item quality',
            'Previewing Laravel Boost rules and skills',
            'Preview complete',
            'Laracanon dry run (no changes made)',
        ]);
        self::assertFileDoesNotExist($this->project.'/config/quality.php');
        self::assertDirectoryDoesNotExist($this->project.'/.ai');
    }

    public function test_partial_dependency_failures_finish_with_issues_and_clear_listeners(): void
    {
        $progress = $this->prepareProject(failingDependency: true);
        file_put_contents($this->project.'/item-sources/broken.md', <<<'MARKDOWN'
---
name: broken
description: An isolated dependency failure fixture.
paths: []
dependencies:
  development:
    - example/unavailable
---

## Rules

This fixture must be skipped when its dependency fails.
MARKDOWN);
        $buffer = new BufferedOutput;
        [$status, $output] = $this->install(['items' => ['quality', 'broken']], $buffer);

        self::assertSame(1, $status);
        self::assertStringContainsString('Installing dependency example/unavailable', $output);
        self::assertStringContainsString('Installation finished with issues', $output);
        self::assertStringNotContainsString('Installation complete', $output);
        self::assertStringContainsString('Fixture Composer failure.', $output);
        self::assertFileExists($this->project.'/config/quality.php');
        self::assertFileDoesNotExist($this->project.'/.ai/rules/laracanon-broken.md');
        $progress->stage('A stale command listener');
        $progress->tick();
        self::assertSame('', $buffer->fetch());
    }

    public function test_user_edit_conflicts_do_not_finish_with_a_success_message(): void
    {
        $this->prepareProject();
        File::ensureDirectoryExists($this->project.'/config');
        file_put_contents($this->project.'/config/quality.php', '<?php // A project preference.');
        [$status, $output] = $this->install(['items' => ['quality']]);

        self::assertSame(1, $status);
        self::assertStringContainsString('Installation finished with issues', $output);
        self::assertStringNotContainsString('Installation complete', $output);
        self::assertStringContainsString('Conflicts:', $output);
        self::assertSame('<?php // A project preference.', file_get_contents($this->project.'/config/quality.php'));
    }

    public function test_quiet_installs_without_progress_and_clears_listeners(): void
    {
        $progress = $this->prepareProject();
        $buffer = new BufferedOutput;
        [$status, $output] = $this->install(['items' => ['quality'], '--quiet' => true], $buffer);

        self::assertSame(0, $status);
        self::assertSame('', $output);
        self::assertFileExists($this->project.'/config/quality.php');
        $progress->stage('A stale quiet command listener');
        $progress->tick();
        self::assertSame('', $buffer->fetch());
    }

    public function test_no_ansi_uses_readable_step_lines(): void
    {
        $this->prepareProject();
        [$status, $output] = $this->install(['items' => ['quality'], '--no-ansi' => true], new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true));

        self::assertSame(0, $status);
        self::assertStringContainsString("  Checking Laravel application\n", $output);
        self::assertStringContainsString("  Installing item quality\n", $output);
        self::assertStringNotContainsString("\x1B", $output);
        self::assertStringNotContainsString("\r", $output);
    }

    public function test_decorated_output_uses_an_indicator_and_detaches_after_completion(): void
    {
        $progress = $this->prepareProject();
        $buffer = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        [$status, $output] = $this->install(['items' => ['quality']], $buffer);

        self::assertSame(0, $status);
        self::assertStringContainsString("\r\x1B[2K", $output);
        self::assertStringContainsString('✔ Installation complete', $output);
        self::assertMatchesRegularExpression('/Preparing Laracanon installation \([^\r\n]*s\)/', $output);
        $this->assertInOrder($output, ['✔ Installation complete', 'Laracanon installation summary']);
        $progress->stage('A stale decorated command listener');
        $progress->tick();
        self::assertSame('', $buffer->fetch());
    }

    public function test_redirected_decorated_stream_output_does_not_animate(): void
    {
        $this->prepareProject();
        $stream = fopen('php://memory', 'w+');

        try {
            $output = new StreamOutput($stream, OutputInterface::VERBOSITY_NORMAL, true);
            self::assertSame(0, $this->app['Illuminate\\Contracts\\Console\\Kernel']->call('canon:install', ['items' => ['quality']], $output));
            rewind($stream);
            $contents = stream_get_contents($stream);
            self::assertStringContainsString("  Checking Laravel application\n", $contents);
            self::assertStringNotContainsString("\r\x1B[2K", $contents);
        } finally {
            fclose($stream);
        }
    }

    private function prepareProject(bool $failingDependency = false): InstallationProgress
    {
        $project = $this->project = sys_get_temp_dir().'/laracanon-command-files-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($project.'/bootstrap');
        File::ensureDirectoryExists($project.'/vendor/composer');
        File::ensureDirectoryExists($project.'/item-sources');
        file_put_contents($project.'/artisan', '<?php');
        file_put_contents($project.'/bootstrap/app.php', '<?php');
        file_put_contents($project.'/composer.json', json_encode(['require' => ['laravel/framework' => '^13.0'], 'require-dev' => ['laravel/boost' => '^2.10']], JSON_THROW_ON_ERROR));
        $packages = [['name' => 'laravel/framework', 'version' => '13.34.0'], ['name' => 'laravel/boost', 'version' => '2.10.1']];
        file_put_contents($project.'/composer.lock', json_encode(['packages' => $packages, 'packages-dev' => []], JSON_THROW_ON_ERROR));
        file_put_contents($project.'/vendor/composer/installed.json', json_encode(['packages' => $packages], JSON_THROW_ON_ERROR));
        file_put_contents($project.'/item-sources/quality.md', <<<'MARKDOWN'
---
name: quality
description: An isolated configuration output fixture.
paths: []
---

## Files

### config/quality.php

```php
<?php
return ['enabled' => true];
```
MARKDOWN);
        $runner = new class($failingDependency) implements ProcessRunner
        {
            public function __construct(private readonly bool $failingDependency) {}

            public function run(array $command, string $cwd): ProcessResult
            {
                if ($this->failingDependency) {
                    return new ProcessResult(1, 'Fixture Composer failure.');
                }

                throw new \LogicException('This fixture must not start external processes.');
            }
        };
        $progress = $this->app->make(InstallationProgress::class);
        $boost = new class($runner, $progress) extends BoostIntegration
        {
            public function packageSkillNames(string $projectPath): array
            {
                return [];
            }

            public function refresh(string $projectPath, bool $dryRun = false, array $selectedPackages = []): array
            {
                return ['resources' => [], 'failures' => [], 'notes' => []];
            }
        };
        $this->app->instance(Installer::class, new Installer(new ItemCatalog($project.'/item-sources'), new ComposerDependencies($runner, $progress), $boost, $progress));
        $this->app->setBasePath($project);
        $this->withoutMockingConsoleOutput();

        return $progress;
    }

    private function install(array $arguments, ?BufferedOutput $output = null): array
    {
        $kernel = $this->app['Illuminate\\Contracts\\Console\\Kernel'];
        $output ??= new BufferedOutput;
        $status = $kernel->call('canon:install', $arguments, $output);

        return [$status, $output->fetch()];
    }

    private function assertInOrder(string $output, array $messages): void
    {
        $offset = 0;
        foreach ($messages as $message) {
            $position = strpos($output, $message, $offset);
            self::assertNotFalse($position, 'Missing progress message in order: '.$message);
            $offset = $position + strlen($message);
        }
    }
}
