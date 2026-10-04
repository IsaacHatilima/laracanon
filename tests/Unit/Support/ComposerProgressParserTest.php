<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Tests\Unit\Support;

use Isaachatilima\Laracanon\Support\ComposerProgressParser;
use Isaachatilima\Laracanon\Support\InstallationProgress;
use PHPUnit\Framework\TestCase;

final class ComposerProgressParserTest extends TestCase
{
    public function test_known_composer_phases_emit_only_fixed_messages(): void
    {
        $events = [];
        $parser = $this->parser($events);
        $parser->consume('err', implode("\n", [
            'Loading composer repositories with package information',
            'Updating dependencies',
            'Writing lock file',
            'Installing dependencies from lock file (including require-dev)',
            'Package operations: 30 installs, 0 updates, 0 removals',
            '  - Downloading spatie/laravel-data (4.23.0)',
            '  - Installing spatie/laravel-data (4.23.0): Extracting archive',
            'Generating optimized autoload files',
            '> Illuminate\\Foundation\\ComposerScripts::postAutoloadDump',
            '> @php artisan package:discover --ansi',
            '> post-update-cmd: @php artisan boost:update --ansi',
            'Boost guidelines and skills updated successfully.',
            '> @php artisan another:project-script --token=private-value',
            'No security vulnerability advisories found.',
            '',
        ]));

        self::assertSame([
            'Composer: loading package metadata',
            'Composer: resolving compatible versions',
            'Composer: writing the lockfile',
            'Composer: installing packages',
            'Composer: downloading packages',
            'Composer: installing packages',
            'Composer: generating autoload files',
            'Composer: running project scripts',
            'Composer: discovering Laravel packages',
            'Composer: updating Laravel Boost resources',
            'Composer: finalizing',
            'Composer: running project scripts',
            'Composer: security advisory check complete',
        ], $events);
        self::assertStringNotContainsString('private-value', implode("\n", $events));
    }

    public function test_fragmented_ansi_lines_and_independent_streams_are_recognized(): void
    {
        $events = [];
        $parser = $this->parser($events);
        $parser->consume('err', "\x1B[32mLoading composer repositories with ");
        $parser->consume('out', 'Updating depend');
        $parser->consume('err', "package information\x1B[0m\r\n");
        $parser->consume('out', "encies\nGenerating autoload files");
        self::assertSame(['Composer: loading package metadata', 'Composer: resolving compatible versions'], $events);

        $parser->finish();
        self::assertSame([
            'Composer: loading package metadata',
            'Composer: resolving compatible versions',
            'Composer: generating autoload files',
        ], $events);
    }

    public function test_unknown_output_and_oversized_lines_cannot_publish_log_content(): void
    {
        $events = [];
        $parser = $this->parser($events);
        $parser->consume('err', 'Downloading https://private.example/package?token=secret'."\n");
        $parser->consume('err', str_repeat('x', 8193));
        $parser->consume('out', "Generating autoload files\n");
        $parser->consume('err', "Updating dependencies\nUpdating dependencies\n");
        $parser->consume('err', str_repeat('x', 8193)."\n");
        $parser->finish();

        self::assertSame(['Composer: generating autoload files', 'Composer: resolving compatible versions'], $events);
    }

    public function test_only_composer_invocations_enable_parsing(): void
    {
        foreach ([['composer', 'require', 'spatie/laravel-data'], ['/usr/local/bin/composer', 'install'], [PHP_BINARY, '/tmp/composer.phar', 'update'], ['php', 'composer', 'install'], ['C:\\tools\\composer.exe', 'install']] as $command) {
            self::assertTrue(ComposerProgressParser::supports($command), json_encode($command));
        }

        foreach ([[], [PHP_BINARY, '-r', 'echo "Updating dependencies";'], [PHP_BINARY, '/tmp/boost-refresh.php'], ['bash', '/tmp/composer'], ['composer-tools', 'install']] as $command) {
            self::assertFalse(ComposerProgressParser::supports($command), json_encode($command));
        }
    }

    private function parser(array &$events): ComposerProgressParser
    {
        $progress = new InstallationProgress;
        $progress->listen(static function (string $message) use (&$events): void {
            $events[] = $message;
        }, null);

        return new ComposerProgressParser($progress);
    }
}
