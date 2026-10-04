<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Installation;

use Isaachatilima\Laracanon\Boost\BoostIntegration;
use Isaachatilima\Laracanon\Dependencies\ComposerDependencies;
use Isaachatilima\Laracanon\Dependencies\ComposerState;
use Isaachatilima\Laracanon\Items\Dependency;
use Isaachatilima\Laracanon\Items\Item;
use Isaachatilima\Laracanon\Items\ItemCatalog;
use Isaachatilima\Laracanon\Support\InstallationProgress;
use Throwable;

final class Installer
{
    public function __construct(
        private readonly ItemCatalog $catalog,
        private readonly ComposerDependencies $dependencies,
        private readonly BoostIntegration $boost,
        private readonly ?InstallationProgress $progress = null,
    ) {}

    public function install(string $project, array $names = [], bool $dryRun = false): InstallReport
    {
        $report = new InstallReport($dryRun);
        $lock = null;
        try {
            $this->progress?->stage('Checking Laravel application');
            Project::validate($project);
            $this->progress?->stage('Loading selected items');
            $items = $names === [] ? $this->catalog->all() : [];
            if ($names !== []) {
                foreach (array_unique($names) as $name) {
                    $items[$name] = $this->catalog->find($name);
                }
            }
            if ($items === []) {
                throw new \RuntimeException('No items available. Add Markdown items to the configured catalog before installing.');
            }
            // Validate ownership state before Composer or any other mutation.
            $this->progress?->stage('Checking compatibility and managed files');
            $files = new ManagedFiles($project, $dryRun);
            $resolvedPaths = [];
            $items = $this->compatibleItems($items, $project, $dryRun, $report);
            if ($items === []) {
                return $report;
            }
            if (! $this->validateOutputs($items, $files, $report, $resolvedPaths)) {
                return $report;
            }
            $lockPath = $files->lockPath();
            if (! $dryRun) {
                $directory = $project.'/.ai/laracanon';
                if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
                    throw new \RuntimeException('Cannot create Laracanon installation directory.');
                }
                $lock = @fopen($lockPath, 'c');
                if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
                    throw new \RuntimeException('Another Laracanon installation is running.');
                }
                // Re-read after taking the lock.
                $files = new ManagedFiles($project, false);
                if (! $this->validateOutputs($items, $files, $report, $resolvedPaths)) {
                    return $report;
                }
            }

            $required = [new Dependency('laravel/boost', 'development')];
            $composerPlugins = [];
            foreach ($items as $item) {
                array_push($required, ...$item->dependencies);
                foreach ($item->composerPlugins as $package => $plugins) {
                    $composerPlugins[$package] = array_values(array_unique(array_merge($composerPlugins[$package] ?? [], $plugins)));
                }
            }
            $this->progress?->stage('Checking dependencies');
            $dependencyResult = $this->dependencies->install($project, $required, $dryRun, composerPlugins: $composerPlugins);
            $report->dependencies = $dependencyResult['changes'];
            array_push($report->failures, ...$dependencyResult['failures']);
            array_push($report->notes, ...$dependencyResult['notes']);
            $available = $dependencyResult['available'];
            // Composer scripts may create a preferred configuration. Resolve
            // once more, then keep these destinations stable across item writes.
            $this->progress?->stage('Checking managed files after dependency installation');
            if (! $this->validateOutputs($items, $files, $report, $resolvedPaths)) {
                return $report;
            }
            $this->progress?->stage('Discovering package skills');
            $packageSkills = $this->boost->packageSkillNames($project);

