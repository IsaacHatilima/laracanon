<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Tests\Unit\Dependencies;

use Closure;
use Isaachatilima\Laracanon\Dependencies\ComposerPluginPolicy;
use Isaachatilima\Laracanon\Dependencies\ComposerState;
use Isaachatilima\Laracanon\Support\ProcessResult;
use Isaachatilima\Laracanon\Support\ProcessRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ComposerPluginPolicyTest extends TestCase
{
    private const PLUGIN = 'dealerdirect/phpcodesniffer-composer-installer';

    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir().'/laracanon-plugin-policy-'.bin2hex(random_bytes(8));
        mkdir($this->project);
        $this->write([
            'name' => 'example/application',
            'require' => ['php' => '^8.2', 'laravel/framework' => '^12.0'],
            'scripts' => ['post-update-cmd' => ['preserve-this-script']],
            'config' => ['sort-packages' => true, 'allow-plugins' => ['other/plugin' => true]],
        ]);
    }

    protected function tearDown(): void
    {
        unlink($this->project.'/composer.json');
        rmdir($this->project);
    }

    public function test_missing_named_permission_is_added_once_and_unrelated_settings_are_preserved(): void
    {
        $before = $this->manifest();
        $runner = $this->runner($before['config']['allow-plugins']);
        $report = $this->report();

        self::assertTrue((new ComposerPluginPolicy($runner))->prepare($this->project, [self::PLUGIN, self::PLUGIN], ComposerState::inspect($this->project), false, $report));

        self::assertSame([
            ['composer', 'config', '--no-plugins', '--no-scripts', '--no-interaction', 'allow-plugins'],
            ['composer', 'config', '--no-plugins', '--no-scripts', '--no-interaction', 'allow-plugins.'.self::PLUGIN, 'true'],
        ], $runner->commands);
        $before['config']['allow-plugins'][self::PLUGIN] = true;
        self::assertSame($before, $this->manifest());
        self::assertCount(1, $report['changes']);
        self::assertSame([], $report['failures']);
    }

    #[DataProvider('existingDecisions')]
    public function test_effective_existing_decisions_are_preserved(mixed $policy, bool $allowed): void
    {
        $before = file_get_contents($this->project.'/composer.json');
        $runner = $this->runner($policy);
        $report = $this->report();

        self::assertTrue((new ComposerPluginPolicy($runner))->prepare($this->project, [self::PLUGIN], ComposerState::inspect($this->project), false, $report));

        self::assertCount(1, $runner->commands);
        self::assertSame([], $report['changes']);
        self::assertSame([], $report['failures']);
        self::assertStringContainsString($allowed ? 'allow decision preserved' : 'deny decision preserved', $report['notes'][0]);
        self::assertSame($before, file_get_contents($this->project.'/composer.json'));
    }

    public static function existingDecisions(): array
    {
        return [
            'exact allow' => [[self::PLUGIN => true], true],
            'exact denial' => [[self::PLUGIN => false], false],
            'wildcard denial' => [['dealerdirect/*' => false], false],
            'wildcard allow' => [['dealerdirect/*' => true], true],
            'all denied' => [false, false],
            'all allowed' => [true, true],
            'first broad allowance wins' => [['*' => true, self::PLUGIN => false], true],
            'first explicit denial wins' => [[self::PLUGIN => false, '*' => true], false],
            'case insensitive patterns' => [['DEALERDIRECT/*' => false], false],
            'global policy denial' => [['other/plugin' => true, 'dealerdirect/*' => false], false],
        ];
    }

    public function test_shell_pattern_metacharacters_other_than_star_are_literals(): void
    {
        $runner = $this->runner(['dealerdirect/phpcodesniffer-composer-install?r' => false, 'dealerdirect/[p]*' => false]);
        $report = $this->report();

        self::assertTrue((new ComposerPluginPolicy($runner))->prepare($this->project, [self::PLUGIN], ComposerState::inspect($this->project), false, $report));

        self::assertCount(2, $runner->commands);
        self::assertTrue($this->manifest()['config']['allow-plugins'][self::PLUGIN]);
    }

    public function test_dry_run_plans_permission_without_processes_or_file_changes(): void
    {
        $before = file_get_contents($this->project.'/composer.json');
        $runner = $this->runner([]);
        $report = $this->report();

        self::assertTrue((new ComposerPluginPolicy($runner))->prepare($this->project, [self::PLUGIN], ComposerState::inspect($this->project), true, $report));

        self::assertSame([], $runner->commands);
        self::assertCount(1, $report['changes']);
        self::assertStringContainsString('if no existing effective policy covers it', $report['changes'][0]);
        self::assertSame($before, file_get_contents($this->project.'/composer.json'));
    }

    public function test_dry_run_honors_local_denial(): void
    {
        $manifest = $this->manifest();
        $manifest['config']['allow-plugins'] = false;
        $this->write($manifest);
        $runner = $this->runner([]);
        $report = $this->report();

        self::assertTrue((new ComposerPluginPolicy($runner))->prepare($this->project, [self::PLUGIN], ComposerState::inspect($this->project), true, $report));

        self::assertSame([], $runner->commands);
        self::assertSame([], $report['changes']);
        self::assertStringContainsString('deny decision preserved', $report['notes'][0]);
    }

    public function test_read_failure_prevents_configuration(): void
    {
        $before = file_get_contents($this->project.'/composer.json');
        $runner = new PolicyTestRunner(static fn (): ProcessResult => new ProcessResult(2, 'Configuration cannot be read'));
        $report = $this->report();

        self::assertFalse((new ComposerPluginPolicy($runner))->prepare($this->project, [self::PLUGIN], ComposerState::inspect($this->project), false, $report));

        self::assertCount(1, $runner->commands);
        self::assertStringContainsString('Cannot inspect effective Composer plugin policy', $report['failures'][0]);
        self::assertSame($before, file_get_contents($this->project.'/composer.json'));
    }

    public function test_write_failure_preserves_raw_error_and_does_not_claim_success(): void
    {
        $before = file_get_contents($this->project.'/composer.json');
        $runner = new PolicyTestRunner(static fn (array $command): ProcessResult => count($command) === 6
            ? new ProcessResult(0, '{}')
            : new ProcessResult(1, 'Composer could not write composer.json'));
        $report = $this->report();

        self::assertFalse((new ComposerPluginPolicy($runner))->prepare($this->project, [self::PLUGIN], ComposerState::inspect($this->project), false, $report));

        self::assertSame([], $report['changes']);
        self::assertStringContainsString('Composer could not write composer.json', $report['failures'][0]);
        self::assertSame($before, file_get_contents($this->project.'/composer.json'));
    }

    public function test_write_success_is_verified_against_persisted_manifest(): void
    {
        $runner = new PolicyTestRunner(static fn (): ProcessResult => new ProcessResult(0, '{}'));
        $report = $this->report();

        self::assertFalse((new ComposerPluginPolicy($runner))->prepare($this->project, [self::PLUGIN], ComposerState::inspect($this->project), false, $report));

        self::assertSame([], $report['changes']);
        self::assertStringContainsString('did not configure', $report['failures'][0]);
    }

    public function test_unrelated_manifest_mutation_is_detected_before_dependency_installation(): void
    {
        $runner = new PolicyTestRunner(function (array $command): ProcessResult {
            if (count($command) === 7) {
                $manifest = $this->manifest();
                $manifest['config']['allow-plugins'][self::PLUGIN] = true;
                $manifest['scripts'] = [];
                $this->write($manifest);
            }

            return new ProcessResult(0, '{}');
        });
        $report = $this->report();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('changed unrelated application settings');

        (new ComposerPluginPolicy($runner))->prepare($this->project, [self::PLUGIN], ComposerState::inspect($this->project), false, $report);
    }

    public function test_composer_warnings_and_ansi_do_not_hide_effective_policy(): void
    {
        $runner = new PolicyTestRunner(static fn (): ProcessResult => new ProcessResult(0, "\033[32m{\"dealerdirect/*\":false}\033[0m\nComposer could not detect the root package version.\n"));
        $report = $this->report();

        self::assertTrue((new ComposerPluginPolicy($runner))->prepare($this->project, [self::PLUGIN], ComposerState::inspect($this->project), false, $report));

        self::assertCount(1, $runner->commands);
        self::assertSame([], $report['changes']);
    }

    public function test_native_crlf_output_can_be_read(): void
    {
        $runner = new PolicyTestRunner(static fn (): ProcessResult => new ProcessResult(0, "false\r\n"));
        $report = $this->report();

        self::assertTrue((new ComposerPluginPolicy($runner))->prepare($this->project, [self::PLUGIN], ComposerState::inspect($this->project), false, $report));

        self::assertCount(1, $runner->commands);
        self::assertStringContainsString('deny decision preserved', $report['notes'][0]);
    }

    public function test_programmatic_wildcard_permission_is_rejected_without_a_process(): void
    {
        $runner = $this->runner([]);
        $report = $this->report();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('without wildcards or constraints');

        try {
            (new ComposerPluginPolicy($runner))->prepare($this->project, ['*/*'], ComposerState::inspect($this->project), false, $report);
        } finally {
            self::assertSame([], $runner->commands);
        }
    }

    public function test_partial_configuration_failure_reports_the_completed_permission_change(): void
    {
        $runner = new PolicyTestRunner(function (array $command): ProcessResult {
            if (count($command) === 7) {
                $manifest = $this->manifest();
                $manifest['config']['allow-plugins'][self::PLUGIN] = true;
                $this->write($manifest);

                return new ProcessResult(1, 'Configuration completed before another error');
            }

            return new ProcessResult(0, '{}');
        });
        $report = $this->report();

        self::assertFalse((new ComposerPluginPolicy($runner))->prepare($this->project, [self::PLUGIN], ComposerState::inspect($this->project), false, $report));

        self::assertCount(1, $report['changes']);
        self::assertStringContainsString('permission changed before configuration failed', $report['changes'][0]);
        self::assertTrue($this->manifest()['config']['allow-plugins'][self::PLUGIN]);
    }

    #[DataProvider('malformedPolicies')]
    public function test_malformed_policy_is_rejected_without_write(string $output): void
    {
        $runner = new PolicyTestRunner(static fn (): ProcessResult => new ProcessResult(0, $output));
        $report = $this->report();
        $this->expectException(RuntimeException::class);

        try {
            (new ComposerPluginPolicy($runner))->prepare($this->project, [self::PLUGIN], ComposerState::inspect($this->project), false, $report);
        } finally {
            self::assertCount(1, $runner->commands);
        }
    }

    public static function malformedPolicies(): array
    {
        return [
            'no policy' => ['Composer produced no configuration'],
            'ambiguous policies' => ["{}\nfalse"],
            'nonboolean decision' => ['{"dealerdirect/*":"false"}'],
            'empty pattern' => ['{"":true}'],
            'null policy' => ['null'],
            'nonempty list' => ['[true]'],
        ];
    }

    private function runner(mixed $policy): PolicyTestRunner
    {
        return new PolicyTestRunner(function (array $command) use ($policy): ProcessResult {
            if (count($command) === 6) {
                return new ProcessResult(0, json_encode($policy === [] ? (object) [] : $policy, JSON_THROW_ON_ERROR));
            }
            $plugin = substr($command[5], strlen('allow-plugins.'));
            $manifest = $this->manifest();
            $manifest['config']['allow-plugins'][$plugin] = true;
            $this->write($manifest);

            return new ProcessResult(0, '');
        });
    }

    private function report(): array
    {
        return ['changes' => [], 'failures' => [], 'notes' => [], 'available' => []];
    }

    private function manifest(): array
    {
        return json_decode(file_get_contents($this->project.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function write(array $manifest): void
    {
        file_put_contents($this->project.'/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }
}

final class PolicyTestRunner implements ProcessRunner
{
    public array $commands = [];

    public function __construct(private readonly Closure $callback) {}

    public function run(array $command, string $cwd): ProcessResult
    {
        $this->commands[] = $command;

        return ($this->callback)($command, $cwd);
    }
}
