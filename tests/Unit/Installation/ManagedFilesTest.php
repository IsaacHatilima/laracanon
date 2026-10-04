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
}