            foreach ($items as $item) {
                $missing = array_filter($item->dependencies, fn ($dependency) => ! in_array($dependency->package, $available, true));
                if (! $dryRun && $missing !== []) {
                    $report->items[$item->name] = 'failed dependencies';
                    $report->notes[] = "Skipped {$item->name}: required dependencies did not complete.";

                    continue;
                }
                if (! $dryRun && $item->minimumVersions !== []) {
                    try {
                        $versionFailures = $this->minimumVersionFailures($item, ComposerState::inspect($project)->installed, true);
                    } catch (Throwable $exception) {
                        $versionFailures = ["{$item->name}: cannot verify required dependency versions: {$exception->getMessage()}"];
                    }
                    if ($versionFailures !== []) {
                        array_push($report->failures, ...$versionFailures);
                        $report->items[$item->name] = 'incompatible dependencies';

                        continue;
                    }
                }
                $conflictsBefore = count($report->conflicts);
                $failuresBefore = count($report->failures);
                $previousFiles = $files->item($item->name)['files'] ?? [];
                $managed = $previousFiles;
                $desired = [];
                $blockedSkills = [];
                try {
                    $this->progress?->stage(($dryRun ? 'Previewing item ' : 'Installing item ').$item->name);
                    if ($item->rules !== null) {
                        $path = '.ai/rules/laracanon-'.$item->name.'.md';
                        $desired[] = $path;
                        if ($this->write($files, $path, ItemRenderer::rules($item), $item->name, 'rules', $report)) {
                            $managed[] = $path;
                        }
                    }
                    if ($item->skill !== null) {
                        $path = '.ai/skills/'.$item->skillName.'/SKILL.md';
                        if (in_array($item->skillName, $packageSkills, true) && ! $item->overridesSkill) {
                            $report->conflicts[] = "{$path}: package-provided skill preserved; set overrides_skill: true in the item to explicitly override it.";
                            $blockedSkills[] = $path;
                        } else {
                            $desired[] = $path;
                            if ($this->write($files, $path, ItemRenderer::skill($item), $item->name, 'skills', $report)) {
                                $managed[] = $path;
                            }
                        }
                    }
                    foreach ($item->files as $defaultPath => $contents) {
                        $policy = $item->neonUpdates[$defaultPath] ?? null;
                        $path = $resolvedPaths[$item->name][$defaultPath];
                        $desired[] = $path;
                        $existing = $policy !== null && $files->exists($path);
                        if ($policy !== null && ! $existing) {
                            $contents = (new NeonPatcher)->patch($contents, $policy['set']);
                        }
                        $written = $existing
                            ? $this->writeNeon($files, $path, $policy['set'], $item->name, $report)
                            : $this->write($files, $path, $contents, $item->name, 'files', $report);
                        if ($written) {
                            $managed[] = $path;
                        } elseif ($policy !== null) {
                            // A replacement conflict must not retire the prior configuration.
                            array_push($desired, ...array_intersect($previousFiles, $policy['candidates']));
                        }
                        if ($policy !== null && ($written || $files->exists($path))) {
                            $plannedContents = $files->exists($path) ? null : $contents;
                            foreach ($files->retainedNeonIncludes($path, $previousFiles, $plannedContents) as $retained) {
                                if (! in_array($retained, $desired, true)) {
                                    $desired[] = $retained;
                                    $report->notes[] = "{$retained}: included or potentially referenced configuration preserved.";
                                }
                            }
                        }
                    }
                    foreach ($previousFiles as $previous) {
                        if (! in_array($previous, $desired, true)) {
                            $result = $files->remove($previous, $item->name);
                            if ($result === 'conflict') {
                                $report->conflicts[] = "{$previous}: edited file preserved when removing a retired item section.";
                                if (in_array($previous, $blockedSkills, true)) {
                                    $report->notes[] = "{$previous}: the locally edited custom override remains and may continue to shadow the package skill; resolve this conflict before relying on package guidance.";
                                }
                            } else {
                                $managed = array_values(array_diff($managed, [$previous]));
                                if ($result === 'removed') {
                                    $report->notes[] = "{$previous}: removed retired managed file.";
                                } elseif ($result === 'released') {
                                    $report->notes[] = "{$previous}: retired adopted configuration preserved; managed settings released.";
                                }
                            }
                        }
                    }
                    $status = count($report->conflicts) > $conflictsBefore ? 'conflict' : ($dryRun ? 'planned' : 'installed');
                    $report->items[$item->name] = $status;
                } catch (Throwable $exception) {
                    $report->failures[] = "{$item->name}: {$exception->getMessage()}";
                    $report->items[$item->name] = 'partial failure';
                }
                if (count($report->failures) > $failuresBefore) {
                    $report->items[$item->name] = 'partial failure';
                }
                // Keep every completed and previously owned path even when a
                // later write fails, so retries can safely update or retire it.
                $files->record($item->name, ['source_hash' => $item->sourceHash, 'files' => array_values(array_unique($managed)), 'status' => $report->items[$item->name]]);
            }
            $this->progress?->stage($dryRun ? 'Checking installation state' : 'Saving installation state');
            $files->save();

