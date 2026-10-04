<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Tests\Unit\Items;

use Isaachatilima\Laracanon\Items\ItemCatalog;
use Isaachatilima\Laracanon\Items\ItemFormatException;
use Isaachatilima\Laracanon\Items\ItemNotFoundException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ItemCatalogTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/laracanon-items-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function test_adding_a_markdown_file_adds_an_item_without_manifest_changes(): void
    {
        $catalog = new ItemCatalog($this->directory);
        self::assertSame([], $catalog->all());

        $this->writeItem('second');
        $this->writeItem('first');
        file_put_contents($this->directory.'/ignored.txt', 'not an item');

        self::assertSame(['first', 'second'], array_keys($catalog->all()));
        self::assertSame('second', $catalog->find('second')->name);
    }

    public function test_unknown_item_has_a_clear_error(): void
    {
        $this->expectException(ItemNotFoundException::class);
        $this->expectExceptionMessage('Unknown item "missing". Run php artisan canon:list');

        (new ItemCatalog($this->directory))->find('missing');
    }

    public function test_invalid_item_names_are_reported_with_the_source_path(): void
    {
        file_put_contents($this->directory.'/bad.md', "---\nname: other\ndescription: Example\npaths: []\n---\n");

        $this->expectException(ItemFormatException::class);
        $this->expectExceptionMessage($this->directory.'/bad.md: name must match');

        (new ItemCatalog($this->directory))->all();
    }

    public function test_a_missing_directory_is_reported_clearly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Item directory does not exist');

        (new ItemCatalog($this->directory.'/missing'))->all();
    }

    public function test_demonstrations_live_in_a_separate_fixture_catalog(): void
    {
        $fixtures = (new ItemCatalog(dirname(__DIR__, 2).'/Fixtures/Items'))->all();
        self::assertNull($fixtures['fixture-rules-only']->skill);
        self::assertSame([], $fixtures['fixture-rules-only']->dependencies);
        self::assertNotNull($fixtures['fixture-rules-only']->examples);
        self::assertSame('runtime', $fixtures['fixture-runtime-package']->dependencies[0]->type);
        self::assertNull($fixtures['fixture-runtime-package']->skill);
        self::assertSame('development', $fixtures['fixture-dev-package']->dependencies[0]->type);
        self::assertNotNull($fixtures['fixture-authored-workflow']->skill);
        self::assertSame([], $fixtures['fixture-authored-workflow']->dependencies);

        $production = (new ItemCatalog(dirname(__DIR__, 3).'/resources/items'))->all();
        self::assertNotEmpty($production);
        foreach ($production as $name => $item) {
            self::assertFalse($item->sample);
            self::assertArrayNotHasKey($name, $fixtures);
        }
    }

    private function writeItem(string $name): void
    {
        file_put_contents($this->directory.'/'.$name.'.md', "---\nname: ".$name."\ndescription: A sample.\npaths: []\n---\n");
    }
}
