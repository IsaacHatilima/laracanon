<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Dependencies;

use Isaachatilima\Laracanon\Items\Dependency;
use Isaachatilima\Laracanon\Support\InstallationProgress;
use Isaachatilima\Laracanon\Support\ProcessRunner;
use RuntimeException;
use Throwable;

final readonly class ComposerDependencies
{
    public function __construct(private ProcessRunner $runner, private ?InstallationProgress $progress = null) {}

    /**
     * @param  list<Dependency>  $dependencies
     * @param  array<string, list<string>>  $composerPlugins
     * @return array{changes: list<string>, failures: list<string>, notes: list<string>, available: list<string>}
     */
    public function install(string $projectPath, array $dependencies, bool $dryRun = false, array $composerPlugins = []): array
    {
        $report = ['changes' => [], 'failures' => [], 'notes' => [], 'available' => []];
        $unique = [];

        foreach ($dependencies as $dependency) {
            // A runtime requirement cannot be fulfilled by require-dev.
            if (! isset($unique[$dependency->package]) || $dependency->type === 'runtime') {
                $unique[$dependency->package] = $dependency;
            }
        }

        try {
            $initial = ComposerState::inspect($projectPath);
        } catch (Throwable $exception) {
            $report['failures'][] = $exception->getMessage();

            return $report;
        }

        $integrityBroken = false;
        $blockedPlugin = null;
        $pluginsPrepared = null;
        $plugins = [];
        foreach ($unique as $package => $dependency) {
            array_push($plugins, ...($composerPlugins[$package] ?? []));
        }
        $plugins = array_values(array_unique($plugins));

        $processed = [];
        foreach ($unique as $dependency) {
            if (isset($processed[$dependency->package])) {
                continue;
            }
            $group = [$dependency];
            if (! $dryRun && $initial->section($dependency->package) === null) {
                $group = array_values(array_filter($unique, static fn (Dependency $candidate): bool => $candidate->type === $dependency->type
                    && $initial->section($candidate->package) === null));
            }
            foreach ($group as $member) {
                $processed[$member->package] = true;
            }
            try {
                $this->progress?->stage('Checking dependency '.$dependency->package);
                $state = ComposerState::inspect($projectPath);
                if (count($group) > 1) {
                    $this->installBatch($projectPath, $group, $state, $initial, $report, $integrityBroken, $blockedPlugin, $plugins, $pluginsPrepared);
                } else {
                    $this->installDependency($projectPath, $dependency, $state, $initial, $dryRun, $report, $integrityBroken, $blockedPlugin, $plugins, $pluginsPrepared);
                }
            } catch (Throwable $exception) {
                foreach ($group as $member) {
                    $report['failures'][] = $member->package.': '.$exception->getMessage();
                }
            }
        }

        // Grouping changes execution order, but reports retain selection order.
        $report['available'] = array_values(array_filter(array_keys($unique), static fn (string $package): bool => in_array($package, $report['available'], true)));

        return $report;
    }

    /**
     * @param  list<Dependency>  $dependencies
     * @param  array{changes: list<string>, failures: list<string>, notes: list<string>, available: list<string>}  $report
     */
    private function installBatch(string $projectPath, array $dependencies, ComposerState $state, ComposerState $initial, array &$report, bool &$integrityBroken, ?string &$blockedPlugin, array $plugins, ?bool &$pluginsPrepared): void
    {
        $pending = [];
        foreach ($dependencies as $dependency) {
            // An earlier section or project script may have declared a member.
            if ($state->section($dependency->package) !== null || $integrityBroken || $blockedPlugin !== null) {
                $this->installDependency($projectPath, $dependency, $state, $initial, false, $report, $integrityBroken, $blockedPlugin, $plugins, $pluginsPrepared);
                $state = ComposerState::inspect($projectPath);
            } else {
                $pending[] = $dependency;
            }
        }
        if ($pending === []) {
            return;
        }
        if (count($pending) === 1 || $integrityBroken || $blockedPlugin !== null || $this->preservationFailures($initial, $state) !== [] || $this->mutationFailure($state) !== null) {
            foreach ($pending as $dependency) {
                $this->installDependency($projectPath, $dependency, ComposerState::inspect($projectPath), $initial, false, $report, $integrityBroken, $blockedPlugin, $plugins, $pluginsPrepared);
            }

            return;
        }

        $plans = [];
        foreach ($pending as $dependency) {
            $plan = $this->newRequirement($dependency, $state, $report);
            if ($plan !== null) {
                $plans[$dependency->package] = $plan;
            }
        }
        $pending = array_values(array_filter($pending, static fn (Dependency $dependency): bool => isset($plans[$dependency->package])));
        if ($pending === []) {
            return;
        }

        if ($pluginsPrepared === null) {
            $pluginsPrepared = false;
            $pluginsPrepared = (new ComposerPluginPolicy($this->runner, $this->progress))->prepare($projectPath, $plugins, $state, false, $report);
        }
        if (! $pluginsPrepared) {
            foreach ($pending as $dependency) {
                $report['failures'][] = $dependency->package.': Composer operation skipped because item plugin configuration did not complete.';
            }

            return;
        }

        // Capture after the separately reported item plugin permission changes.
        $state = ComposerState::inspect($projectPath);
        $snapshot = $this->mutationSnapshot($projectPath, $state);
        $command = array_merge(['composer', 'require'], array_map(static fn (string $package): string => $package.':'.$plans[$package]['constraint'], array_keys($plans)), ['--prefer-stable', '--no-interaction', '--no-progress']);
        if ($pending[0]->type === 'development') {
            $command[] = '--dev';
        }
        $this->progress?->stage('Installing '.count($pending).' '.$pending[0]->type.' dependencies');
        $result = $this->runner->run($command, $projectPath);
        $after = ComposerState::inspect($projectPath);
        $violations = array_values(array_unique(array_merge($this->preservationFailures($initial, $after), $this->preservationFailures($state, $after))));
        array_push($report['failures'], ...$violations);
        if ($violations !== []) {
            $report['available'] = [];
            $integrityBroken = true;
        }

        // Require rolls back a failed solve. Retry independently only when the
        // precise resolver error and unchanged files prove no installation ran.
        if (count($pending) > 1 && ! $result->successful() && $result->exitCode === 2 && $violations === []
            && $this->isResolutionFailure($result->output)
            && $this->blockedPlugin($result->output) === null
            && $snapshot === $this->mutationSnapshot($projectPath, $after)) {
            $report['notes'][] = 'Composer could not resolve '.implode(', ', array_keys($plans)).' together; unchanged Composer files verified. Retrying dependencies individually to preserve independent item progress.';
            foreach ($pending as $dependency) {
                $this->installDependency($projectPath, $dependency, ComposerState::inspect($projectPath), $initial, false, $report, $integrityBroken, $blockedPlugin, $plugins, $pluginsPrepared);
            }

            return;
        }
        if (! $result->successful()) {
            $plugin = $this->blockedPlugin($result->output);
            if ($plugin !== null) {
                $blockedPlugin = $plugin['package'];
                $report['notes'][] = $plugin['recovery'];
            }
        }
        foreach ($pending as $dependency) {
            $package = $dependency->package;
            if (! $result->successful()) {
                $report['failures'][] = $package.': Composer exited '.$result->exitCode.'. '.trim($result->output);
                if ($after->section($package) !== $state->section($package)) {
                    $report['changes'][] = $package.' requirement changed before Composer failed; inspect composer.json and composer.lock.';
                }
                if (isset($after->installed[$package])) {
                    $report['notes'][] = $package.' is installed at '.$after->installed[$package].', but Composer failed; fix the reported problem and rerun composer install before retrying the incomplete item.';
                }

                continue;
            }
            if ($after->section($package) === null || ! isset($after->installed[$package])) {
                $report['failures'][] = $package.': Composer reported success, but the package is not both declared and installed. Inspect the Composer output: '.trim($result->output);

                continue;
            }
            if ($dependency->type === 'runtime' && $after->section($package) !== 'require') {
                $report['failures'][] = $package.': Composer completed, but this runtime dependency is in require-dev. Review composer.json and place it in require before retrying; Laracanon did not mark it available.';

                continue;
            }
            $report['changes'][] = $plans[$package]['description'].' Installed '.$after->installed[$package].'.';
            if ($violations === []) {
                $report['available'][] = $package;
            }
        }
    }

    private function isResolutionFailure(string $output): bool
    {
        $plain = preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $output) ?? $output;

        return str_contains($plain, 'Your requirements could not be resolved to an installable set of packages')
            && ! preg_match('/Script[\s\S]{0,400}returned\s+with\s+error\s+code|(?:pre|post)-(?:update-cmd|install-cmd|autoload-dump)\s+failed|Writing lock file|Installing dependencies from lock file|Generating (?:optimized )?autoload files/i', $plain);
    }

    /** @return array<string, string|null> */
    private function mutationSnapshot(string $project, ComposerState $state): array
    {
        $vendor = $state->manifest['config']['vendor-dir'] ?? 'vendor';
        $vendorPath = str_starts_with($vendor, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $vendor) ? $vendor : $project.'/'.$vendor;
        $snapshot = [];
        foreach ([$project.'/composer.json', $project.'/composer.lock', $vendorPath.'/composer/installed.json'] as $path) {
            $hash = is_file($path) ? @hash_file('sha256', $path) : null;
            if ($hash === false) {
                throw new RuntimeException('Cannot verify Composer file '.$path.' before dependency recovery.');
            }
            $snapshot[$path] = $hash;
        }

        return $snapshot;
    }

    /** @param array{changes: list<string>, failures: list<string>, notes: list<string>, available: list<string>} $report
     * @return array{constraint: string, description: string}|null
     */
    private function newRequirement(Dependency $dependency, ComposerState $state, array &$report): ?array
    {
        $package = $dependency->package;
        $version = $state->installed[$package] ?? $state->locked[$package] ?? null;
        $constraint = $version ?? '*@stable';
        if ($version !== null && (str_starts_with($version, 'dev-') || str_ends_with($version, '-dev'))) {
            $reference = $state->installedReferences[$package] ?? $state->lockedReferences[$package] ?? null;
            if ($reference === null) {
                $report['failures'][] = $package.': existing development branch '.$version.' has no commit reference; Laracanon cannot safely promote it without changing its resolved revision.';

                return null;
            }
            $constraint .= '#'.$reference;
        }
        $section = $dependency->type === 'runtime' ? 'require' : 'require-dev';

        return ['constraint' => $constraint, 'description' => 'Add '.$package.' to '.$section.($version !== null ? ' at existing version '.$version.'.' : ' using the latest compatible stable version.')];
    }

    private function mutationFailure(ComposerState $state): ?string
    {
        if (! $state->hasLock && $state->installed !== []) {
            return 'composer.lock is missing while packages are installed. Laracanon cannot preserve their versions during dependency resolution; restore or generate a reviewed lockfile before retrying.';
        }
        foreach ($state->installed as $package => $version) {
            if (($state->locked[$package] ?? null) !== $version) {
                return 'installed '.$package.' at '.$version.' differs from composer.lock. Reconcile the installed packages and lockfile before retrying; Laracanon cannot safely preserve both versions.';
            }
            if (isset($state->installedReferences[$package]) && ($state->lockedReferences[$package] ?? null) !== $state->installedReferences[$package]) {
                return 'installed revision of '.$package.' differs from composer.lock. Reconcile the installed packages and lockfile before retrying.';
            }
        }

        return null;
    }

    /** @param array{changes: list<string>, failures: list<string>, notes: list<string>, available: list<string>} $report */
    private function installDependency(
        string $projectPath,
        Dependency $dependency,
        ComposerState $state,
        ComposerState $initial,
        bool $dryRun,
        array &$report,
        bool &$integrityBroken,
        ?string &$blockedPlugin,
        array $plugins,
        ?bool &$pluginsPrepared,
    ): void {
        $package = $dependency->package;
        $section = $state->section($package);
        $targetSection = $dependency->type === 'runtime' ? 'require' : 'require-dev';

        if ($integrityBroken || $this->preservationFailures($initial, $state) !== []) {
            $integrityBroken = true;
            $report['available'] = [];
            $report['failures'][] = $package.': stopped because an earlier Composer operation changed existing requirements or versions unexpectedly. Review and reconcile the reported changes before retrying.';

            return;
        }

        if ($dependency->type === 'runtime' && $section === 'require-dev') {
            $report['failures'][] = $package.' is in require-dev but this item needs it at runtime. Move it to require explicitly, preserving its constraint, then retry.';

            return;
        }

        if ($section !== null && isset($state->installed[$package])) {
            $report['notes'][] = $package.' already installed at '.$state->installed[$package].'; preserved '.$section.' constraint '.$state->manifest[$section][$package].'.';
            $report['available'][] = $package;

            return;
        }

        if ($blockedPlugin !== null) {
            $report['failures'][] = $package.': Composer operation skipped while plugin '.$blockedPlugin.' is blocked; resolve its policy, run composer install, then retry.';

            return;
        }

        $flags = ['--prefer-stable', '--no-interaction', '--no-progress'];

        if ($section !== null) {
            if (! $state->hasLock || isset($state->locked[$package])) {
                $command = ['composer', 'install', '--no-interaction', '--no-progress'];
                $description = 'Install '.$package.($state->hasLock ? ' from composer.lock' : ' with existing requirements').'; preserve '.$section.' constraint '.$state->manifest[$section][$package].'.';
            } else {
                $command = array_merge(['composer', 'update', $package], $flags);
                $description = 'Install declared '.$package.' with its existing '.$section.' constraint '.$state->manifest[$section][$package].'.';
            }
        } else {
            // Lock-only packages must also retain their resolved version when
            // becoming direct requirements. Installed packages take precedence.
            $plan = $this->newRequirement($dependency, $state, $report);
            if ($plan === null) {
                return;
            }
            $command = array_merge(['composer', 'require', $package.':'.$plan['constraint']], $flags);

            if ($targetSection === 'require-dev') {
                $command[] = '--dev';
            }

            $description = $plan['description'];
        }

        if (($failure = $this->mutationFailure($state)) !== null) {
            $report['failures'][] = $package.': '.$failure;

            return;
        }

        if ($pluginsPrepared === null) {
            // Configure selected item declarations before the first Composer
            // mutation, including recovery of a previous partial install.
            $pluginsPrepared = false;
            $pluginsPrepared = (new ComposerPluginPolicy($this->runner, $this->progress))->prepare($projectPath, $plugins, $state, $dryRun, $report);
        }
        if (! $pluginsPrepared) {
            $report['failures'][] = $package.': Composer operation skipped because item plugin configuration did not complete.';

            return;
        }

        if ($dryRun) {
            $report['changes'][] = 'Would '.lcfirst($description);
            $report['notes'][] = 'Compatibility for '.$package.' will be checked by Composer during installation.';

            return;
        }

        $this->progress?->stage('Installing dependency '.$package);
        $result = $this->runner->run($command, $projectPath);
        $after = ComposerState::inspect($projectPath);
        $violations = array_values(array_unique(array_merge(
            $this->preservationFailures($initial, $after),
            $this->preservationFailures($state, $after),
        )));

        foreach ($violations as $violation) {
            $report['failures'][] = $violation;
        }

        if ($violations !== []) {
            // Do not install item resources after a project script/plugin has
            // changed dependencies that this operation promised to preserve.
            $report['available'] = [];
            $integrityBroken = true;
        }

        if (! $result->successful()) {
            $report['failures'][] = $package.': Composer exited '.$result->exitCode.'. '.trim($result->output);
            $plugin = $this->blockedPlugin($result->output);

            if ($plugin !== null) {
                $blockedPlugin = $plugin['package'];
                $report['notes'][] = $plugin['recovery'];
            }

            if ($after->section($package) !== $section) {
                $report['changes'][] = $package.' requirement changed before Composer failed; inspect composer.json and composer.lock.';
            }

            if (isset($after->installed[$package])) {
                $report['notes'][] = $package.' is installed at '.$after->installed[$package].', but Composer failed; fix the reported problem and rerun composer install before retrying the incomplete item.';
            }

            return;
        }

        if ($after->section($package) === null || ! isset($after->installed[$package])) {
            $report['failures'][] = $package.': Composer reported success, but the package is not both declared and installed. Inspect the Composer output: '.trim($result->output);

            return;
        }
        if ($dependency->type === 'runtime' && $after->section($package) !== 'require') {
            $report['failures'][] = $package.': Composer completed, but this runtime dependency is in require-dev. Review composer.json and place it in require before retrying; Laracanon did not mark it available.';

            return;
        }

        $report['changes'][] = $description.' Installed '.$after->installed[$package].'.';

        if ($violations === []) {
            $report['available'][] = $package;
        }
    }

    /** @return array{package: string, recovery: string}|null */
    private function blockedPlugin(string $output): ?array
    {
        // Keep Composer's original diagnostic; remove terminal formatting only
        // while recognizing its specific non-interactive plugin-policy error.
        $plain = preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $output) ?? $output;
        // Symfony's error box can split a word across padded terminal lines.
        // Allow those line breaks inside the exact phrase, not arbitrary spaces.
        $word = static fn (string $word): string => implode(
            '(?:[ \t]*\R[ \t]*)?',
            array_map(static fn (string $character): string => preg_quote($character, '~'), str_split($word)),
        );
        $phrase = implode('\s+', array_map($word, explode(' ', 'contains a Composer plugin which is blocked by your allow-plugins config')));

        if (! preg_match('~([a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*)(?<global>\s+\(installed globally\))?\s+'.$phrase.'\b~i', $plain, $matches)) {
            return null;
        }

        $package = $matches[1];
        $decision = ($matches['global'] ?? '') !== ''
            ? 'Review the existing global Composer plugin decision explicitly; a project permission cannot resolve a blocked global plugin.'
            : 'Review the project permission. If trusted, allow only this plugin in the application with composer config --no-plugins allow-plugins.'.$package.' true. Explicit false disables the plugin; an existing denial remains a project decision.';

        return [
            'package' => $package,
            'recovery' => 'Composer blocked plugin '.$package.'; Laracanon did not change allow-plugins. '.$decision.' After resolving the policy, run composer install, then retry canon:install.',
        ];
    }

    /** @return list<string> */
    private function preservationFailures(ComposerState $before, ComposerState $after): array
    {
        $failures = [];

        foreach (['require', 'require-dev'] as $section) {
            foreach ($before->manifest[$section] ?? [] as $package => $constraint) {
                if (($after->manifest[$section][$package] ?? null) !== $constraint) {
                    $failures[] = 'Existing '.$section.' constraint for '.$package.' changed unexpectedly; inspect composer.json.';
                }
            }
        }

        foreach ($before->installed as $package => $version) {
            if (($after->installed[$package] ?? null) !== $version) {
                $failures[] = 'Existing installed version of '.$package.' changed unexpectedly from '.$version.'; inspect Composer changes.';
            }
        }

        foreach ($before->locked as $package => $version) {
            if (($after->locked[$package] ?? null) !== $version) {
                $failures[] = 'Existing locked version of '.$package.' changed unexpectedly from '.$version.'; inspect composer.lock.';
            }
        }

        foreach (['installedReferences' => 'installed', 'lockedReferences' => 'locked'] as $property => $label) {
            foreach ($before->{$property} as $package => $reference) {
                if (($after->{$property}[$package] ?? null) !== $reference) {
                    $failures[] = 'Existing '.$label.' revision of '.$package.' changed unexpectedly from '.$reference.'; inspect Composer changes.';
                }
            }
        }

        return array_values(array_unique($failures));
    }
}
