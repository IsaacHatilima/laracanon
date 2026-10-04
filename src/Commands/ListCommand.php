<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Commands;

use Illuminate\Console\Command;
use Isaachatilima\Laracanon\Items\ItemCatalog;
use Throwable;

final class ListCommand extends Command
{
    protected $signature = 'canon:list';

    protected $description = 'List available Laracanon items without installing them';

    public function handle(ItemCatalog $catalog): int
    {
        try {
            $rows = [];
            foreach ($catalog->all() as $item) {
                $rows[] = [$item->name, $item->sample ? 'Sample' : 'Item', $item->description,
                    implode(', ', array_map(fn ($dependency) => "{$dependency->package} ({$dependency->type})", $item->dependencies)) ?: 'None'];
            }
            $this->table(['Name', 'Kind', 'Description', 'Dependencies'], $rows);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
