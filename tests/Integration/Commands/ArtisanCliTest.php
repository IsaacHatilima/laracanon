<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Tests\Integration\Commands;

use Isaachatilima\Laracanon\Support\ProcessResult;
use Isaachatilima\Laracanon\Support\SymfonyProcessRunner;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Exercises Artisan and the real Boost refresh helper in fresh PHP processes. */
final class ArtisanCliTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir().'/laracanon-artisan-cli-'.bin2hex(random_bytes(8));
        mkdir($this->project, 0777, true);

        foreach (['bootstrap/cache', 'config', 'storage/framework/views', 'storage/framework/cache', 'storage/logs', 'resources/views'] as $directory) {
            mkdir($this->project.'/'.$directory, 0777, true);
        }

        $package = dirname(__DIR__, 3);
        symlink($package.'/vendor', $this->project.'/vendor');
        $this->write('composer.json', json_encode([
            'name' => 'fixture/laracanon-cli',
            'type' => 'project',
            'require' => ['php' => '^8.2', 'laravel/framework' => '^11.0 || ^12.0 || ^13.0'],
            'require-dev' => ['laravel/boost' => '^2.0'],
            'scripts' => ['post-update-cmd' => ['my existing command']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        copy($package.'/composer.lock', $this->project.'/composer.lock');
        $this->write('config/app.php', <<<'PHP'
<?php
return ['name' => 'Laracanon CLI fixture', 'env' => 'local', 'debug' => true, 'key' => 'base64:a2V5a2V5a2V5a2V5a2V5a2V5a2V5a2V5a2V5a2V5a2U='];
PHP);
        $this->write('config/view.php', <<<'PHP'
<?php
return ['paths' => [dirname(__DIR__).'/resources/views'], 'compiled' => dirname(__DIR__).'/storage/framework/views'];
PHP);
        $this->write('bootstrap/app.php', <<<'PHP'
<?php
require_once dirname(__DIR__).'/fixture-runner.php';
return Illuminate\Foundation\Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        Laravel\Boost\BoostServiceProvider::class,
        Isaachatilima\Laracanon\LaracanonServiceProvider::class,
        FixtureNoComposerProvider::class,
    ])
    ->withExceptions(fn (Illuminate\Foundation\Configuration\Exceptions $exceptions) => null)
    ->create();
PHP);
        $this->write('fixture-runner.php', <<<'PHP'
<?php
final class FixtureNoComposerProvider extends Illuminate\Support\ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Isaachatilima\Laracanon\Support\ProcessRunner::class,
            fn () => new FixtureNoComposerRunner($this->app->basePath()));
    }
}
final readonly class FixtureNoComposerRunner implements Isaachatilima\Laracanon\Support\ProcessRunner
{
    public function __construct(private string $project) {}

    public function run(array $command, string $cwd): Isaachatilima\Laracanon\Support\ProcessResult
    {
        file_put_contents($this->project.'/.process-log.jsonl', json_encode($command, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND);
        if ($command[0] === 'composer') {
            return new Isaachatilima\Laracanon\Support\ProcessResult(1, 'External Composer mutations are forbidden in this CLI fixture.');
        }
        if ($command[0] !== PHP_BINARY) {
            return new Isaachatilima\Laracanon\Support\ProcessResult(1, 'Only the actual PHP Boost helper is permitted in this CLI fixture.');
        }
        return (new Isaachatilima\Laracanon\Support\SymfonyProcessRunner)->run($command, $cwd);
    }
}
PHP);
        $this->write('artisan', <<<'PHP'
<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
exit($app->handleCommand(new Symfony\Component\Console\Input\ArgvInput));
PHP);

        $this->useFixtureCatalog();

        // Laravel itself generates its package cache on first bootstrap. Warm
        // that cache before asserting the installer's dry run writes nothing.
        $list = $this->artisan(['canon:list']);
        self::assertTrue($list->successful(), 'CLI exit '.$list->exitCode.': '.$list->output.' '.(is_file($this->project.'/storage/logs/laravel.log') ? file_get_contents($this->project.'/storage/logs/laravel.log') : ''));
    }

    protected function tearDown(): void
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->project, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if ($file->isLink() || ! $file->isDir()) {
                unlink($file->getPathname());
            } else {
                rmdir($file->getPathname());
            }
        }

        rmdir($this->project);
    }

    public function test_real_artisan_lists_fixtures_and_previews_multiple_items_without_writes_or_child_processes(): void
    {
        $list = $this->artisan(['canon:list']);
        self::assertTrue($list->successful(), $list->output);
        self::assertStringContainsString('fixture-rules-only', $list->output);
        self::assertStringContainsString('fixture-authored-workflow', $list->output);
        self::assertStringContainsString('Sample', $list->output);
        $before = $this->snapshot();

        $dryRun = $this->artisan(['canon:install', 'fixture-rules-only', 'fixture-authored-workflow', '--dry-run']);

        self::assertTrue($dryRun->successful(), $dryRun->output);
        self::assertStringContainsString('Preparing Laracanon preview', $dryRun->output);
        self::assertStringContainsString('Checking dependency laravel/boost', $dryRun->output);
        self::assertStringContainsString('Previewing item fixture-rules-only', $dryRun->output);
        self::assertStringContainsString('Previewing Laravel Boost rules and skills', $dryRun->output);
        self::assertStringContainsString('Preview complete', $dryRun->output);
        self::assertStringNotContainsString("\033[", $dryRun->output);
        self::assertStringContainsString('Laracanon dry run', $dryRun->output);
        self::assertStringContainsString('fixture-rules-only: planned', $dryRun->output);
        self::assertStringContainsString('fixture-authored-workflow: planned', $dryRun->output);
        self::assertSame($before, $this->snapshot());
        self::assertSame([], $this->recordedProcesses());
        self::assertFileDoesNotExist($this->project.'/.ai/rules/index.md');
        self::assertFileDoesNotExist($this->project.'/boost.json');
    }

    public function test_real_no_name_dry_run_plans_fixture_runtime_and_development_dependencies_without_changes(): void
    {
        $before = $this->snapshot();

        $dryRun = $this->artisan(['canon:install', '--dry-run']);

        self::assertTrue($dryRun->successful(), $dryRun->output);
        self::assertStringContainsString('fixture-rules-only: planned', $dryRun->output);
        self::assertStringContainsString('fixture-authored-workflow: planned', $dryRun->output);
        self::assertStringContainsString('fixture-runtime-package: planned', $dryRun->output);
        self::assertStringContainsString('fixture-dev-package: planned', $dryRun->output);
        self::assertStringContainsString('example/runtime-tool to require ', $dryRun->output);
        self::assertStringContainsString('example/dev-tool to require-dev ', $dryRun->output);
        self::assertSame($before, $this->snapshot());
        self::assertSame([], $this->recordedProcesses());
    }

    public function test_real_install_runs_fresh_boost_refresh_is_idempotent_and_preserves_edited_rules(): void
    {
        $composer = file_get_contents($this->project.'/composer.json');
        $install = $this->artisan(['canon:install', 'fixture-rules-only', 'fixture-authored-workflow']);

        self::assertTrue($install->successful(), $install->output);
        self::assertStringContainsString('Installing item fixture-rules-only', $install->output);
        self::assertStringContainsString('Refreshing Laravel Boost rules and skills', $install->output);
        self::assertLessThan(strpos($install->output, 'Laracanon installation summary'), strpos($install->output, 'Installation complete'));
        self::assertStringContainsString('fixture-rules-only: installed', $install->output);
        self::assertFileExists($this->project.'/.ai/rules/laracanon-fixture-rules-only.md');
        self::assertFileExists($this->project.'/.ai/skills/laracanon-fixture-authored-workflow/SKILL.md');
        self::assertFileExists($this->project.'/.agents/skills/laracanon-fixture-authored-workflow/SKILL.md');
        self::assertStringContainsString('laracanon-fixture-rules-only.md', file_get_contents($this->project.'/.ai/rules/index.md'));
        self::assertStringContainsString('@.ai/rules/index.md', file_get_contents($this->project.'/AGENTS.md'));
        self::assertStringContainsString('[mcp_servers.laravel-boost]', file_get_contents($this->project.'/.codex/config.toml'));
        self::assertSame(['codex'], json_decode(file_get_contents($this->project.'/boost.json'), true)['agents']);
        self::assertSame($composer, file_get_contents($this->project.'/composer.json'));
        self::assertCount(1, $this->recordedProcesses());
        self::assertStringEndsWith('/resources/boost-refresh.php', $this->recordedProcesses()[0][1]);
        $before = $this->snapshot();

        $repeat = $this->artisan(['canon:install', 'fixture-rules-only', 'fixture-authored-workflow']);

        self::assertTrue($repeat->successful(), $repeat->output);
        self::assertStringContainsString('(unchanged)', $repeat->output);
        self::assertSame($before, $this->snapshot());
        self::assertCount(2, $this->recordedProcesses());

        $edited = "# Project-edited fixture rule\n\nPreserve my local rule.\n";
        $this->write('.ai/rules/laracanon-fixture-rules-only.md', $edited);
        $conflict = $this->artisan(['canon:install', 'fixture-rules-only', 'fixture-authored-workflow']);

        self::assertSame(1, $conflict->exitCode, $conflict->output);
        self::assertStringContainsString('Installation finished with issues', $conflict->output);
        self::assertStringNotContainsString('Installation complete', $conflict->output);
        self::assertStringContainsString('Conflicts:', $conflict->output);
        self::assertStringContainsString('locally modified', $conflict->output);
        self::assertSame($edited, file_get_contents($this->project.'/.ai/rules/laracanon-fixture-rules-only.md'));
        self::assertSame($composer, file_get_contents($this->project.'/composer.json'));

        foreach ($this->recordedProcesses() as $command) {
            self::assertSame(PHP_BINARY, $command[0]);
        }
    }

    public function test_real_quiet_preview_suppresses_progress_and_summary_without_mutation(): void
    {
        $before = $this->snapshot();

        $result = $this->artisan(['canon:install', 'fixture-rules-only', '--dry-run', '--quiet']);

        self::assertTrue($result->successful(), $result->output);
        self::assertSame('', $result->output);
        self::assertSame($before, $this->snapshot());
        self::assertSame([], $this->recordedProcesses());
    }

    private function useFixtureCatalog(): void
    {
        mkdir($this->project.'/item-sources');

        foreach (glob(dirname(__DIR__, 2).'/Fixtures/Items/*.md') ?: [] as $source) {
            copy($source, $this->project.'/item-sources/'.basename($source));
        }

        $this->write('config/laracanon.php', <<<'PHP'
<?php
return ['items_path' => dirname(__DIR__).'/item-sources'];
PHP);
    }

    /** @param list<string> $arguments */
    private function artisan(array $arguments): ProcessResult
    {
        return (new SymfonyProcessRunner)->run([PHP_BINARY, '-d', 'display_errors=1', '-d', 'log_errors=0', $this->project.'/artisan', ...$arguments, '--no-interaction', '--no-ansi'], $this->project);
    }

    /** @return list<list<string>> */
    private function recordedProcesses(): array
    {
        $path = $this->project.'/.process-log.jsonl';

        return is_file($path)
            ? array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
            : [];
    }

    /** @return array<string, string> */
    private function snapshot(): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->project, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && ! $file->isLink() && $file->getFilename() !== '.process-log.jsonl') {
                $files[substr($file->getPathname(), strlen($this->project) + 1)] = hash_file('sha256', $file->getPathname());
            }
        }

        ksort($files);

        return $files;
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->project.'/'.$relative;

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $contents);
    }
}
