<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Tests\Unit\Items;

use Isaachatilima\Laracanon\Items\ItemCatalog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SkillCatalogTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/laracanon-skills-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    public function test_a_skill_folder_becomes_one_item_with_its_support_files_and_discovery_links(): void
    {
        $this->skill('laravel-actions', 'Use for Laravel actions.');
        $this->write('laravel-actions/templates/Action.php', '<?php // template');

        $item = (new ItemCatalog($this->directory))->find('laravel-actions');

        self::assertSame('Use for Laravel actions.', $item->description);
        self::assertSame('laravel-actions', $item->skillName);
        self::assertNull($item->skill);
        self::assertSame([], $item->dependencies);
        self::assertSame([
            '.agents/skills/laravel-actions/SKILL.md',
            '.agents/skills/laravel-actions/templates/Action.php',
        ], array_keys($item->files));
        self::assertSame('<?php // template', $item->files['.agents/skills/laravel-actions/templates/Action.php']);
        self::assertSame([
            '.ai/skills/laravel-actions' => '.agents/skills/laravel-actions',
            '.claude/skills/laravel-actions' => '.agents/skills/laravel-actions',
        ], $item->links);
    }

    public function test_items_are_sorted_by_name_and_hidden_files_are_not_installed(): void
    {
        $this->skill('laravel-models', 'Models.');
        $this->skill('laravel-actions', 'Actions.');
        $this->write('laravel-actions/.DS_Store', 'finder metadata');
        $this->write('.DS_Store', 'finder metadata');

        $items = (new ItemCatalog($this->directory))->all();

        self::assertSame(['laravel-actions', 'laravel-models'], array_keys($items));
        self::assertSame(['.agents/skills/laravel-actions/SKILL.md'], array_keys($items['laravel-actions']->files));
    }

    public function test_source_changes_update_the_item_hash(): void
    {
        $this->skill('laravel-actions', 'Actions.');
        $catalog = new ItemCatalog($this->directory);
        $before = $catalog->find('laravel-actions')->sourceHash;

        $this->write('laravel-actions/SKILL.md', "---\nname: laravel-actions\ndescription: Actions.\n---\n\nUpdated instructions.\n");

        self::assertNotSame($before, $catalog->find('laravel-actions')->sourceHash);
    }

    public function test_the_skill_name_must_match_its_folder(): void
    {
        $this->write('laravel-actions/SKILL.md', "---\nname: actions\ndescription: Actions.\n---\n");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('laravel-actions/SKILL.md: name must match its folder');
        (new ItemCatalog($this->directory))->all();
    }

    public function test_a_skill_needs_a_description(): void
    {
        $this->write('laravel-actions/SKILL.md', "---\nname: laravel-actions\ndescription: ''\n---\n");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('laravel-actions/SKILL.md: description must be a nonempty string');
        (new ItemCatalog($this->directory))->all();
    }

    public function test_a_skill_needs_frontmatter(): void
    {
        $this->write('laravel-actions/SKILL.md', "# No frontmatter\n");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('laravel-actions/SKILL.md: expected YAML frontmatter');
        (new ItemCatalog($this->directory))->all();
    }

    public function test_every_skill_folder_needs_a_skill_file(): void
    {
        $this->skill('laravel-actions', 'Actions.');
        $this->write('laravel-models/notes.md', 'No skill file.');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Skill folder laravel-models has no SKILL.md');
        (new ItemCatalog($this->directory))->all();
    }

    public function test_folder_names_must_be_kebab_case(): void
    {
        $this->skill('laravel-actions', 'Actions.');
        $this->write('Laravel_Models/SKILL.md', "---\nname: Laravel_Models\ndescription: Models.\n---\n");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid skill folder name: Laravel_Models');
        (new ItemCatalog($this->directory))->all();
    }

    public function test_rejects_symlinked_support_files(): void
    {
        $this->skill('laravel-actions', 'Actions.');
        $this->write('outside.php', '<?php');
        symlink($this->directory.'/outside.php', $this->directory.'/laravel-actions/linked.php');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Symlink in skill source: laravel-actions/linked.php');
        (new ItemCatalog($this->directory))->all();
    }

    public function test_rejects_symlinked_skill_folders(): void
    {
        $this->skill('laravel-actions', 'Actions.');
        symlink($this->directory.'/laravel-actions', $this->directory.'/laravel-models');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Symlink in skill source: laravel-models');
        (new ItemCatalog($this->directory))->all();
    }

    public function test_rejects_support_file_names_that_are_unsafe_install_paths(): void
    {
        $this->skill('laravel-actions', 'Actions.');
        $this->write('laravel-actions/my template.php', '<?php');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsafe skill file name: laravel-actions/my template.php');
        (new ItemCatalog($this->directory))->all();
    }

    public function test_a_directory_without_skill_folders_is_read_as_markdown_items(): void
    {
        $this->write('custom-rules.md', "---\nname: custom-rules\ndescription: Project rules.\npaths: []\n---\n\n## Rules\n\nFollow them.\n");

        $items = (new ItemCatalog($this->directory))->all();

        self::assertSame(['custom-rules'], array_keys($items));
        self::assertNotNull($items['custom-rules']->rules);
    }

    private function skill(string $name, string $description): void
    {
        $this->write($name.'/SKILL.md', "---\nname: {$name}\ndescription: {$description}\n---\n\nInstructions.\n");
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->directory.'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path.'/'.$entry);
            }
        }
        rmdir($path);
    }
}
