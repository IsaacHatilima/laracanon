<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Tests\Integration\Boost;

use Isaachatilima\Laracanon\Boost\PackageSkillDiscovery;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class PackageSkillDiscoveryTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir().'/laracanon-discovery-'.bin2hex(random_bytes(8));
        mkdir($this->project);
    }

    protected function tearDown(): void
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->project, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->project);
    }

    public function test_custom_vendor_directory_and_install_path_metadata_are_honored(): void
    {
        $this->write('composer.json', '{"require-dev":{"fixture/toolkit":"^1.0"},"config":{"vendor-dir":"dependencies"}}');
        $this->write('dependencies/composer/installed.json', '{"packages":[{"name":"fixture/toolkit","install-path":"../../packages/toolkit"}]}');
        $this->write('packages/toolkit/resources/boost/skills/directory-name/SKILL.md', "---\nname: package-authored-name\ndescription: Use the package workflow.\n---\n\nWorkflow.\n");

        self::assertSame(['package-authored-name'], (new PackageSkillDiscovery)->names($this->project));
    }

    public function test_transitive_skills_are_not_considered_and_built_in_boost_skills_are_reserved(): void
    {
        $this->write('composer.json', '{"require-dev":{"laravel/boost":"^2.0"}}');
        $this->write('vendor/composer/installed.json', '{"packages":[{"name":"laravel/boost"},{"name":"fixture/transitive"}]}');
        $this->write('vendor/laravel/boost/.ai/boost/skill/infer-conventions/SKILL.blade.php', "---\nname: infer-conventions\ndescription: Infer project conventions.\n---\n\nWorkflow.\n");
        $this->write('vendor/fixture/transitive/resources/boost/skills/transitive/SKILL.md', "---\nname: transitive\ndescription: Indirect workflow.\n---\n\nWorkflow.\n");

        self::assertSame(['infer-conventions'], (new PackageSkillDiscovery)->names($this->project));
    }

    public function test_malformed_skill_still_reserves_its_directory_name(): void
    {
        $this->write('composer.json', '{"require-dev":{"fixture/toolkit":"^1.0"}}');
        $this->write('vendor/fixture/toolkit/resources/boost/skills/reserved-name/SKILL.md', "---\nname: [invalid\n---\n\nWorkflow.\n");

        self::assertSame(['reserved-name'], (new PackageSkillDiscovery)->names($this->project));
    }

    private function write(string $path, string $contents): void
    {
        $absolute = $this->project.'/'.$path;

        if (! is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0755, true);
        }

        file_put_contents($absolute, $contents);
    }
}
