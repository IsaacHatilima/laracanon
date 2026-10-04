<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Items;

use RuntimeException;

final class ItemCatalog
{
    private readonly ItemParser $parser;

    public function __construct(private readonly string $directory, ?ItemParser $parser = null)
    {
        $this->parser = $parser ?? new ItemParser;
    }

    /** @return array<string, Item> */
    public function all(): array
    {
        if (! is_dir($this->directory)) {
            throw new RuntimeException('Item directory does not exist: '.$this->directory.'.');
        }

        $files = glob(rtrim($this->directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*.md');
        if ($files === false) {
            throw new RuntimeException('Cannot read item directory: '.$this->directory.'.');
        }

        sort($files, SORT_STRING);
        $items = [];
        foreach ($files as $file) {
            $markdown = file_get_contents($file);
            if ($markdown === false) {
                throw new RuntimeException('Cannot read item file: '.$file.'.');
            }

            $item = $this->parser->parse($markdown, $file);
            $items[$item->name] = $item;
        }

        return $items;
    }

    public function find(string $name): Item
    {
        $items = $this->all();

        return $items[$name] ?? throw new ItemNotFoundException('Unknown item "'.$name.'". Run php artisan canon:list to see available items.');
    }
}
