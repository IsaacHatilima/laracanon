<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Tests\Unit\Items;

use Isaachatilima\Laracanon\Items\ItemCatalog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LibraryCatalogTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/laracanon-library-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        file_put_contents($this->directory.'/source.md', 'Shared instructions.');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function test_source_changes_update_the_owned_item_hash(): void
    {
        $this->catalog(['.agents/skills/laravel-actions/SKILL.md' => 'source.md']);
        $catalog = new ItemCatalog($this->directory);
        $item = $catalog->find('laravel-actions');
        self::assertSame('Shared instructions.', $item->files['.agents/skills/laravel-actions/SKILL.md']);
        file_put_contents($this->directory.'/source.md', 'Updated instructions.');
        self::assertNotSame($item->sourceHash, $catalog->find('laravel-actions')->sourceHash);
    }

    public function test_rejects_writes_outside_native_skill_and_agent_directories(): void
    {
        $this->catalog(['composer.json' => 'source.md']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsafe library');
        (new ItemCatalog($this->directory))->all();
    }

    public function test_rejects_source_path_traversal(): void
    {
        $this->catalog(['.agents/skills/laravel-actions/SKILL.md' => '../source.md']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsafe library source');
        (new ItemCatalog($this->directory))->all();
    }

    public function test_rejects_symlinked_sources(): void
    {
        symlink($this->directory.'/source.md', $this->directory.'/linked.md');
        $this->catalog(['.agents/skills/laravel-actions/SKILL.md' => 'linked.md']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Symlink in library source');
        (new ItemCatalog($this->directory))->all();
    }

    /** @param array<string, string> $files */
    private function catalog(array $files): void
    {
        file_put_contents($this->directory.'/catalog.json', json_encode([
            'schema' => 1,
            'items' => [['name' => 'laravel-actions', 'description' => 'Shared Laravel actions.', 'files' => $files]],
        ], JSON_THROW_ON_ERROR));
    }
}
