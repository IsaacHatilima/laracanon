<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Tests\Unit\Installation;

use Isaachatilima\Laracanon\Installation\ManagedFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ManagedFilesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/laracanon-managed-'.bin2hex(random_bytes(8));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    #[DataProvider('configurationDestinations')]
    public function test_configuration_is_stored_as_literal_bytes_and_can_adopt_identical_unowned_content(string $relative): void
    {
        $path = $this->root.'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        $contents = "<?php\nfile_put_contents(__DIR__.'/executed', 'unsafe execution');\nreturn ['enabled' => true];\n";
        file_put_contents($path, $contents);
        $files = new ManagedFiles($this->root, false);

        self::assertSame('unchanged', $files->put($relative, $contents, 'quality'));
        $files->record('quality', ['source_hash' => hash('sha256', 'source'), 'files' => [$relative], 'status' => 'installed']);
        $files->save();

        self::assertSame($contents, file_get_contents($path));
        self::assertFileDoesNotExist(dirname($path).'/executed');
        self::assertSame('quality', (new ManagedFiles($this->root, false))->owner($relative));
    }

    public static function configurationDestinations(): array
    {
        return [['config/quality/insights.php'], ['phpstan.neon'], ['phpstan.neon.dist']];
    }

    #[DataProvider('unsafePaths')]
    public function test_noncanonical_or_out_of_scope_paths_are_rejected(string $path): void
    {
        $files = new ManagedFiles($this->root, false);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsafe managed path');

        $files->put($path, '<?php return [];', 'quality');
    }

    public static function unsafePaths(): array
    {
        return [
            'outside supported roots' => ['app/config.php'],
            'root only' => ['config'],
            'absolute' => ['/config/insights.php'],
            'backslash' => ['config\\insights.php'],
            'empty segment' => ['config//insights.php'],
            'current directory segment' => ['config/./insights.php'],
            'parent traversal' => ['config/../composer.json'],
            'bare dot' => ['config/.'],
            'bare parent' => ['config/..'],
            'trailing slash' => ['config/insights.php/'],
            'trailing newline' => ["config/insights.php\n"],
            'control byte' => ["config/a\x00.php"],
            'unicode segment' => ['config/qualité.php'],
            'ai alias' => ['.ai/rules/./quality.md'],
            'root neon current directory alias' => ['./phpstan.neon.dist'],
            'root neon parent traversal' => ['../phpstan.neon.dist'],
            'root neon absolute' => ['/phpstan.neon.dist'],
            'root neon subdirectory' => ['quality/phpstan.neon.dist'],
            'root neon hidden name' => ['.phpstan.neon.dist'],
            'root neon wrong suffix' => ['phpstan.neon.dist.bak'],
            'root neon trailing newline' => ["phpstan.neon.dist\n"],
        ];
    }

    #[DataProvider('symlinkPaths')]
    public function test_configuration_symlinks_are_rejected_at_every_component(string $relative): void
    {
        mkdir($this->root.'/original');
        file_put_contents($this->root.'/original/insights.php', 'original user file');
        if ($relative !== 'config') {
            mkdir($this->root.'/config');
        }
        symlink($this->root.'/original'.($relative === 'config/insights.php' ? '/insights.php' : ''), $this->root.'/'.$relative);
        $files = new ManagedFiles($this->root, false);
        try {
            $files->put($relative === 'config/quality' ? 'config/quality/insights.php' : 'config/insights.php', 'replacement', 'quality');
            self::fail('A configuration symlink must not be followed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Refusing symlink', $exception->getMessage());
        }
        self::assertSame('original user file', file_get_contents($this->root.'/original/insights.php'));
    }

    public static function symlinkPaths(): array
    {
        return [['config'], ['config/quality'], ['config/insights.php']];
    }

    public function test_root_neon_symlink_is_rejected_without_touching_its_target(): void
    {
        file_put_contents($this->root.'/original.neon', 'original configuration');
        symlink($this->root.'/original.neon', $this->root.'/phpstan.neon.dist');
        $files = new ManagedFiles($this->root, false);
        try {
            $files->put('phpstan.neon.dist', 'replacement', 'quality');
            self::fail('A root configuration symlink must not be followed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Refusing symlink', $exception->getMessage());
        }
        self::assertSame('original configuration', file_get_contents($this->root.'/original.neon'));
    }

    public function test_existing_ai_destinations_still_work(): void
    {
        $files = new ManagedFiles($this->root, false);
        self::assertSame('installed', $files->put('.ai/rules/laracanon-quality.md', '# Rule', 'quality'));
        self::assertSame('# Rule', file_get_contents($this->root.'/.ai/rules/laracanon-quality.md'));
    }

    #[DataProvider('configurationUmasks')]
    public function test_new_configuration_files_respect_the_project_umask(int $mask, int $expected, string $relative): void
    {
        $previous = umask($mask);
        try {
            $files = new ManagedFiles($this->root, false);
            self::assertSame('installed', $files->put($relative, "<?php\nreturn [];\n", 'quality'));
            clearstatcache(true, $this->root.'/'.$relative);
            self::assertSame($expected, fileperms($this->root.'/'.$relative) & 0777);
        } finally {
            umask($previous);
        }
    }

    public static function configurationUmasks(): array
    {
        return [
            'standard permissions' => [0022, 0644, 'config/quality.php'],
            'group-readable project' => [0027, 0640, 'config/quality.php'],
            'private project' => [0077, 0600, 'config/quality.php'],
            'root neon standard permissions' => [0022, 0644, 'phpstan.neon.dist'],
            'root neon group-readable project' => [0027, 0640, 'phpstan.neon.dist'],
            'root neon private project' => [0077, 0600, 'phpstan.neon.dist'],
        ];
    }

    #[DataProvider('configurationModes')]
    public function test_configuration_updates_preserve_existing_permissions(int $mode, string $relative): void
    {
        $files = new ManagedFiles($this->root, false);
        $path = $this->root.'/'.$relative;
        self::assertSame('installed', $files->put($relative, "<?php\nreturn ['version' => 1];\n", 'quality'));
        self::assertTrue(chmod($path, $mode));
        $previous = umask(0077);
        try {
            $updated = "<?php\nreturn ['version' => 2];\n";
            self::assertSame('updated', $files->put($relative, $updated, 'quality'));
            clearstatcache(true, $path);
            self::assertSame($mode, fileperms($path) & 0777);
            self::assertSame($updated, file_get_contents($path));
        } finally {
            umask($previous);
        }
    }

    public static function configurationModes(): array
    {
        return [[0644, 'config/quality.php'], [0640, 'config/quality.php'], [0600, 'config/quality.php'], [0644, 'phpstan.neon.dist'], [0640, 'phpstan.neon.dist'], [0600, 'phpstan.neon.dist']];
    }

    public function test_ai_writes_keep_their_existing_private_permissions(): void
    {
        $previous = umask(0022);
        try {
            $files = new ManagedFiles($this->root, false);
            self::assertSame('installed', $files->put('.ai/rules/laracanon-quality.md', '# Rule', 'quality'));
            $path = $this->root.'/.ai/rules/laracanon-quality.md';
            clearstatcache(true, $path);
            self::assertSame(0600, fileperms($path) & 0777);
        } finally {
            umask($previous);
        }
    }

    public function test_neon_destination_resolution_uses_existing_candidates_in_order_without_creating_files(): void
    {
        $files = new ManagedFiles($this->root, false);
        $candidates = ['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'];
        self::assertSame('phpstan.neon', $files->resolveNeonPath($candidates));
        self::assertFalse($files->exists('phpstan.neon'));
        file_put_contents($this->root.'/phpstan.dist.neon', "parameters:\n    level: 4\n");
        self::assertSame('phpstan.dist.neon', $files->resolveNeonPath($candidates));
        file_put_contents($this->root.'/phpstan.neon.dist', "parameters:\n    level: 5\n");
        self::assertSame('phpstan.neon.dist', $files->resolveNeonPath($candidates));
        file_put_contents($this->root.'/phpstan.neon', "parameters:\n    level: 6\n");
        self::assertSame('phpstan.neon', $files->resolveNeonPath($candidates));
        self::assertTrue($files->exists('phpstan.neon'));
        self::assertDirectoryDoesNotExist($this->root.'/.ai');
    }

    public function test_existing_neon_configuration_adopts_only_changed_fields_and_preserves_bytes_and_mode(): void
    {
        $path = $this->root.'/phpstan.neon';
        $contents = "# Keep CRLF and this comment.\r\nincludes:\r\n    - vendor/example/extension.neon\r\n\r\nparameters:\r\n    level: 6 # Keep this inline comment.\r\n    paths: [app/Domain]\r\n    tmpDir: storage/custom-cache\r\n";
        file_put_contents($path, $contents);
        chmod($path, 0640);
        $files = new ManagedFiles($this->root, false);

        self::assertSame('updated', $files->updateNeon('phpstan.neon', ['parameters.level' => 10], 'phpstan'));
        $this->recordPhpstan($files, ['phpstan.neon']);
        $files->save();

        self::assertSame(str_replace('level: 6 #', 'level: 10 #', $contents), file_get_contents($path));
        clearstatcache(true, $path);
        self::assertSame(0640, fileperms($path) & 0777);
        self::assertSame('phpstan', (new ManagedFiles($this->root, false))->owner('phpstan.neon'));
        $state = json_decode((string) file_get_contents($this->root.'/.ai/laracanon/state.json'), true);
        self::assertSame(2, $state['schema']);
        self::assertArrayHasKey('neon_fields', $state['files']['phpstan.neon']);
        self::assertSame(['parameters.level'], array_keys($state['files']['phpstan.neon']['neon_fields']));
    }

    public function test_existing_neon_unrelated_edits_do_not_conflict_or_rewrite_the_ownership_state(): void
    {
        $path = $this->root.'/phpstan.neon';
        file_put_contents($path, "parameters:\n    level: 6\n    paths: [app/Domain]\n");
        $files = new ManagedFiles($this->root, false);
        self::assertSame('updated', $files->updateNeon('phpstan.neon', ['parameters.level' => 10], 'phpstan'));
        $this->recordPhpstan($files, ['phpstan.neon']);
        $files->save();
        $state = (string) file_get_contents($this->root.'/.ai/laracanon/state.json');
        $edited = str_replace('app/Domain', 'app/NewDomain', (string) file_get_contents($path))."# User comment\n";
        file_put_contents($path, $edited);
        $repeat = new ManagedFiles($this->root, false);

        self::assertSame('unchanged', $repeat->updateNeon('phpstan.neon', ['parameters.level' => 10], 'phpstan'));
        $repeat->save();

        self::assertSame($edited, file_get_contents($path));
        self::assertSame($state, file_get_contents($this->root.'/.ai/laracanon/state.json'));
    }

    public function test_an_edited_managed_neon_value_conflicts_and_preserves_the_file_and_baseline(): void
    {
        $path = $this->root.'/phpstan.neon';
        file_put_contents($path, "parameters:\n    level: 6\n");
        $files = new ManagedFiles($this->root, false);
        self::assertSame('updated', $files->updateNeon('phpstan.neon', ['parameters.level' => 10], 'phpstan'));
        $this->recordPhpstan($files, ['phpstan.neon']);
        $files->save();
        $state = (string) file_get_contents($this->root.'/.ai/laracanon/state.json');
        $edited = "parameters:\n    level: 8\n";
        file_put_contents($path, $edited);
        $repeat = new ManagedFiles($this->root, false);

        self::assertSame('conflict', $repeat->updateNeon('phpstan.neon', ['parameters.level' => 10], 'phpstan'));
        $repeat->save();

        self::assertSame($edited, file_get_contents($path));
        self::assertSame($state, file_get_contents($this->root.'/.ai/laracanon/state.json'));
    }

    public function test_literal_writes_cannot_take_over_a_field_owned_neon_configuration(): void
    {
        $path = $this->root.'/phpstan.neon';
        file_put_contents($path, "parameters:\n    level: 6\n    paths: [app/Domain]\n");
        $files = new ManagedFiles($this->root, false);
        self::assertSame('updated', $files->updateNeon('phpstan.neon', ['parameters.level' => 10], 'phpstan'));
        $before = (string) file_get_contents($path);

        self::assertSame('conflict', $files->put('phpstan.neon', "parameters:\n    level: 10\n", 'phpstan'));
        self::assertSame($before, file_get_contents($path));
    }

    public function test_updating_a_full_owned_neon_file_retains_full_file_ownership_and_conflict_detection(): void
    {
        $files = new ManagedFiles($this->root, false);
        self::assertSame('installed', $files->put('phpstan.neon', "parameters:\n    level: 6\n", 'phpstan'));
        $this->recordPhpstan($files, ['phpstan.neon']);
        $files->save();
        $repeat = new ManagedFiles($this->root, false);
        self::assertSame('updated', $repeat->updateNeon('phpstan.neon', ['parameters.level' => 10], 'phpstan'));
        $repeat->save();
        $state = json_decode((string) file_get_contents($this->root.'/.ai/laracanon/state.json'), true);
        self::assertArrayNotHasKey('neon_fields', $state['files']['phpstan.neon']);
        $edited = "parameters:\n    level: 10\n# A local edit\n";
        file_put_contents($this->root.'/phpstan.neon', $edited);

        self::assertSame('conflict', (new ManagedFiles($this->root, false))->updateNeon('phpstan.neon', ['parameters.level' => 10], 'phpstan'));
        self::assertSame($edited, file_get_contents($this->root.'/phpstan.neon'));
    }

    public function test_releasing_a_field_owned_neon_configuration_keeps_the_existing_project_file(): void
    {
        $path = $this->root.'/phpstan.neon';
        file_put_contents($path, "parameters:\n    level: 6\n    paths: [app/Domain]\n");
        $files = new ManagedFiles($this->root, false);
        self::assertSame('updated', $files->updateNeon('phpstan.neon', ['parameters.level' => 10], 'phpstan'));
        $this->recordPhpstan($files, ['phpstan.neon']);
        $files->save();
        $contents = (string) file_get_contents($path);
        $repeat = new ManagedFiles($this->root, false);

        self::assertSame('released', $repeat->remove('phpstan.neon', 'phpstan'));
        $this->recordPhpstan($repeat, []);
        $repeat->save();

        self::assertSame($contents, file_get_contents($path));
        self::assertNull((new ManagedFiles($this->root, false))->owner('phpstan.neon'));
    }

    public function test_neon_dry_run_does_not_write_configuration_or_ownership_state(): void
    {
        $path = $this->root.'/phpstan.neon';
        $contents = "parameters:\n    level: 6\n    paths: [app/Domain]\n";
        file_put_contents($path, $contents);
        $files = new ManagedFiles($this->root, true);

        self::assertSame('updated', $files->updateNeon('phpstan.neon', ['parameters.level' => 10], 'phpstan'));
        $this->recordPhpstan($files, ['phpstan.neon']);
        $files->save();

        self::assertSame($contents, file_get_contents($path));
        self::assertDirectoryDoesNotExist($this->root.'/.ai');
    }

    public function test_malformed_neon_configuration_is_never_written_or_claimed(): void
    {
        $path = $this->root.'/phpstan.neon';
        $contents = "parameters:\n    level: [\n";
        file_put_contents($path, $contents);
        $files = new ManagedFiles($this->root, false);
        try {
            $files->updateNeon('phpstan.neon', ['parameters.level' => 10], 'phpstan');
            self::fail('Malformed existing NEON must be reported.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('NEON', $exception->getMessage());
        }

        self::assertSame($contents, file_get_contents($path));
        self::assertNull($files->owner('phpstan.neon'));
        self::assertDirectoryDoesNotExist($this->root.'/.ai');
    }

    public function test_empty_neon_updates_are_rejected_without_config_or_state_mutations(): void
    {
        $path = $this->root.'/phpstan.neon';
        $contents = "parameters:\n    level: 6\n";
        file_put_contents($path, $contents);
        $files = new ManagedFiles($this->root, false);

        try {
            $files->updateNeon('phpstan.neon', [], 'phpstan');
            self::fail('Empty updates must not create invalid field ownership.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('NEON', $exception->getMessage());
        }

        self::assertSame($contents, file_get_contents($path));
        self::assertNull($files->owner('phpstan.neon'));
        self::assertDirectoryDoesNotExist($this->root.'/.ai');
    }

    public function test_neon_updates_respect_another_items_ownership(): void
    {
        $files = new ManagedFiles($this->root, false);
        $contents = "parameters:\n    level: 6\n";
        self::assertSame('installed', $files->put('phpstan.neon', $contents, 'other'));

        self::assertSame('conflict', $files->updateNeon('phpstan.neon', ['parameters.level' => 10], 'phpstan'));
        self::assertSame($contents, file_get_contents($this->root.'/phpstan.neon'));
    }

    public function test_neon_candidate_symlinks_are_rejected_without_touching_the_target(): void
    {
        $contents = "parameters:\n    level: 6\n";
        file_put_contents($this->root.'/original.neon', $contents);
        symlink($this->root.'/original.neon', $this->root.'/phpstan.neon');
        $files = new ManagedFiles($this->root, false);
        try {
            $files->resolveNeonPath(['phpstan.neon', 'phpstan.neon.dist']);
            self::fail('An active configuration symlink must not be followed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Refusing symlink', $exception->getMessage());
        }
        self::assertSame($contents, file_get_contents($this->root.'/original.neon'));
    }

    public function test_retirement_keeps_previous_configs_referenced_by_indirect_neon_includes(): void
    {
        $files = new ManagedFiles($this->root, false);
        self::assertSame('installed', $files->put('phpstan.neon.dist', "parameters:\n    level: 10\n", 'phpstan'));
        self::assertSame('installed', $files->put('phpstan.dist.neon', "parameters:\n    level: 5\n", 'phpstan'));
        file_put_contents($this->root.'/phpstan.neon', "includes:\n    - project-analysis.neon\nparameters:\n    level: 10\n");
        file_put_contents($this->root.'/project-analysis.neon', "includes:\n    - phpstan.neon.dist\n");

        self::assertSame(['phpstan.neon.dist'], $files->retainedNeonIncludes('phpstan.neon', ['phpstan.neon.dist', 'phpstan.dist.neon']));
        self::assertSame("parameters:\n    level: 10\n", file_get_contents($this->root.'/phpstan.neon.dist'));
    }

    public function test_dry_run_include_scan_uses_proposed_contents_instead_of_the_existing_file(): void
    {
        $files = new ManagedFiles($this->root, true);
        $active = "parameters:\n    level: 10\n";
        $previous = "parameters:\n    level: 6\n";
        file_put_contents($this->root.'/phpstan.neon', $active);
        file_put_contents($this->root.'/phpstan.neon.dist', $previous);
        $proposed = "includes:\n    - phpstan.neon.dist\nparameters:\n    level: 10\n";

        self::assertSame(['phpstan.neon.dist'], $files->retainedNeonIncludes('phpstan.neon', ['phpstan.neon.dist'], $proposed));
        self::assertSame($active, file_get_contents($this->root.'/phpstan.neon'));
        self::assertSame($previous, file_get_contents($this->root.'/phpstan.neon.dist'));
        self::assertDirectoryDoesNotExist($this->root.'/.ai');

        unlink($this->root.'/phpstan.neon');
        self::assertSame(['phpstan.neon.dist'], $files->retainedNeonIncludes('phpstan.neon', ['phpstan.neon.dist'], $proposed), 'A not-yet-created config must use the planned contents too.');
        self::assertFileDoesNotExist($this->root.'/phpstan.neon');
    }

    public function test_neon_scalar_ownership_retains_float_types_when_state_is_saved_and_reloaded(): void
    {
        $path = $this->root.'/phpstan.neon';
        file_put_contents($path, "parameters:\n    threshold: 0.5\n");
        $files = new ManagedFiles($this->root, false);
        self::assertSame('updated', $files->updateNeon('phpstan.neon', ['parameters.threshold' => 1.0], 'phpstan'));
        $this->recordPhpstan($files, ['phpstan.neon']);
        $files->save();
        $state = (string) file_get_contents($this->root.'/.ai/laracanon/state.json');
        $contents = (string) file_get_contents($path);
        $repeat = new ManagedFiles($this->root, false);

        self::assertSame('unchanged', $repeat->updateNeon('phpstan.neon', ['parameters.threshold' => 1.0], 'phpstan'));
        $repeat->save();

        self::assertSame($contents, file_get_contents($path));
        self::assertSame($state, file_get_contents($this->root.'/.ai/laracanon/state.json'));
    }

    #[DataProvider('invalidNeonFieldMetadata')]
    public function test_invalid_neon_field_metadata_is_rejected_before_any_mutation(mixed $metadata): void
    {
        $path = $this->root.'/phpstan.neon';
        $contents = "parameters:\n    level: 6\n";
        file_put_contents($path, $contents);
        $files = new ManagedFiles($this->root, false);
        self::assertSame('updated', $files->updateNeon('phpstan.neon', ['parameters.level' => 10], 'phpstan'));
        $this->recordPhpstan($files, ['phpstan.neon']);
        $files->save();
        $statePath = $this->root.'/.ai/laracanon/state.json';
        $state = json_decode((string) file_get_contents($statePath), true);
        $state['files']['phpstan.neon']['neon_fields'] = $metadata;
        $invalid = json_encode($state, JSON_THROW_ON_ERROR);
        file_put_contents($statePath, $invalid);
        $before = (string) file_get_contents($path);

        try {
            new ManagedFiles($this->root, false);
            self::fail('Malformed scalar ownership metadata must block installation.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Invalid Laracanon ownership state', $exception->getMessage());
        }
        self::assertSame($invalid, file_get_contents($statePath));
        self::assertSame($before, file_get_contents($path));
    }

    /** @return array<string, array{mixed}> */
    public static function invalidNeonFieldMetadata(): array
    {
        return [
            'not a mapping' => ['invalid field ownership'],
            'non scalar baseline' => [['parameters.level' => ['exists' => true, 'value' => []]]],
            'non boolean presence' => [['parameters.level' => ['exists' => 'yes', 'value' => 10]]],
            'absent field with a value' => [['parameters.level' => ['exists' => false, 'value' => 10]]],
            'overlapping owned paths' => [[
                'parameters' => ['exists' => true, 'value' => null],
                'parameters.level' => ['exists' => true, 'value' => 10],
            ]],
        ];
    }

    private function recordPhpstan(ManagedFiles $files, array $paths): void
    {
        $files->record('phpstan', ['source_hash' => hash('sha256', 'phpstan source'), 'files' => $paths, 'status' => 'installed']);
    }
}
