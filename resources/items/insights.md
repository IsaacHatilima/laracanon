---
name: insights
description: PHP Insights with the exact City Directory quality configuration.
paths:
  - 'config/insights.php'
  - 'app/**/*.php'
dependencies:
  development:
    - nunomaduro/phpinsights
composer_plugins:
  nunomaduro/phpinsights:
    - dealerdirect/phpcodesniffer-composer-installer
---

## Examples

Install the selected development integration in an existing Laravel application:

```sh
php artisan canon:install insights --dry-run
php artisan canon:install insights
```

A missing dependency resolves to the latest compatible stable release in `require-dev`; existing constraints and installed versions are preserved. The [PHP Insights repository](https://github.com/nunomaduro/phpinsights) documents Laravel's `php artisan insights` command. Configuration is installed directly through Laracanon's ownership checks.

This item declares `dealerdirect/phpcodesniffer-composer-installer`, which PHP Insights receives through Slevomat, for PHP_CodeSniffer standard registration. Laracanon adds that exact project permission before installing this dependency when no existing plugin policy covers it. Existing allow/deny decisions are preserved, and dry runs report the planned permission without changing Composer configuration. See [Composer's plugin permissions](https://getcomposer.org/doc/06-config.md#allow-plugins) and the [plugin's usage documentation](https://github.com/PHPCSStandards/composer-installer#usage).

The literal file below exactly matches the [requested City Directory configuration](https://github.com/city-directory/citydirectory/blob/main/config/insights.php), captured at [commit 8c5cdb0093067933cb74481d7fb087ca2fb63b98](https://github.com/city-directory/citydirectory/blob/8c5cdb0093067933cb74481d7fb087ca2fb63b98/config/insights.php). Git blob: `f63106cd5c23bc50b2c79066ac6f2d6bc5d5d175`. SHA-256: `9b51c64f64e8c8349ad6b33bb330b270b3a77292130a1eb3ef3d8351dd597623`.

Run analysis for the changed/affected paths using the installed command's supported options. Installing this item adds no Composer scripts and runs no analysis or fixes. It contains no project Rules or authored Skill section; dependency-provided resources are discovered when available.

## Files

### config/insights.php

```php
<?php

declare(strict_types=1);

use NunoMaduro\PhpInsights\Domain\Insights\CyclomaticComplexityIsHigh;
use NunoMaduro\PhpInsights\Domain\Insights\ForbiddenDefineFunctions;
use NunoMaduro\PhpInsights\Domain\Insights\ForbiddenNormalClasses;
use NunoMaduro\PhpInsights\Domain\Insights\ForbiddenPrivateMethods;
use NunoMaduro\PhpInsights\Domain\Insights\ForbiddenTraits;
use NunoMaduro\PhpInsights\Domain\Metrics\Architecture\Classes;
use PHP_CodeSniffer\Standards\Generic\Sniffs\Files\LineLengthSniff as GenericLineLengthSniff;
use SlevomatCodingStandard\Sniffs\Commenting\UselessFunctionDocCommentSniff;
use SlevomatCodingStandard\Sniffs\Files\LineLengthSniff;
use SlevomatCodingStandard\Sniffs\Functions\FunctionLengthSniff;
use SlevomatCodingStandard\Sniffs\Namespaces\AlphabeticallySortedUsesSniff;
use SlevomatCodingStandard\Sniffs\TypeHints\DeclareStrictTypesSniff;
use SlevomatCodingStandard\Sniffs\TypeHints\DisallowMixedTypeHintSniff;
use SlevomatCodingStandard\Sniffs\TypeHints\ParameterTypeHintSniff;
use SlevomatCodingStandard\Sniffs\TypeHints\PropertyTypeHintSniff;
use SlevomatCodingStandard\Sniffs\TypeHints\ReturnTypeHintSniff;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Preset
    |--------------------------------------------------------------------------
    |
    | This option controls the default preset that will be used by PHP Insights
    | to make your code reliable, simple, and clean. However, you can always
    | adjust the `Metrics` and `Insights` below in this configuration file.
    |
    | Supported: "default", "laravel", "symfony", "magento2", "drupal", "wordpress"
    |
    */

    'preset' => 'laravel',

    /*
    |--------------------------------------------------------------------------
    | IDE
    |--------------------------------------------------------------------------
    |
    | This options allow to add hyperlinks in your terminal to quickly open
    | files in your favorite IDE while browsing your PhpInsights report.
    |
    | Supported: "textmate", "macvim", "emacs", "sublime", "phpstorm",
    | "atom", "vscode".
    |
    | If you have another IDE that is not in this list but which provide an
    | url-handler, you could fill this config with a pattern like this:
    |
    | myide://open?url=file://%f&line=%l
    |
    */

    'ide' => 'phpstorm',

    /*
    |--------------------------------------------------------------------------
    | Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may adjust all the various `Insights` that will be used by PHP
    | Insights. You can either add, remove or configure `Insights`. Keep in
    | mind, that all added `Insights` must belong to a specific `Metric`.
    |
    */

    'exclude' => [
        //  'path/to/directory-or-file'
        'app/Http/Middleware',
        'app/Providers',
        'routes',
        'lang',
    ],

    'add' => [
        Classes::class => [
            LineLengthSniff::class,
            CyclomaticComplexityIsHigh::class,
        ],
    ],

    'remove' => [
        AlphabeticallySortedUsesSniff::class,
        DeclareStrictTypesSniff::class,
        DisallowMixedTypeHintSniff::class,
        ForbiddenDefineFunctions::class,
        ForbiddenNormalClasses::class,
        ForbiddenTraits::class,
        ParameterTypeHintSniff::class,
        PropertyTypeHintSniff::class,
        ReturnTypeHintSniff::class,
        UselessFunctionDocCommentSniff::class,
        GenericLineLengthSniff::class,
    ],

    'config' => [
        ForbiddenPrivateMethods::class => [
            'title' => 'The usage of private methods is not idiomatic in Laravel.',
        ],
        LineLengthSniff::class => [
            'lineLengthLimit' => 160,
            'absoluteLineLimit' => 180,
            'ignoreImports' => true,
        ],
        FunctionLengthSniff::class => [
            'maxLinesLength' => 50,
            'includeComments' => false,
            'includeWhitespace' => false,
        ],
        CyclomaticComplexityIsHigh::class => [
            'maxComplexity' => 15,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Requirements
    |--------------------------------------------------------------------------
    |
    | Here you may define a level you want to reach per `Insights` category.
    | When a score is lower than the minimum level defined, then an error
    | code will be returned. This is optional and individually defined.
    |
    */

    'requirements' => [
        'min-quality' => 100,
        'min-complexity' => 100,
        'min-architecture' => 100,
        'min-style' => 100,
        //        'disable-security-check' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Threads
    |--------------------------------------------------------------------------
    |
    | Here you may adjust how many threads (core) PHPInsights can use to perform
    | the analysis. This is optional, don't provide it and the tool will guess
    | the max core number available. It accepts null value or integer > 0.
    |
    */

    'threads' => null,

    /*
    |--------------------------------------------------------------------------
    | Timeout
    |--------------------------------------------------------------------------
    | Here you may adjust the timeout (in seconds) for PHPInsights to run before
    | a ProcessTimedOutException is thrown.
    | This accepts an int > 0. Default is 60 seconds, which is the default value
    | of Symfony's setTimeout function.
    |
    */

    'timeout' => 60,
];
```
