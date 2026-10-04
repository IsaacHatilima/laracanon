<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Installation;

use Isaachatilima\Laracanon\Items\Item;
use Symfony\Component\Yaml\Yaml;

final class ItemRenderer
{
    public static function rules(Item $item): string
    {
        // Boost omits rules with an empty paths list from its index. An item
        // whose applicability is global therefore needs an explicit glob.
        $frontmatter = Yaml::dump(['description' => $item->description, 'paths' => $item->paths === [] ? ['**'] : $item->paths], 3, 2);
        $body = "# {$item->name}\n\n{$item->rules}\n";
        if ($item->examples !== null) {
            $body .= "\n## Examples\n\nThese examples illustrate usage. Follow explicit project rules and preferences when they differ from package examples.\n\n{$item->examples}\n";
        }

        return "---\n{$frontmatter}---\n\n{$body}";
    }

    public static function skill(Item $item): string
    {
        $frontmatter = Yaml::dump(['name' => $item->skillName, 'description' => $item->description], 2, 2);

        return "---\n{$frontmatter}---\n\n{$item->skill}\n";
    }
}
