<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Installation;

use RuntimeException;

/** Native discovery paths point to the one shared copy; they never hold another copy. */
final readonly class SharedSkillLinks
{
    public function __construct(private string $root, private bool $dryRun) {}

    public function validate(string $alias, string $target): void
    {
        if (! preg_match('#^\.(?:ai|claude)/skills/(?:[a-z0-9]+(?:-[a-z0-9]+)*|catalog\.md)$#D', $alias) || $target !== '.agents/skills/'.basename($alias)) {
            throw new RuntimeException('Unsafe shared skill link: '.$alias.'.');
        }
        foreach ([dirname($alias), $target] as $relative) {
            $absolute = rtrim($this->root, '/');
            foreach (explode('/', $relative) as $part) {
                $absolute .= '/'.$part;
                if (is_link($absolute)) {
                    throw new RuntimeException('Refusing symlink in shared skill path '.$relative.'.');
                }
            }
        }
        $absolute = $this->root.'/'.$alias;
        if (is_link($absolute) && readlink($absolute) !== '../../'.$target) {
            throw new RuntimeException('Refusing unrelated symlink at '.$alias.'.');
        }
    }

    /** @param list<string> $retired Files already verified for removal by ManagedFiles. */
    public function install(string $alias, string $target, array $retired = []): string
    {
        $this->validate($alias, $target);
        $absolute = $this->root.'/'.$alias;
        if (is_link($absolute)) {
            return 'unchanged';
        }
        if (! $this->available($alias, $retired)) {
            return 'conflict';
        }
        if ($this->dryRun) {
            return 'planned';
        }
        if (! file_exists($this->root.'/'.$target)) {
            throw new RuntimeException('Shared skill target is missing: '.$target.'.');
        }
        $this->removeEmpty($absolute);
        if (! is_dir(dirname($absolute)) && ! @mkdir(dirname($absolute), 0777, true) && ! is_dir(dirname($absolute))) {
            throw new RuntimeException('Cannot create shared skill link directory.');
        }
        if (! @symlink('../../'.$target, $absolute)) {
            throw new RuntimeException('Cannot create skill link '.$alias.'; enable symlink support and retry.');
        }

        return 'installed';
    }

    /** @param list<string> $retired */
    private function available(string $relative, array $retired): bool
    {
        $absolute = $this->root.'/'.$relative;
        if (is_link($absolute)) {
            return false;
        }
        if (is_file($absolute)) {
            return $this->dryRun && in_array($relative, $retired, true);
        }
        if (! file_exists($absolute)) {
            return true;
        }
        if (! is_dir($absolute)) {
            return false;
        }
        foreach (scandir($absolute) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && ! $this->available($relative.'/'.$entry, $retired)) {
                return false;
            }
        }

        return true;
    }

    private function removeEmpty(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeEmpty($directory.'/'.$entry);
            }
        }
        if (! @rmdir($directory)) {
            throw new RuntimeException('Cannot replace old skill directory '.$directory.'.');
        }
    }
}
