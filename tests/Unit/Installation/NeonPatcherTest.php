<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Tests\Unit\Installation;

use Isaachatilima\Laracanon\Installation\NeonPatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NeonPatcherTest extends TestCase
{
    public function test_only_the_level_value_changes_in_a_phpstan_configuration(): void
    {
        $contents = <<<'NEON'
# Project analysis settings
includes:
    - vendor/larastan/larastan/extension.neon
    - vendor/nesbot/carbon/extension.neon

parameters:
    level: 7  # Keep this explanation
    paths:
        - app
    excludePaths:
        - app/Providers/*
    ignoreErrors:
        - '#Call to an undefined method#'
    checkUninitializedProperties: true

services:
    - App\Analysis\CustomRule()
NEON;

        $patched = (new NeonPatcher)->patch($contents, ['parameters.level' => 10]);

        self::assertSame(str_replace('level: 7', 'level: 10', $contents), $patched);
        self::assertSame($patched, (new NeonPatcher)->patch($patched, ['parameters.level' => 10]));
    }

    #[DataProvider('scalarFormatting')]
    public function test_existing_scalar_formatting_and_comments_are_preserved(string $contents, string $expected): void
    {
        self::assertSame($expected, (new NeonPatcher)->patch($contents, ['parameters.level' => 10]));
    }

    public static function scalarFormatting(): array
    {
        return [
            'tabs and CRLF' => ["parameters:\r\n\tlevel:\t7\t# comment\r\n\tpaths: [app]\r\n", "parameters:\r\n\tlevel:\t10\t# comment\r\n\tpaths: [app]\r\n"],
            'single quoted scalar with hash' => ["parameters:\n  level: 'max#value' # comment\n", "parameters:\n  level: 10 # comment\n"],
            'double quoted scalar with escaped quote' => ["parameters:\n  level: \"max\\\"#value\"# comment\n", "parameters:\n  level: 10 # comment\n"],
            'single quoted keys' => ["'parameters':\n    'level': 7\n", "'parameters':\n    'level': 10\n"],
            'double quoted keys' => ["\"parameters\":\n    \"level\": 7\n", "\"parameters\":\n    \"level\": 10\n"],
            'spaces before colon' => ["parameters :\n  level  :   7   \n", "parameters :\n  level  :   10   \n"],
            'equals notation' => ["parameters=\n    level=7\n", "parameters=\n    level=10\n"],
            'empty scalar' => ["parameters:\n    level:\n", "parameters:\n    level: 10\n"],
            'comment on empty scalar' => ["parameters:\n    level: # inherited\n", "parameters:\n    level: 10 # inherited\n"],
            'no trailing newline' => ["parameters:\n    level: 7", "parameters:\n    level: 10"],
            'indented root' => ["  parameters:\n    level: 7\n", "  parameters:\n    level: 10\n"],
        ];
    }

    #[DataProvider('missingScalars')]
    public function test_missing_mappings_or_scalars_are_added_without_rewriting_existing_lines(string $contents, string $expected): void
    {
        self::assertSame($expected, (new NeonPatcher)->patch($contents, ['parameters.level' => 10]));
    }

    public static function missingScalars(): array
    {
        return [
            'existing parameters' => ["parameters:\n  paths:\n    - app\nservices:\n  - CustomRule()\n", "parameters:\n  paths:\n    - app\n  level: 10\nservices:\n  - CustomRule()\n"],
            'existing tabs' => ["parameters:\r\n\tpaths: [app]\r\n", "parameters:\r\n\tpaths: [app]\r\n\tlevel: 10\r\n"],
            'empty parameters' => ["parameters: # analysis\n", "parameters: # analysis\n    level: 10\n"],
            'empty parameters without newline' => ['parameters:', "parameters:\n    level: 10\n"],
            'missing parameters' => ["includes:\n  - custom.neon\n", "includes:\n  - custom.neon\nparameters:\n  level: 10\n"],
            'empty file' => ['', "parameters:\n    level: 10\n"],
            'comments only' => ["# Project configuration\n", "# Project configuration\nparameters:\n    level: 10\n"],
        ];
    }

    public function test_multiple_scalar_updates_can_use_different_types_and_nested_paths(): void
    {
        $contents = "parameters:\n    level: 7\n    checks:\n        enabled: false\n        message: old\n";
        $patched = (new NeonPatcher)->patch($contents, [
            'parameters.level' => 10,
            'parameters.checks.enabled' => true,
            'parameters.checks.message' => 'true # is a string',
            'parameters.checks.optional' => null,
            'parameters.checks.ratio' => 1.5,
        ]);

        self::assertSame("parameters:\n    level: 10\n    checks:\n        enabled: true\n        message: 'true # is a string'\n        optional: null\n        ratio: 1.5\n", $patched);
        self::assertSame([
            'parameters.level' => ['exists' => true, 'value' => 10],
            'parameters.checks.optional' => ['exists' => true, 'value' => null],
            'parameters.missing' => ['exists' => false, 'value' => null],
        ], (new NeonPatcher)->values($patched, ['parameters.level', 'parameters.checks.optional', 'parameters.missing']));
    }

    public function test_unrelated_dates_entities_and_multiline_strings_are_preserved(): void
    {
        $contents = <<<'NEON'
parameters:
    level: 7
    message: '''
        parameters:
            level: fake
        '''
    date: 2026-10-04
services:
    rule: CustomRule(enabled: true)
NEON;

        self::assertSame(str_replace('level: 7', 'level: 10', $contents), (new NeonPatcher)->patch($contents, ['parameters.level' => 10]));
    }

    public function test_unchanged_inline_configuration_does_not_need_to_be_rewritten(): void
    {
        $contents = 'parameters: {level: 10, paths: [app]}';

        self::assertSame($contents, (new NeonPatcher)->patch($contents, ['parameters.level' => 10]));
    }

    #[DataProvider('unsupportedConfigurations')]
    public function test_invalid_or_unsupported_configuration_fails_clearly(string $contents, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        (new NeonPatcher)->patch($contents, ['parameters.level' => 10]);
    }

    public static function unsupportedConfigurations(): array
    {
        return [
            'inline parameters' => ["parameters: {level: 7, paths: [app]}\n", 'must use a block mapping'],
            'inline root' => ['{parameters: {level: 7}}', 'Invalid NEON configuration'],
            'duplicate level' => ["parameters:\n    level: 7\n    level: 8\n", 'Invalid NEON configuration'],
            'duplicate quoted parameters' => ["parameters:\n    level: 7\n'parameters':\n    paths: [app]\n", 'Invalid NEON configuration'],
            'invalid syntax' => ["parameters:\n    level: [\n", 'Invalid NEON configuration'],
            'scalar parameters' => ["parameters: custom\n", 'parameters is not a mapping'],
            'sequence parameters' => ["parameters:\n    - app\n", 'parameters is not a mapping'],
            'structured level' => ["parameters:\n    level: [7]\n", 'existing value is not a scalar'],
            'multiline level' => ["parameters:\n    level: '''\n        seven\n        '''\n", 'multiline scalar syntax is unsupported'],
            'root sequence' => ["- parameters\n", 'root mapping'],
            'bare carriage return' => ["parameters:\n    level: 7\r", 'bare carriage returns'],
        ];
    }

    public function test_misleading_multiline_text_cannot_be_modified_even_when_the_result_is_valid(): void
    {
        $contents = "parameters:\n    paths: [app]\n    message: '''\n    level: fake\n    '''\n";
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unrelated configuration would change');

        (new NeonPatcher)->patch($contents, ['parameters.level' => 10]);
    }

    #[DataProvider('invalidUpdates')]
    public function test_invalid_update_values_and_paths_are_rejected(array $updates): void
    {
        $this->expectException(RuntimeException::class);

        (new NeonPatcher)->patch("parameters:\n    level: 7\n", $updates);
    }

    public static function invalidUpdates(): array
    {
        return [
            'array value' => [['parameters.level' => [10]]],
            'object value' => [['parameters.level' => new \stdClass]],
            'nonfinite float' => [['parameters.level' => INF]],
            'empty path' => [['' => 10]],
            'empty segment' => [['parameters..level' => 10]],
            'hyphenated identifier' => [['parameters.custom-setting' => 10]],
            'numeric key' => [[10]],
            'overlapping paths' => [['parameters' => 10, 'parameters.level' => 10]],
        ];
    }
}
