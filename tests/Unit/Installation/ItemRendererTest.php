<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Tests\Unit\Installation;

use Isaachatilima\Laracanon\Installation\ItemRenderer;
use Isaachatilima\Laracanon\Items\ItemParser;
use Laravel\Boost\Rules\RuleFrontmatter;
use PHPUnit\Framework\TestCase;

final class ItemRendererTest extends TestCase
{
    public function test_global_item_rules_expose_an_explicit_glob_to_boost(): void
    {
        $item = (new ItemParser)->parse("---\nname: global-convention\ndescription: A convention for the whole project.\npaths: []\n---\n\n## Rules\n\nFollow the project convention.\n");

        $boostRule = RuleFrontmatter::parse(ItemRenderer::rules($item));

        self::assertSame(['**'], $boostRule['paths']);
        self::assertStringContainsString('Follow the project convention.', $boostRule['body']);
    }

    public function test_scoped_item_rules_preserve_their_applicability_for_boost(): void
    {
        $item = (new ItemParser)->parse("---\nname: scoped-convention\ndescription: A scoped convention.\npaths:\n  - app/Data/**/*.php\n  - tests/**/*.php\n---\n\n## Rules\n\nFollow the scoped convention.\n");

        $boostRule = RuleFrontmatter::parse(ItemRenderer::rules($item));

        self::assertSame(['app/Data/**/*.php', 'tests/**/*.php'], $boostRule['paths']);
    }
}
