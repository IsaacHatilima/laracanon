<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Tests\Integration\Boost;

use Illuminate\Support\Facades\File;
use Isaachatilima\Laracanon\Boost\BoostProjectRefresher;
use Laravel\Boost\BoostManager;
use Laravel\Boost\BoostServiceProvider;
use Laravel\Boost\Console\InstallCommand;
use Laravel\Boost\Console\UpdateCommand;
use Laravel\Boost\Install\Agents\Agent;
use Laravel\Boost\Install\GuidelineComposer;
use Laravel\Boost\Install\GuidelineConfig;
use Laravel\Boost\Install\GuidelineWriter;
use Laravel\Boost\Rules\RuleRepository;
use Laravel\Boost\Support\RenderFailures;
use Laravel\Roster\ProjectManager;
use Orchestra\Testbench\TestCase;

final class BoostProjectRefresherTest extends TestCase
{
    private string $project;

    protected function getPackageProviders($app): array
    {
        return [BoostServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = sys_get_temp_dir().'/laracanon-boost-test-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->project.'/vendor');
        $this->app->setBasePath($this->project);
        $this->app->useStoragePath($this->project.'/storage');
        $this->app->singleton(ProjectManager::class, fn () => new ProjectManager);
        $this->app->singleton(BoostManager::class, fn () => new BoostManager);
        $this->app->singleton(RuleRepository::class, fn () => new RuleRepository($this->project.'/.ai/rules'));
        // Native GuidelineWriter uses its configured path without base_path().
        // Absolute fixture destinations keep native Artisan calls isolated.
        foreach (['claude_code', 'codex', 'junie', 'opencode'] as $agent) {
            $this->app['config']->set('boost.agents.'.$agent.'.guidelines_path', $this->project.'/AGENTS.md');
        }
        $this->app['config']->set('view.compiled', $this->project.'/storage/framework/views');
        File::ensureDirectoryExists($this->project.'/storage/framework/views');
        symlink(dirname(__DIR__, 3).'/vendor/laravel', $this->project.'/vendor/laravel');
        $this->writeJson('composer.json', [
            'name' => 'example/application',
            'require' => ['php' => '^8.2', 'laravel/framework' => '^12.0'],
            'require-dev' => ['laravel/boost' => '^2.0', 'fixture/toolkit' => '^1.0'],
            'scripts' => ['post-update-cmd' => ['my existing script']],
        ]);
        $this->writeJson('composer.lock', [
            'packages' => [['name' => 'laravel/framework', 'version' => 'v12.50.0']],
            'packages-dev' => [['name' => 'laravel/boost', 'version' => 'v2.10.1'], ['name' => 'fixture/toolkit', 'version' => 'v1.0.0']],
        ]);
        $this->write('.ai/rules/laracanon-actions.md', "---\npaths:\n  - app/Actions/**\n---\n\n# Actions\n\nKeep transactions in actions.\n");
        $this->write('.ai/rules/project.md', "---\npaths:\n  - app/**\n---\n\n# Project preference\n\nProject preferences take precedence over package examples.\n");
        $this->write('vendor/fixture/toolkit/resources/boost/guidelines/core.md', "# Fixture toolkit\n\nPackage instructions for the test toolkit.\n");
        $this->write('vendor/fixture/toolkit/resources/boost/skills/toolkit-development/SKILL.md', "---\nname: toolkit-development\ndescription: Implement the fixture toolkit workflow.\n---\n\n# Toolkit workflow\n\nFollow this package-authored workflow.\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->project);
        parent::tearDown();
    }

    public function test_missing_boost_configuration_uses_native_index_guidelines_skills_and_mcp(): void
    {
        $report = (new BoostProjectRefresher)->refresh(['fixture/toolkit']);

        self::assertSame([], $report['failures']);
        self::assertSame([], $report['conflicts']);
        self::assertStringContainsString('.ai/rules/laracanon-actions.md', $this->read('.ai/rules/index.md'));
        self::assertStringContainsString('.ai/rules/project.md', $this->read('.ai/rules/index.md'));
        self::assertStringContainsString('@.ai/rules/index.md', $this->read('AGENTS.md'));
        self::assertStringContainsString('Package instructions for the test toolkit.', $this->read('AGENTS.md'));
        self::assertFileExists($this->project.'/.agents/skills/toolkit-development/SKILL.md');
        self::assertFileExists($this->project.'/.agents/skills/infer-conventions/SKILL.md');
        self::assertStringContainsString('[mcp_servers.laravel-boost]', $this->read('.codex/config.toml'));
        self::assertSame(['codex'], json_decode($this->read('boost.json'), true)['agents']);
    }

    public function test_selected_new_package_resources_are_opted_in_without_losing_config_or_scripts(): void
    {
        $composer = $this->read('composer.json');
        $this->writeJson('boost.json', ['agents' => ['codex'], 'guidelines' => true, 'mcp' => false, 'packages' => [], 'skills' => [], 'custom' => ['keep' => true], 'empty' => []]);
        $this->write('AGENTS.md', "# My project\n\nKeep these unrelated instructions.\n");
        $report = (new BoostProjectRefresher)->refresh(['fixture/toolkit']);

        self::assertSame([], $report['failures']);
        self::assertSame([], $report['conflicts']);
        $config = json_decode($this->read('boost.json'), true);
        self::assertSame(['fixture/toolkit'], $config['packages']);
        self::assertFalse($config['mcp']);
        self::assertSame(['keep' => true], $config['custom']);
        self::assertSame([], $config['empty']);
        self::assertSame($composer, $this->read('composer.json'));
        self::assertStringStartsWith("# My project\n\nKeep these unrelated instructions.", $this->read('AGENTS.md'));
    }

    public function test_repeat_refresh_is_idempotent_and_custom_authored_skills_are_discovered(): void
    {
        $this->write('.ai/skills/laracanon-review/SKILL.md', "---\nname: laracanon-review\ndescription: Review a change using the authored project workflow.\n---\n\n# Review\n\nTrace the request and run the relevant checks.\n");
        $first = (new BoostProjectRefresher)->refresh(['fixture/toolkit']);
        $agents = $this->read('AGENTS.md');
        $index = $this->read('.ai/rules/index.md');
        $state = $this->read('.ai/laracanon/boost-state.json');
        $statePath = $this->project.'/.ai/laracanon/boost-state.json';
        self::assertTrue(touch($statePath, 946684800));
        $second = (new BoostProjectRefresher)->refresh(['fixture/toolkit']);
        clearstatcache(true, $statePath);

        self::assertSame([], $first['failures']);
        self::assertSame([], $second['failures']);
        self::assertSame([], $second['conflicts']);
        self::assertSame($agents, $this->read('AGENTS.md'));
        self::assertSame($index, $this->read('.ai/rules/index.md'));
        self::assertSame($state, $this->read('.ai/laracanon/boost-state.json'));
        self::assertSame(946684800, filemtime($statePath));
        self::assertStringContainsString('Trace the request', $this->read('.agents/skills/laracanon-review/SKILL.md'));
        self::assertSame($this->read('.ai/skills/laracanon-review/SKILL.md'), $this->read('.agents/skills/laracanon-review/SKILL.md'));
    }

    public function test_local_package_skill_edits_conflict_but_unrelated_project_instructions_survive(): void
    {
        (new BoostProjectRefresher)->refresh(['fixture/toolkit']);
        $this->write('.agents/skills/toolkit-development/SKILL.md', "My local skill edit.\n");
        $this->write('AGENTS.md', "My new surrounding instructions.\n\n".$this->read('AGENTS.md'));
        $report = (new BoostProjectRefresher)->refresh(['fixture/toolkit']);

        self::assertSame([], $report['failures']);
        self::assertCount(1, $report['conflicts']);
        self::assertStringContainsString('.agents/skills/toolkit-development/SKILL.md', $report['conflicts'][0]);
        self::assertSame("My local skill edit.\n", $this->read('.agents/skills/toolkit-development/SKILL.md'));
        self::assertStringStartsWith('My new surrounding instructions.', $this->read('AGENTS.md'));
    }

    public function test_edited_boost_block_and_edited_rule_index_are_preserved(): void
    {
        (new BoostProjectRefresher)->refresh(['fixture/toolkit']);
        $agents = str_replace('<laravel-boost-guidelines>', "<laravel-boost-guidelines>\nMy edited Boost block.", $this->read('AGENTS.md'));
        $index = $this->read('.ai/rules/index.md')."\nMy manual index notes.\n";
        $this->write('AGENTS.md', $agents);
        $this->write('.ai/rules/index.md', $index);
        $report = (new BoostProjectRefresher)->refresh(['fixture/toolkit']);

        self::assertCount(2, $report['conflicts']);
        self::assertSame($agents, $this->read('AGENTS.md'));
        self::assertSame($index, $this->read('.ai/rules/index.md'));
    }

    public function test_existing_native_index_can_be_safely_adopted_and_extended(): void
    {
        unlink($this->project.'/.ai/rules/laracanon-actions.md');
        (new RuleRepository($this->project.'/.ai/rules'))->writeIndex();
        $this->write('.ai/rules/laracanon-actions.md', "---\npaths: [app/Actions/**]\n---\n\n# Actions\nKeep transactions in actions.\n");
        $report = (new BoostProjectRefresher)->refresh(['fixture/toolkit']);

        self::assertSame([], $report['conflicts']);
        self::assertStringContainsString('.ai/rules/laracanon-actions.md', $this->read('.ai/rules/index.md'));
        self::assertStringContainsString('.ai/rules/project.md', $this->read('.ai/rules/index.md'));
    }

    public function test_first_refresh_adopts_verified_native_guidance_and_preserves_tests_and_surrounding_instructions(): void
    {
        $this->writeJson('boost.json', ['agents' => ['claude_code', 'codex', 'junie', 'opencode'], 'guidelines' => true, 'mcp' => false, 'skills' => [], 'packages' => []]);
        $prefix = "# Project instructions\n\nPreserve this deployment policy.\n";
        $suffix = "\n# Local preferences\n\nKeep this review checklist.\n";
        $this->write('AGENTS.md', $prefix);
        $this->writeNativeGuidelines(enforceTests: true, hasSkills: false);
        $this->write('AGENTS.md', $this->read('AGENTS.md').$suffix);
        $before = $this->read('AGENTS.md');
        self::assertStringContainsString('=== tests rules ===', $before);
        self::assertStringNotContainsString('Package instructions for the test toolkit.', $before);
        self::assertFileDoesNotExist($this->project.'/.ai/laracanon/boost-state.json');

        $report = (new BoostProjectRefresher)->refresh(['fixture/toolkit']);

        self::assertSame([], $report['failures']);
        self::assertSame([], $report['conflicts']);
        $after = $this->read('AGENTS.md');
        self::assertStringContainsString('=== tests rules ===', $after);
        self::assertStringContainsString('Package instructions for the test toolkit.', $after);
        self::assertStringContainsString('## Skills Activation', $after);
        self::assertStringStartsWith(rtrim($prefix), $after);
        self::assertStringEndsWith($suffix, $after);
        self::assertCount(1, array_filter($report['resources'], fn (string $resource): bool => $resource === 'Boost guidelines refreshed in AGENTS.md'));
        $state = json_decode($this->read('.ai/laracanon/boost-state.json'), true);
        preg_match('/<laravel-boost-guidelines>.*?<\/laravel-boost-guidelines>/s', $after, $block);
        self::assertSame(hash('sha256', $block[0]), $state['hashes']['AGENTS.md#boost-guidelines']);
    }

    public function test_native_boost_update_side_effect_can_be_reconciled_when_its_marker_matches_fresh_output(): void
    {
        $this->writeJson('boost.json', ['agents' => ['codex'], 'guidelines' => true, 'mcp' => false, 'skills' => [], 'packages' => []]);
        $first = (new BoostProjectRefresher)->refresh(['fixture/toolkit']);
        self::assertSame([], $first['failures']);
        self::assertStringNotContainsString('=== tests rules ===', $this->read('AGENTS.md'));
        $oldState = $this->read('.ai/laracanon/boost-state.json');
        $this->write('AGENTS.md', "Project-owned instructions before Composer.\n\n".$this->read('AGENTS.md'));
        $this->app['config']->set('boost.enforce_tests', true);
        // Exercise the actual command used by Composer's post-update script;
        // the native writer does not know Laracanon's previous marker hash.
        $kernel = $this->app['Illuminate\\Contracts\\Console\\Kernel'];
        $kernel->registerCommand($this->app->make(InstallCommand::class));
        $kernel->registerCommand($this->app->make(UpdateCommand::class));
        self::assertSame(0, $kernel->call('boost:update', ['--no-discover' => true, '--no-interaction' => true]));
        self::assertStringContainsString('=== tests rules ===', $this->read('AGENTS.md'));
        self::assertSame($oldState, $this->read('.ai/laracanon/boost-state.json'));
        $nativeOutput = $this->read('AGENTS.md');

        $report = (new BoostProjectRefresher)->refresh(['fixture/toolkit']);

        self::assertSame([], $report['failures']);
        self::assertSame([], $report['conflicts']);
        self::assertSame($nativeOutput, $this->read('AGENTS.md'));
        self::assertStringStartsWith('Project-owned instructions before Composer.', $this->read('AGENTS.md'));
        self::assertNotSame($oldState, $this->read('.ai/laracanon/boost-state.json'));
    }

    public function test_explicit_test_enforcement_preference_updates_only_verified_native_guidance(): void
    {
        $this->writeJson('boost.json', ['agents' => ['codex'], 'guidelines' => true, 'mcp' => false, 'skills' => [], 'packages' => []]);
        $this->writeNativeGuidelines(enforceTests: true, hasSkills: false);
        $this->app['config']->set('boost.enforce_tests', false);

        $report = (new BoostProjectRefresher)->refresh(['fixture/toolkit']);

        self::assertSame([], $report['failures']);
        self::assertSame([], $report['conflicts']);
        self::assertStringNotContainsString('=== tests rules ===', $this->read('AGENTS.md'));
        self::assertFalse($this->app['config']->get('boost.enforce_tests'));
    }

    public function test_shared_guideline_path_reports_one_conflict_and_preserves_unverifiable_user_edits(): void
    {
        $this->writeJson('boost.json', ['agents' => ['claude_code', 'codex', 'junie', 'opencode'], 'guidelines' => true, 'mcp' => false, 'skills' => [], 'packages' => []]);
        $this->writeNativeGuidelines(enforceTests: true, hasSkills: false);
        $existing = str_replace('# Test Enforcement', "# Test Enforcement\n\nMy project-specific test policy.", $this->read('AGENTS.md'));
        $this->write('AGENTS.md', $existing);

        $report = (new BoostProjectRefresher)->refresh(['fixture/toolkit']);

        self::assertSame([], $report['failures']);
        self::assertCount(1, $report['conflicts']);
        self::assertStringContainsString('Boost block in AGENTS.md', $report['conflicts'][0]);
        self::assertSame($existing, $this->read('AGENTS.md'));
        $state = json_decode($this->read('.ai/laracanon/boost-state.json'), true);
        self::assertArrayNotHasKey('AGENTS.md#boost-guidelines', $state['hashes']);
    }

    public function test_global_rules_with_catch_all_glob_are_discoverable_in_native_index(): void
    {
        $this->write('.ai/rules/laracanon-global.md', "---\npaths: ['**']\n---\n\n# Global project preferences\n\nRead project rules before editing.\n");
        $report = (new BoostProjectRefresher)->refresh(['fixture/toolkit']);

        self::assertSame([], $report['failures']);
        self::assertStringContainsString('| ** | .ai/rules/laracanon-global.md |', $this->read('.ai/rules/index.md'));
        self::assertStringContainsString('@.ai/rules/index.md', $this->read('AGENTS.md'));
    }

    public function test_unknown_existing_boost_block_is_not_silently_replaced(): void
    {
        $existing = "Project notes.\n\n<laravel-boost-guidelines>\nUnknown existing user-edited guidance.\n</laravel-boost-guidelines>\n";
        $this->write('AGENTS.md', $existing);
        $report = (new BoostProjectRefresher)->refresh(['fixture/toolkit']);

        self::assertCount(1, $report['conflicts']);
        self::assertSame($existing, $this->read('AGENTS.md'));
        self::assertFileExists($this->project.'/.agents/skills/toolkit-development/SKILL.md');
    }

    public function test_malformed_skill_reports_partial_failure_while_other_resources_complete(): void
    {
        $this->write('vendor/fixture/toolkit/resources/boost/skills/broken/SKILL.md', "---\nname: broken\ndescription: [invalid\n---\n\nInstructions.\n");
        $report = (new BoostProjectRefresher)->refresh(['fixture/toolkit']);

        self::assertNotEmpty($report['failures']);
        self::assertStringContainsString('could not parse skill', implode("\n", $report['failures']));
        self::assertFileExists($this->project.'/.agents/skills/toolkit-development/SKILL.md');
        self::assertFileExists($this->project.'/.ai/rules/index.md');
    }

    public function test_invalid_existing_config_has_no_output_changes(): void
    {
        $this->write('boost.json', '{broken');
        $report = (new BoostProjectRefresher)->refresh(['fixture/toolkit']);

        self::assertNotEmpty($report['failures']);
        self::assertSame('{broken', $this->read('boost.json'));
        self::assertFileDoesNotExist($this->project.'/AGENTS.md');
        self::assertFileDoesNotExist($this->project.'/.ai/rules/index.md');
    }

    public function test_existing_mcp_server_and_other_servers_are_preserved(): void
    {
        $mcp = "model = \"my-model\"\n\n[mcp_servers.other]\ncommand = \"my-server\"\n\n[mcp_servers.laravel-boost]\ncommand = \"my-php\"\nargs = [\"artisan\", \"boost:mcp\"]\n";
        $this->write('.codex/config.toml', $mcp);
        $report = (new BoostProjectRefresher)->refresh(['fixture/toolkit']);

        self::assertSame([], $report['failures']);
        self::assertSame($mcp, $this->read('.codex/config.toml'));
    }

    public function test_comment_mentioning_mcp_does_not_prevent_missing_server_registration(): void
    {
        $this->write('.codex/config.toml', "# laravel-boost is not configured yet\nmodel = \"my-model\"\n");
        $report = (new BoostProjectRefresher)->refresh(['fixture/toolkit']);

        self::assertSame([], $report['failures']);
        self::assertStringContainsString('[mcp_servers.laravel-boost]', $this->read('.codex/config.toml'));
        self::assertStringContainsString('model = "my-model"', $this->read('.codex/config.toml'));
    }

    public function test_scoped_package_guidance_conflict_preserves_rule_and_keeps_new_guidance_inline(): void
    {
        $this->app['config']->set('boost.rules.scoped_guidelines', true);
        $this->write('vendor/fixture/toolkit/resources/boost/guidelines/scoped.blade.php', "@scoped(['app/Actions/**'])\n# Package scoped rules\n\nOld package advice.\n@endscoped\n");
        $first = (new BoostProjectRefresher)->refresh(['fixture/toolkit']);
        self::assertSame([], $first['failures']);
        $scopedFiles = File::glob($this->project.'/.ai/rules/boost/*.md');
        self::assertNotEmpty($scopedFiles);
        $target = array_values(array_filter($scopedFiles, fn ($path) => str_contains((string) file_get_contents($path), 'Old package advice.')))[0];
        file_put_contents($target, (string) file_get_contents($target)."\nLocal scoped-rule edit.\n");
        $this->write('vendor/fixture/toolkit/resources/boost/guidelines/scoped.blade.php', "@scoped(['app/Actions/**'])\n# Package scoped rules\n\nNew package advice.\n@endscoped\n");
        $second = (new BoostProjectRefresher)->refresh(['fixture/toolkit']);

        self::assertSame([], $second['failures']);
        self::assertNotEmpty($second['conflicts']);
        self::assertStringContainsString('Local scoped-rule edit.', (string) file_get_contents($target));
        self::assertStringContainsString('New package advice.', $this->read('AGENTS.md'));
    }

    public function test_retired_authored_skill_is_removed_only_when_unchanged(): void
    {
        $this->write('.ai/skills/laracanon-retired/SKILL.md', "---\nname: laracanon-retired\ndescription: An authored workflow.\n---\n\n# Workflow\n\nImplement the change.\n");
        (new BoostProjectRefresher)->refresh(['fixture/toolkit']);
        self::assertFileExists($this->project.'/.agents/skills/laracanon-retired/SKILL.md');
        File::deleteDirectory($this->project.'/.ai/skills/laracanon-retired');
        $report = (new BoostProjectRefresher)->refresh(['fixture/toolkit']);

        self::assertSame([], $report['failures']);
        self::assertSame([], $report['conflicts']);
        self::assertFileDoesNotExist($this->project.'/.agents/skills/laracanon-retired/SKILL.md');
        self::assertNotContains('laracanon-retired', json_decode($this->read('boost.json'), true)['skills']);
    }

    public function test_retired_edited_skill_is_preserved_and_reported(): void
    {
        $this->write('.ai/skills/laracanon-retired/SKILL.md', "---\nname: laracanon-retired\ndescription: An authored workflow.\n---\n\n# Workflow\n\nImplement the change.\n");
        (new BoostProjectRefresher)->refresh(['fixture/toolkit']);
        $this->write('.agents/skills/laracanon-retired/SKILL.md', "My local workflow.\n");
        File::deleteDirectory($this->project.'/.ai/skills/laracanon-retired');
        $report = (new BoostProjectRefresher)->refresh(['fixture/toolkit']);

        self::assertSame([], $report['failures']);
        self::assertCount(1, $report['conflicts']);
        self::assertSame("My local workflow.\n", $this->read('.agents/skills/laracanon-retired/SKILL.md'));
        self::assertContains('laracanon-retired', json_decode($this->read('boost.json'), true)['skills']);
    }

    public function test_parse_failure_prevents_retiring_an_existing_skill(): void
    {
        (new BoostProjectRefresher)->refresh(['fixture/toolkit']);
        $before = $this->read('.agents/skills/toolkit-development/SKILL.md');
        $this->write('vendor/fixture/toolkit/resources/boost/skills/toolkit-development/SKILL.md', "---\nname: toolkit-development\ndescription: [invalid\n---\n\nWorkflow.\n");
        $report = (new BoostProjectRefresher)->refresh(['fixture/toolkit']);

        self::assertNotEmpty($report['failures']);
        self::assertSame($before, $this->read('.agents/skills/toolkit-development/SKILL.md'));
        self::assertContains('toolkit-development', json_decode($this->read('boost.json'), true)['skills']);
    }

    public function test_malformed_ownership_hash_is_rejected_before_boost_outputs_change(): void
    {
        $this->writeJson('.ai/laracanon/boost-state.json', ['version' => 1, 'hashes' => ['AGENTS.md#boost-guidelines' => ['nested' => 'invalid']]]);
        $this->write('AGENTS.md', "My existing instructions.\n");
        $state = $this->read('.ai/laracanon/boost-state.json');
        $report = (new BoostProjectRefresher)->refresh(['fixture/toolkit']);

        self::assertNotEmpty($report['failures']);
        self::assertSame("My existing instructions.\n", $this->read('AGENTS.md'));
        self::assertSame($state, $this->read('.ai/laracanon/boost-state.json'));
        self::assertFileDoesNotExist($this->project.'/.ai/rules/index.md');
        self::assertFileDoesNotExist($this->project.'/boost.json');
    }

    private function write(string $path, string $contents): void
    {
        File::ensureDirectoryExists(dirname($this->project.'/'.$path));
        file_put_contents($this->project.'/'.$path, $contents);
    }

    private function writeNativeGuidelines(bool $enforceTests, bool $hasSkills): void
    {
        app(ProjectManager::class)->fresh();
        $options = new GuidelineConfig;
        $options->aiGuidelines = [];
        $options->enforceTests = $enforceTests;
        $options->hasSkills = $hasSkills;
        $guidelines = app(GuidelineComposer::class)->config($options)->compose();
        self::assertNotSame('', $guidelines, implode("\n", app(RenderFailures::class)->paths()));
        (new GuidelineWriter(Agent::fromName('codex')))->write($guidelines);
    }

    private function writeJson(string $path, array $value): void
    {
        $this->write($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function read(string $path): string
    {
        return (string) file_get_contents($this->project.'/'.$path);
    }
}
