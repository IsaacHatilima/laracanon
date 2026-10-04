<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Tests\Unit\Items;

use Isaachatilima\Laracanon\Items\Item;
use Isaachatilima\Laracanon\Items\ItemFormatException;
use Isaachatilima\Laracanon\Items\ItemParser;
use Isaachatilima\Laracanon\Support\ItemFilePath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ItemParserTest extends TestCase
{
    public function test_sections_and_dependencies_are_optional(): void
    {
        $markdown = $this->markdown();
        $item = (new ItemParser)->parse($markdown);

        self::assertSame('example', $item->name);
        self::assertSame('An example item.', $item->description);
        self::assertSame(['app/**/*.php'], $item->paths);
        self::assertSame([], $item->dependencies);
        self::assertSame([], $item->composerPlugins);
        self::assertSame([], $item->files);
        self::assertNull($item->rules);
        self::assertNull($item->examples);
        self::assertNull($item->skill);
        self::assertNull($item->skillName);
        self::assertFalse($item->sample);
        self::assertFalse($item->overridesSkill);
        self::assertSame(hash('sha256', $markdown), $item->sourceHash);
    }

    public function test_rules_and_examples_do_not_generate_skill_instructions(): void
    {
        $item = (new ItemParser)->parse($this->markdown(body: "## Rules\n\nUse named factories.\n\n## Examples\n\n`fromRequest()`"));

        self::assertSame('Use named factories.', $item->rules);
        self::assertSame('`fromRequest()`', $item->examples);
        self::assertNull($item->skill);
        self::assertNull($item->skillName);
    }

    public function test_runtime_and_development_dependencies_remain_distinct(): void
    {
        $item = (new ItemParser)->parse($this->markdown(extra: "dependencies:\n  runtime:\n    - spatie/laravel-data\n  development:\n    - laravel/pint"));

        self::assertCount(2, $item->dependencies);
        self::assertSame('spatie/laravel-data', $item->dependencies[0]->package);
        self::assertSame('runtime', $item->dependencies[0]->type);
        self::assertSame('laravel/pint', $item->dependencies[1]->package);
        self::assertSame('development', $item->dependencies[1]->type);
    }

    public function test_minimum_versions_are_optional_and_appended_after_existing_item_arguments(): void
    {
        $parsed = (new ItemParser)->parse($this->markdown(extra: "dependencies:\n  development: [phpstan/phpstan]"));
        $constructed = new Item('example', 'Example.', [], [], null, null, null, null, 'hash', false, false, ['config/example.php' => 'literal']);
        $empty = (new ItemParser)->parse($this->markdown(extra: 'minimum_versions: {}'));

        self::assertSame([], $parsed->minimumVersions);
        self::assertSame([], $constructed->minimumVersions);
        self::assertSame(['config/example.php' => 'literal'], $constructed->files);
        self::assertSame([], $empty->minimumVersions);
    }

    public function test_minimum_versions_reference_both_dependency_types_without_changing_dependencies_or_sections(): void
    {
        $metadata = <<<'YAML'
dependencies:
  runtime: [vendor/runtime]
  development: [phpstan/phpstan, vendor/unguarded]
minimum_versions:
  vendor/runtime: '1.2.3'
  phpstan/phpstan: '2.0.0'
YAML;
        $item = (new ItemParser)->parse($this->markdown(extra: $metadata, body: "## Rules\nA rule.\n## Files\n### phpstan.neon.dist\n```neon\nparameters:\n    level: 10\n```\n## Skill\n1. Run the configured analyzer."));

        self::assertSame(['vendor/runtime' => '1.2.3', 'phpstan/phpstan' => '2.0.0'], $item->minimumVersions);
        self::assertSame(['vendor/runtime', 'phpstan/phpstan', 'vendor/unguarded'], array_column($item->dependencies, 'package'));
        self::assertSame(['runtime', 'development', 'development'], array_column($item->dependencies, 'type'));
        self::assertSame('A rule.', $item->rules);
        self::assertSame(['phpstan.neon.dist' => "parameters:\n    level: 10\n"], $item->files);
        self::assertSame('1. Run the configured analyzer.', $item->skill);
    }

    #[DataProvider('stableMinimumVersions')]
    public function test_minimum_versions_accept_canonical_stable_numeric_versions(string $version): void
    {
        $item = (new ItemParser)->parse($this->markdown(extra: "dependencies:\n  runtime: [vendor/package]\nminimum_versions:\n  vendor/package: '".$version."'"));

        self::assertSame(['vendor/package' => $version], $item->minimumVersions);
        self::assertNull($item->skill);
    }

    /** @return array<string, array{string}> */
    public static function stableMinimumVersions(): array
    {
        return [
            'zero version' => ['0.0.0'],
            'stable major' => ['2.0.0'],
            'multi-digit parts' => ['12.34.567'],
        ];
    }

    #[DataProvider('invalidMinimumVersions')]
    public function test_minimum_versions_reject_malformed_metadata_with_source_context(string $metadata, string $message): void
    {
        $this->expectException(ItemFormatException::class);
        $this->expectExceptionMessage('/items/example.md: '.$message);

        (new ItemParser)->parse($this->markdown(extra: $metadata), '/items/example.md');
    }

    /** @return array<string, array{string, string}> */
    public static function invalidMinimumVersions(): array
    {
        $dependency = "dependencies:\n  development: [phpstan/phpstan]\n";
        $versionPrefix = $dependency."minimum_versions:\n  phpstan/phpstan: ";
        $mappingError = 'minimum_versions must map declared dependency packages';
        $keyError = 'minimum_versions keys must reference packages declared in dependencies';
        $versionError = 'minimum_versions.phpstan/phpstan must be a stable version string in canonical x.y.z format';

        return [
            'null mapping' => [$dependency.'minimum_versions: null', $mappingError],
            'scalar mapping' => [$dependency."minimum_versions: '2.0.0'", $mappingError],
            'list mapping' => [$dependency."minimum_versions: ['2.0.0']", $mappingError],
            'undeclared dependency' => [$dependency."minimum_versions:\n  vendor/missing: '2.0.0'", $keyError],
            'no declared dependencies' => ["minimum_versions:\n  phpstan/phpstan: '2.0.0'", $keyError],
            'empty package key' => [$dependency."minimum_versions:\n  '': '2.0.0'", $keyError],
            'integer package key' => [$dependency."minimum_versions:\n  123: '2.0.0'", $keyError],
            'integer version' => [$versionPrefix.'2', $versionError],
            'float version' => [$versionPrefix.'2.0', $versionError],
            'boolean version' => [$versionPrefix.'true', $versionError],
            'null version' => [$versionPrefix.'null', $versionError],
            'list version' => [$versionPrefix."['2.0.0']", $versionError],
            'map version' => [$versionPrefix."{version: '2.0.0'}", $versionError],
            'empty version' => [$versionPrefix."''", $versionError],
            'major only' => [$versionPrefix."'2'", $versionError],
            'missing patch' => [$versionPrefix."'2.0'", $versionError],
            'four numeric parts' => [$versionPrefix."'2.0.0.0'", $versionError],
            'major leading zero' => [$versionPrefix."'02.0.0'", $versionError],
            'minor leading zero' => [$versionPrefix."'2.00.0'", $versionError],
            'patch leading zero' => [$versionPrefix."'2.0.00'", $versionError],
            'version prefix' => [$versionPrefix."'v2.0.0'", $versionError],
            'constraint operator' => [$versionPrefix."'^2.0.0'", $versionError],
            'constraint range' => [$versionPrefix."'>=2.0.0'", $versionError],
            'branch version' => [$versionPrefix."'dev-main'", $versionError],
            'prerelease version' => [$versionPrefix."'2.0.0-beta.1'", $versionError],
            'build metadata' => [$versionPrefix."'2.0.0+build'", $versionError],
            'negative part' => [$versionPrefix."'-2.0.0'", $versionError],
            'leading whitespace' => [$versionPrefix."' 2.0.0'", $versionError],
            'trailing whitespace' => [$versionPrefix."'2.0.0 '", $versionError],
        ];
    }

    public function test_composer_plugins_are_optional_and_appended_after_existing_item_arguments(): void
    {
        $parsed = (new ItemParser)->parse($this->markdown(extra: "dependencies:\n  development: [vendor/package]"));
        $constructed = new Item('example', 'Example.', [], [], null, null, null, null, 'hash', false, false, ['config/example.php' => 'literal'], ['vendor/package' => '1.2.3']);
        $empty = (new ItemParser)->parse($this->markdown(extra: 'composer_plugins: {}'));

        self::assertSame([], $parsed->composerPlugins);
        self::assertSame([], $constructed->composerPlugins);
        self::assertSame(['vendor/package' => '1.2.3'], $constructed->minimumVersions);
        self::assertSame(['config/example.php' => 'literal'], $constructed->files);
        self::assertSame([], $empty->composerPlugins);
    }

    public function test_composer_plugins_reference_each_dependency_type_and_can_share_a_plugin_between_dependencies(): void
    {
        $metadata = <<<'YAML'
dependencies:
  runtime: [vendor/runtime]
  development: [vendor/development, vendor/unconfigured]
minimum_versions:
  vendor/runtime: '1.2.3'
composer_plugins:
  vendor/runtime: [vendor/shared-plugin]
  vendor/development:
    - vendor/shared-plugin
    - vendor/another.plugin_2
  vendor/unconfigured: []
YAML;
        $item = (new ItemParser)->parse($this->markdown(extra: $metadata, body: "## Rules\nA rule.\n## Skill\n1. An authored step.\n## Files\n### config/example.php\n```php\n<?php\nreturn [];\n```"));

        self::assertSame([
            'vendor/runtime' => ['vendor/shared-plugin'],
            'vendor/development' => ['vendor/shared-plugin', 'vendor/another.plugin_2'],
            'vendor/unconfigured' => [],
        ], $item->composerPlugins);
        self::assertSame(['runtime', 'development', 'development'], array_column($item->dependencies, 'type'));
        self::assertSame(['vendor/runtime' => '1.2.3'], $item->minimumVersions);
        self::assertSame('A rule.', $item->rules);
        self::assertSame('1. An authored step.', $item->skill);
        self::assertSame(['config/example.php' => "<?php\nreturn [];\n"], $item->files);
    }

    #[DataProvider('invalidComposerPlugins')]
    public function test_composer_plugins_reject_malformed_metadata_with_source_context(string $metadata, string $message): void
    {
        $this->expectException(ItemFormatException::class);
        $this->expectExceptionMessage('/items/example.md: '.$message);

        (new ItemParser)->parse($this->markdown(extra: $metadata), '/items/example.md');
    }

    /** @return array<string, array{string, string}> */
    public static function invalidComposerPlugins(): array
    {
        $dependency = "dependencies:\n  development: [vendor/package]\n";
        $pluginsPrefix = $dependency."composer_plugins:\n  vendor/package: ";
        $mappingError = 'composer_plugins must map declared dependency packages';
        $keyError = 'composer_plugins keys must reference packages declared in dependencies';
        $listError = 'composer_plugins.vendor/package must be a list of Composer plugin package names';
        $pluginError = 'Composer plugins must be exact lowercase Composer package names without version constraints or wildcards';

        return [
            'null mapping' => [$dependency.'composer_plugins: null', $mappingError],
            'scalar mapping' => [$dependency.'composer_plugins: vendor/plugin', $mappingError],
            'list mapping' => [$dependency.'composer_plugins: [vendor/plugin]', $mappingError],
            'undeclared dependency' => [$dependency."composer_plugins:\n  vendor/missing: [vendor/plugin]", $keyError],
            'no declared dependencies' => ["composer_plugins:\n  vendor/package: [vendor/plugin]", $keyError],
            'empty package key' => [$dependency."composer_plugins:\n  '': [vendor/plugin]", $keyError],
            'integer package key' => [$dependency."composer_plugins:\n  123: [vendor/plugin]", $keyError],
            'null plugin list' => [$pluginsPrefix.'null', $listError],
            'scalar plugin list' => [$pluginsPrefix.'vendor/plugin', $listError],
            'map plugin list' => [$pluginsPrefix.'{plugin: vendor/plugin}', $listError],
            'nonsequential plugin list' => [$pluginsPrefix.'{1: vendor/plugin}', $listError],
            'integer plugin' => [$pluginsPrefix.'[123]', $pluginError],
            'boolean plugin' => [$pluginsPrefix.'[true]', $pluginError],
            'null plugin' => [$pluginsPrefix.'[null]', $pluginError],
            'nested plugin list' => [$pluginsPrefix.'[[vendor/plugin]]', $pluginError],
            'plugin version constraint' => [$pluginsPrefix."['vendor/plugin:^1.0']", $pluginError],
            'upper case plugin' => [$pluginsPrefix.'[Vendor/Plugin]', $pluginError],
            'wildcard plugin' => [$pluginsPrefix."['vendor/*']", $pluginError],
            'blanket permission' => [$pluginsPrefix."['*']", $pluginError],
            'missing vendor' => [$pluginsPrefix.'[plugin]', $pluginError],
            'missing package' => [$pluginsPrefix.'[vendor/]', $pluginError],
            'extra separator' => [$pluginsPrefix.'[vendor/plugin/extra]', $pluginError],
            'empty plugin' => [$pluginsPrefix."['']", $pluginError],
            'leading whitespace' => [$pluginsPrefix."[' vendor/plugin']", $pluginError],
            'trailing whitespace' => [$pluginsPrefix."['vendor/plugin ']", $pluginError],
            'duplicate plugin' => [$pluginsPrefix.'[vendor/plugin, vendor/plugin]', 'Duplicate Composer plugin vendor/plugin for vendor/package'],
        ];
    }

    public function test_authored_skills_get_a_namespaced_default_name(): void
    {
        $item = (new ItemParser)->parse($this->markdown(body: "## Skill\n\n1. Read the request.\n2. Run the focused check."));

        self::assertSame('1. Read the request.'."\n".'2. Run the focused check.', $item->skill);
        self::assertSame('laracanon-example', $item->skillName);
    }

    public function test_package_skill_override_requires_explicit_authored_metadata(): void
    {
        $item = (new ItemParser)->parse($this->markdown(extra: "skill_name: existing-package-skill\noverrides_skill: true\nsample: true", body: "## Skill\n\nUse this expressly authored replacement workflow."));

        self::assertSame('existing-package-skill', $item->skillName);
        self::assertTrue($item->overridesSkill);
        self::assertTrue($item->sample);
    }

    public function test_empty_sections_are_treated_as_absent(): void
    {
        $item = (new ItemParser)->parse($this->markdown(body: "## Rules\n\n\t\n## Examples\n\n## Skill\n\n## Files\n\t\n"));

        self::assertNull($item->rules);
        self::assertNull($item->examples);
        self::assertNull($item->skill);
        self::assertNull($item->skillName);
        self::assertSame([], $item->files);
    }

    public function test_files_default_at_the_end_of_the_existing_item_constructor(): void
    {
        $item = new Item('example', 'Example.', [], [], null, null, null, null, 'hash', true, true);

        self::assertSame([], $item->files);
        self::assertTrue($item->overridesSkill);
        self::assertTrue($item->sample);
    }

    public function test_configuration_payloads_preserve_literal_whitespace_and_empty_blocks(): void
    {
        $body = <<<'MARKDOWN'
## Files

### config/example.php

```php

<?php

return [
    'value' => '  literal  ',
];

```

### config/nested/Other_2-file.json
~~~json
  {"enabled": true}
~~~~

### config/empty.php
```
```

### config/blank.txt
~~~

~~~
MARKDOWN;
        $body = str_replace('{"enabled": true}', '{"enabled": true}  ', $body);
        $item = (new ItemParser)->parse($this->markdown(body: $body));

        self::assertSame([
            'config/example.php' => "\n<?php\n\nreturn [\n    'value' => '  literal  ',\n];\n\n",
            'config/nested/Other_2-file.json' => '  {"enabled": true}  '."\n",
            'config/empty.php' => '',
            'config/blank.txt' => "\n",
        ], $item->files);
        self::assertNull($item->rules);
        self::assertNull($item->examples);
        self::assertNull($item->skill);
        self::assertNull($item->skillName);
    }

    public function test_files_are_independent_of_rules_examples_skills_and_dependency_types(): void
    {
        $body = <<<'MARKDOWN'
## Rules

A lasting convention.

## Files

### config/example.php
```php
<?php
return ['enabled' => true];
```

## Examples

```markdown
## Files
### config/not-installed.php
```

## Skill

1. Follow this explicitly authored workflow.
MARKDOWN;
        $item = (new ItemParser)->parse($this->markdown(
            extra: "dependencies:\n  runtime: [vendor/runtime]\n  development: [vendor/development]",
            body: $body,
        ));

        self::assertSame('A lasting convention.', $item->rules);
        self::assertSame("```markdown\n## Files\n### config/not-installed.php\n```", $item->examples);
        self::assertSame('1. Follow this explicitly authored workflow.', $item->skill);
        self::assertSame('laracanon-example', $item->skillName);
        self::assertSame(['config/example.php' => "<?php\nreturn ['enabled' => true];\n"], $item->files);
        self::assertSame('runtime', $item->dependencies[0]->type);
        self::assertSame('development', $item->dependencies[1]->type);
    }

    public function test_file_code_headings_and_shorter_or_opposite_fences_are_literal_payload(): void
    {
        $payload = "## Unknown\n### config/another.php\n```\n~~~\n  indented\t  \n";
        $item = (new ItemParser)->parse($this->markdown(body: "## Files\n### config/example.php\n````text\n".$payload."`````\n\n## Rules\nA rule."));

        self::assertSame(['config/example.php' => $payload], $item->files);
        self::assertSame('A rule.', $item->rules);
        self::assertNull($item->skill);
    }

    #[DataProvider('lineEndings')]
    public function test_file_line_endings_are_normalized_without_changing_the_original_source_hash(string $lineEnding): void
    {
        $markdown = "\xEF\xBB\xBF".str_replace("\n", $lineEnding, $this->markdown(body: "## Files\n### config/example.php\n```php\n\n<?php\nreturn [];\n\n```"));
        $item = (new ItemParser)->parse($markdown, '/items/example.md');

        self::assertSame(['config/example.php' => "\n<?php\nreturn [];\n\n"], $item->files);
        self::assertSame(hash('sha256', $markdown), $item->sourceHash);
    }

    /** @return array<string, array{string}> */
    public static function lineEndings(): array
    {
        return [
            'CRLF' => ["\r\n"],
            'CR' => ["\r"],
        ];
    }

    #[DataProvider('rootNeonFileDestinations')]
    public function test_root_neon_configuration_files_preserve_literal_contents(string $destination): void
    {
        $payload = "\nparameters:\n    level: 5\n\n";
        $item = (new ItemParser)->parse($this->markdown(body: "## Files\n### ".$destination."\n```neon\n".$payload.'```'));

        self::assertTrue(ItemFilePath::isConfiguration($destination));
        self::assertSame([$destination => $payload], $item->files);
        self::assertNull($item->skill);
    }

    /** @return array<string, array{string}> */
    public static function rootNeonFileDestinations(): array
    {
        return [
            'project NEON' => ['phpstan.neon'],
            'distributed NEON' => ['phpstan.neon.dist'],
            'baseline NEON' => ['phpstan-baseline.neon'],
            'mixed ASCII filename' => ['PHPStan_2-custom.neon.dist'],
            'numeric first character' => ['1.neon'],
            'single-character prefix' => ['a.neon'],
        ];
    }

    public function test_root_neon_and_config_directory_files_can_be_declared_together(): void
    {
        $item = (new ItemParser)->parse($this->markdown(body: "## Files\n### config/insights.php\n```php\n<?php\nreturn [];\n```\n### phpstan.neon.dist\n~~~neon\nparameters:\n    level: 5\n~~~\n## Rules\nA rule."));

        self::assertSame([
            'config/insights.php' => "<?php\nreturn [];\n",
            'phpstan.neon.dist' => "parameters:\n    level: 5\n",
        ], $item->files);
        self::assertSame('A rule.', $item->rules);
        self::assertNull($item->skill);
    }

    public function test_configuration_path_helper_rejects_line_breaks_in_destinations(): void
    {
        self::assertFalse(ItemFilePath::isConfiguration("phpstan.neon.dist\n"));
        self::assertFalse(ItemFilePath::isConfiguration("phpstan.neon\r"));
        self::assertFalse(ItemFilePath::isConfiguration("config/example.php\n"));
        self::assertFalse(ItemFilePath::isConfiguration(' phpstan.neon'));
    }

    #[DataProvider('invalidFileDestinations')]
    public function test_file_destinations_must_be_canonical_configuration_files(string $destination): void
    {
        self::assertFalse(ItemFilePath::isConfiguration($destination));
        $this->expectException(ItemFormatException::class);
        $this->expectExceptionMessage('/items/example.md: File destinations must be canonical relative config/ paths or root filenames ending .neon or .neon.dist');

        (new ItemParser)->parse($this->markdown(body: "## Files\n### ".$destination."\n```php\n<?php\n```"), '/items/example.md');
    }

    /** @return array<string, array{string}> */
    public static function invalidFileDestinations(): array
    {
        return [
            'absolute' => ['/config/example.php'],
            'Windows absolute' => ['C:/config/example.php'],
            'backslash' => ['config\\example.php'],
            'nested backslash' => ['config/nested\\example.php'],
            'parent prefix' => ['../config/example.php'],
            'parent segment' => ['config/../example.php'],
            'nested parent segment' => ['config/nested/../example.php'],
            'dot segment' => ['config/./example.php'],
            'empty segment' => ['config//example.php'],
            'trailing directory slash' => ['config/nested/'],
            'config directory' => ['config'],
            'config directory slash' => ['config/'],
            'dot basename' => ['config/.'],
            'parent basename' => ['config/..'],
            'root Composer file' => ['composer.json'],
            'vendor file' => ['vendor/package/config.php'],
            'Git file' => ['.git/config'],
            'AI file' => ['.ai/rules/example.md'],
            'wrong config case' => ['Config/example.php'],
            'space' => ['config/an example.php'],
            'trailing space' => ['config/example.php '],
            'tab' => ["config/example\t.php"],
            'control byte' => ["config/example\x01.php"],
            'null byte' => ["config/example\x00.php"],
            'DEL byte' => ["config/example\x7F.php"],
            'non-ASCII' => ['config/éxample.php'],
            'glob' => ['config/*.php'],
            'colon' => ['config/example:php'],
            'root PHP config' => ['insights.php'],
            'root JSON config' => ['phpstan.json'],
            'root YAML config' => ['phpstan.yaml'],
            'root NEON wrong extension case' => ['phpstan.NEON'],
            'root distribution wrong extension case' => ['phpstan.neon.DIST'],
            'root NEON backup extension' => ['phpstan.neon.dist.bak'],
            'hidden root NEON' => ['.phpstan.neon'],
            'empty root NEON prefix' => ['.neon'],
            'empty root distributed NEON prefix' => ['.neon.dist'],
            'root underscore prefix' => ['_phpstan.neon'],
            'root hyphen prefix' => ['-phpstan.neon'],
            'root trailing space' => ['phpstan.neon.dist '],
            'root space within' => ['php stan.neon'],
            'root non-ASCII' => ['phṕstan.neon'],
            'root control byte' => ["phpstan\x01.neon"],
            'absolute NEON' => ['/phpstan.neon.dist'],
            'dot-relative NEON' => ['./phpstan.neon'],
            'parent-relative NEON' => ['../phpstan.neon.dist'],
            'nested non-config NEON' => ['tests/phpstan.neon'],
            'root NEON backslash' => ['phpstan\\custom.neon'],
            'root NEON directory suffix' => ['phpstan.neon/'],
            'vendor NEON' => ['vendor/phpstan.neon'],
            'Git NEON' => ['.git/phpstan.neon'],
            'AI NEON' => ['.ai/phpstan.neon'],
        ];
    }

    #[DataProvider('invalidFileBlocks')]
    public function test_malformed_files_sections_are_rejected_with_source_context(string $body, string $message): void
    {
        $this->expectException(ItemFormatException::class);
        $this->expectExceptionMessage('/items/example.md: '.$message);

        (new ItemParser)->parse($this->markdown(body: $body), '/items/example.md');
    }

    /** @return array<string, array{string, string}> */
    public static function invalidFileBlocks(): array
    {
        $header = "## Files\n### config/example.php\n";
        $block = $header."```php\n<?php\n```";

        return [
            'duplicate destination' => [$block."\n### config/example.php\n```\n```", 'Duplicate file destination config/example.php'],
            'duplicate root NEON destination' => ["## Files\n### phpstan.neon.dist\n```neon\nparameters: []\n```\n### phpstan.neon.dist\n~~~\n~~~", 'Duplicate file destination phpstan.neon.dist'],
            'duplicate section' => [$block."\n## Files", 'Duplicate section ## Files'],
            'section prose' => ["## Files\nAn explanation.", '## Files may contain only'],
            'undeclared fence' => ["## Files\n```php\n<?php\n```", '## Files may contain only'],
            'text after a block' => [$block."\nMore instructions.", '## Files may contain only'],
            'additional undeclared block' => [$block."\n```php\n<?php\n```", '## Files may contain only'],
            'missing fence' => [$header, 'File config/example.php must be followed by a fenced literal block'],
            'header before a fence' => [$header.'### config/other.php', 'File config/example.php must be followed by a fenced literal block'],
            'prose before fence' => [$header."This is PHP.\n```php\n```", 'File config/example.php must be followed by a fenced literal block'],
            'two-character fence' => [$header."``\n<?php\n``", 'File config/example.php must be followed by a fenced literal block'],
            'malformed fence info' => [$header."```php`\n<?php\n```", 'File config/example.php must be followed by a fenced literal block'],
            'unclosed fence' => [$header."```php\n<?php", 'Unclosed fenced literal block for file config/example.php'],
            'opposite closing marker' => [$header."```php\n<?php\n~~~", 'Unclosed fenced literal block for file config/example.php'],
            'short closing marker' => [$header."````php\n<?php\n```", 'Unclosed fenced literal block for file config/example.php'],
            'indented closing marker' => [$header."```php\n<?php\n    ```", 'Unclosed fenced literal block for file config/example.php'],
        ];
    }

    public function test_fenced_headings_and_indentation_are_preserved(): void
    {
        $examples = "```markdown\n## Rules\n## Unknown heading\n```\n\n~~~markdown\n## Skill\n~~~\n\n    indented example";
        $item = (new ItemParser)->parse($this->markdown(body: "## Examples\n\n".$examples."\n\n## Rules\n\nA real rule."));

        self::assertSame($examples, $item->examples);
        self::assertSame('A real rule.', $item->rules);
        self::assertNull($item->skill);
    }

    public function test_longer_fence_does_not_close_at_a_shorter_marker(): void
    {
        $examples = "````markdown\n```\n## Skill\n````";
        $item = (new ItemParser)->parse($this->markdown(body: "## Examples\n".$examples));

        self::assertSame($examples, $item->examples);
        self::assertNull($item->skill);
    }

    public function test_crlf_and_bom_are_accepted_but_source_hash_uses_original_bytes(): void
    {
        $markdown = "\xEF\xBB\xBF".str_replace("\n", "\r\n", $this->markdown(body: "## Rules\n\nA rule."));
        $item = (new ItemParser)->parse($markdown, '/items/example.md');

        self::assertSame('A rule.', $item->rules);
        self::assertSame(hash('sha256', $markdown), $item->sourceHash);
    }

    public function test_filename_must_match_name(): void
    {
        $this->expectException(ItemFormatException::class);
        $this->expectExceptionMessage('/items/other.md: name must match the Markdown filename example.md.');

        (new ItemParser)->parse($this->markdown(), '/items/other.md');
    }

    public function test_applicability_can_be_global_with_an_empty_path_list(): void
    {
        $item = (new ItemParser)->parse("---\nname: example\ndescription: A global item.\npaths: []\n---\n");

        self::assertSame([], $item->paths);
    }

    #[DataProvider('invalidMetadata')]
    public function test_malformed_schema_is_rejected(string $metadata, string $message): void
    {
        $this->expectException(ItemFormatException::class);
        $this->expectExceptionMessage($message);

        (new ItemParser)->parse("---\n".$metadata."\n---\n");
    }

    /** @return array<string, array{string, string}> */
    public static function invalidMetadata(): array
    {
        $base = "name: example\ndescription: An example item.\npaths: []\n";

        return [
            'invalid YAML' => ['name: [', 'Invalid YAML frontmatter'],
            'scalar YAML' => ['just a scalar', 'Frontmatter must be a mapping'],
            'list YAML' => ['- one', 'Frontmatter must be a mapping'],
            'missing name' => ["description: Example\npaths: []", 'name must be a lowercase kebab-case identifier'],
            'unsafe name' => ["name: ../example\ndescription: Example\npaths: []", 'name must be a lowercase kebab-case identifier'],
            'missing description' => ["name: example\npaths: []", 'description must be a nonempty string'],
            'blank description' => ["name: example\ndescription: ' '\npaths: []", 'description must be a nonempty string'],
            'missing paths' => ["name: example\ndescription: Example", 'paths must be a list'],
            'paths scalar' => ["name: example\ndescription: Example\npaths: app/**/*.php", 'paths must be a list'],
            'paths map' => ["name: example\ndescription: Example\npaths:\n  app: test", 'paths must be a list'],
            'paths nonstring' => ["name: example\ndescription: Example\npaths: [true]", 'paths must contain safe relative paths'],
            'parent traversal' => ["name: example\ndescription: Example\npaths: [app/../config/*.php]", 'paths must contain safe relative paths'],
            'absolute path' => ["name: example\ndescription: Example\npaths: [/app/*.php]", 'paths must contain safe relative paths'],
            'windows path' => ["name: example\ndescription: Example\npaths: ['C:\\app\\*.php']", 'paths must contain safe relative paths'],
            'unknown frontmatter' => [$base.'rulez: Example', 'Unknown frontmatter field: rulez'],
            'dependencies scalar' => [$base.'dependencies: laravel/pint', 'dependencies must map'],
            'dependencies null' => [$base.'dependencies: null', 'dependencies must map'],
            'dependencies list' => [$base.'dependencies: [laravel/pint]', 'dependencies must map'],
            'unknown dependency type' => [$base."dependencies:\n  dev: [laravel/pint]", 'Unknown dependency type: dev'],
            'dependency packages scalar' => [$base."dependencies:\n  runtime: spatie/laravel-data", 'dependencies.runtime must be a list'],
            'dependency constraint' => [$base."dependencies:\n  runtime: ['spatie/laravel-data:^4.0']", 'without version constraints'],
            'duplicate dependency' => [$base."dependencies:\n  runtime: [laravel/pint]\n  development: [laravel/pint]", 'Duplicate dependency laravel/pint'],
            'nonboolean sample' => [$base."sample: 'true'", 'sample must be a YAML boolean'],
            'nonboolean override' => [$base."overrides_skill: 'true'", 'overrides_skill must be a YAML boolean'],
            'unsafe skill name' => [$base.'skill_name: ../existing', 'skill_name must be a lowercase kebab-case identifier'],
            'skill name without instructions' => [$base.'skill_name: existing', 'require nonempty, explicitly authored ## Skill instructions'],
            'override without instructions' => [$base.'overrides_skill: true', 'require nonempty, explicitly authored ## Skill instructions'],
        ];
    }

    #[DataProvider('invalidBodies')]
    public function test_malformed_body_is_rejected(string $body, string $message): void
    {
        $this->expectException(ItemFormatException::class);
        $this->expectExceptionMessage($message);

        (new ItemParser)->parse($this->markdown(body: $body));
    }

    /** @return array<string, array{string, string}> */
    public static function invalidBodies(): array
    {
        return [
            'unknown section' => ["## Packages\nlaravel/pint", 'Unknown section ## Packages'],
            'duplicate section' => ["## Rules\nOne\n## Rules\nTwo", 'Duplicate section ## Rules'],
            'unsectioned text' => ['A workflow without an authored Skill section.', 'Markdown content must appear inside'],
        ];
    }

    public function test_override_requires_an_explicit_skill_name(): void
    {
        $this->expectException(ItemFormatException::class);
        $this->expectExceptionMessage('overrides_skill requires an explicit skill_name');

        (new ItemParser)->parse($this->markdown(extra: 'overrides_skill: true', body: "## Skill\nExplicit replacement."));
    }

    public function test_missing_frontmatter_is_rejected(): void
    {
        $this->expectException(ItemFormatException::class);
        $this->expectExceptionMessage('must start with YAML frontmatter');

        (new ItemParser)->parse("## Rules\nA rule.");
    }

    private function markdown(string $extra = '', string $body = ''): string
    {
        return "---\nname: example\ndescription: An example item.\npaths:\n  - app/**/*.php\n".$extra."\n---\n".$body."\n";
    }
}