            if ($dryRun || in_array('laravel/boost', $available, true)) {
                $this->progress?->stage($dryRun ? 'Previewing Laravel Boost rules and skills' : 'Refreshing Laravel Boost rules and skills');
                $result = $this->boost->refresh($project, $dryRun, array_map(fn ($dependency) => $dependency->package, $required));
                $report->resources = $result['resources'];
                array_push($report->failures, ...$result['failures']);
                array_push($report->notes, ...$result['notes']);
                array_push($report->conflicts, ...($result['conflicts'] ?? []));
            } else {
                $report->failures[] = 'Boost refresh skipped: laravel/boost is unavailable. Completed item files are preserved; retry after resolving the dependency failure.';
            }
        } catch (Throwable $exception) {
            $report->failures[] = $exception->getMessage();
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        return $report;
    }

    /** @param array<string, Item> $items
     * @return array<string, Item>
     */
    private function compatibleItems(array $items, string $project, bool $dryRun, InstallReport $report): array
    {
        if (array_filter($items, fn (Item $item) => $item->minimumVersions !== []) === []) {
            return $items;
        }
        $state = ComposerState::inspect($project);
        foreach ($items as $name => $item) {
            $failures = $this->minimumVersionFailures($item, $state->installed, locked: $state->locked);
            if ($failures !== []) {
                array_push($report->failures, ...$failures);
                $report->items[$name] = 'incompatible dependencies';
                unset($items[$name]);

                continue;
            }
            if ($dryRun) {
                foreach ($item->minimumVersions as $package => $minimum) {
                    if (! isset($state->installed[$package]) && ! isset($state->locked[$package])) {
                        $report->notes[] = "{$item->name}: {$package} minimum {$minimum} will be verified after dependency installation; no installed or locked version is available in this dry run.";
                    }
                }
            }
        }

        return $items;
    }

    /** @param array<string, string> $installed
     * @param  array<string, string>  $locked
     * @return list<string>
     */
    private function minimumVersionFailures(Item $item, array $installed, bool $requireInstalled = false, array $locked = []): array
    {
        $failures = [];
        foreach ($item->minimumVersions as $package => $minimum) {
            $current = $installed[$package] ?? $locked[$package] ?? null;
            $source = isset($installed[$package]) ? 'installed' : 'locked';
            if ($current === null) {
                if ($requireInstalled) {
                    $failures[] = "{$item->name}: cannot verify {$package} minimum {$minimum}; no installed version is available. Item files preserved.";
                }

                continue;
            }
            if (! preg_match('/^[vV]?\d+(?:\.\d+){0,3}$/D', $current)) {
                $failures[] = "{$item->name}: cannot verify {$package} minimum {$minimum} against {$source} version {$current}; a stable numeric version is required. Existing constraints and item files preserved.";
            } elseif (version_compare(ltrim($current, 'vV'), $minimum, '<')) {
                $failures[] = "{$item->name}: {$package} requires minimum {$minimum}, but {$current} is {$source}. Existing constraints and item files preserved; resolve compatibility before retrying.";
            }
        }

        return $failures;
    }

    private function write(ManagedFiles $files, string $path, string $contents, string $owner, string $category, InstallReport $report): bool
    {
        $result = $files->put($path, $contents, $owner);
        if ($result === 'conflict') {
            $report->conflicts[] = "{$path}: locally modified or unowned file preserved.";

            return false;
        } else {
            $report->{$category}[] = "{$path} ({$result})";

            return true;
        }
    }

    /** @param array<string, int|string|bool|float|null> $updates */
    private function writeNeon(ManagedFiles $files, string $path, array $updates, string $owner, InstallReport $report): bool
    {
        $result = $files->updateNeon($path, $updates, $owner);
        if ($result === 'conflict') {
            $report->conflicts[] = "{$path}: locally modified managed NEON settings or configuration preserved.";

            return false;
        }

        $report->files[] = "{$path} ({$result})";

        return true;
    }

    /** @param array<string, array<string, string>> $resolvedPaths */
    private function validateOutputs(array $items, ManagedFiles $files, InstallReport $report, array &$resolvedPaths): bool
    {
        $selectedOwners = [];
        $resolvedPaths = [];
        foreach ($items as $item) {
            $paths = [];
            if ($item->rules !== null) {
                $paths[] = '.ai/rules/laracanon-'.$item->name.'.md';
            }
            if ($item->skill !== null) {
                $paths[] = '.ai/skills/'.$item->skillName.'/SKILL.md';
            }
            foreach (array_keys($item->files) as $defaultPath) {
                $policy = $item->neonUpdates[$defaultPath] ?? null;
                $path = $policy === null ? $defaultPath : $files->resolveNeonPath($policy['candidates']);
                $paths[] = $path;
                $resolvedPaths[$item->name][$defaultPath] = $path;
            }

            foreach ($paths as $path) {
                if (isset($selectedOwners[$path])) {
                    if ($selectedOwners[$path] === $item->name) {
                        $report->conflicts[] = "{$path}: item {$item->name} targets this file through multiple output destinations; declare distinct configurations.";
                    } else {
                        $report->conflicts[] = "{$path}: selected items {$selectedOwners[$path]} and {$item->name} both target this file; select distinct output destinations.";
                    }
                }
                $selectedOwners[$path] = $item->name;
                $owner = $files->owner($path);
                if ($owner !== null && $owner !== $item->name) {
                    $report->conflicts[] = "{$path}: managed by item {$owner}; item {$item->name} cannot overwrite another item's file.";
                }
            }
        }

        return $report->conflicts === [];
    }
}
