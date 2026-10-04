<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Dependencies;

use Isaachatilima\Laracanon\Support\InstallationProgress;
use Isaachatilima\Laracanon\Support\ProcessRunner;
use RuntimeException;
use stdClass;

/** Configure only item-authored plugin permissions without replacing existing decisions. */
final readonly class ComposerPluginPolicy
{
    public function __construct(private ProcessRunner $runner, private ?InstallationProgress $progress = null) {}

    /**
     * @param  list<string>  $plugins
     * @param  array{changes: list<string>, failures: list<string>, notes: list<string>, available: list<string>}  $report
     */
    public function prepare(string $project, array $plugins, ComposerState $state, bool $dryRun, array &$report): bool
    {
        if ($plugins === []) {
            return true;
        }
        foreach ($plugins as $plugin) {
            if (! is_string($plugin) || ! preg_match('/^[a-z0-9](?:[_.-]?[a-z0-9]+)*\/[a-z0-9](?:[_.-]?[a-z0-9]+)*$/D', $plugin)) {
                throw new RuntimeException('Item Composer plugin permissions must name exact Composer packages without wildcards or constraints.');
            }
        }

        if ($dryRun) {
            $config = $state->manifest['config'] ?? [];
            $policy = array_key_exists('allow-plugins', $config) ? $config['allow-plugins'] : [];
        } else {
            $this->progress?->stage('Checking Composer plugin permissions');
            $result = $this->runner->run(['composer', 'config', '--no-plugins', '--no-scripts', '--no-interaction', 'allow-plugins'], $project);
            if (! $result->successful()) {
                $report['failures'][] = 'Cannot inspect effective Composer plugin policy: Composer exited '.$result->exitCode.'. '.trim($result->output);

                return false;
            }
            $policy = $this->parse($result->output);
        }

        $this->validate($policy);
        foreach (array_unique($plugins) as $plugin) {
            $decision = $this->decision($policy, $plugin);
            if ($decision !== null) {
                $report['notes'][] = 'Composer plugin '.$plugin.': existing '.($decision ? 'allow' : 'deny').' decision preserved'.($decision ? '.' : '; plugin remains disabled.');

                continue;
            }
            if ($dryRun) {
                $report['changes'][] = 'Would allow item-declared Composer plugin '.$plugin.' in the project if no existing effective policy covers it.';
                $report['notes'][] = 'Effective Composer plugin policy, including global decisions, will be checked during installation.';

                continue;
            }

            $before = ComposerState::inspect($project)->manifest;
            $this->progress?->stage('Configuring Composer plugin '.$plugin);
            $result = $this->runner->run(['composer', 'config', '--no-plugins', '--no-scripts', '--no-interaction', 'allow-plugins.'.$plugin, 'true'], $project);
            $after = ComposerState::inspect($project)->manifest;
            $expected = $before;
            $expected['config']['allow-plugins'][$plugin] = true;
            if ($after !== $before && $after !== $expected) {
                throw new RuntimeException('Composer plugin configuration changed unrelated application settings; inspect composer.json before retrying.');
            }
            if (! $result->successful()) {
                $report['failures'][] = 'Cannot configure Composer plugin '.$plugin.': Composer exited '.$result->exitCode.'. '.trim($result->output);
                if ($after !== $before) {
                    $report['changes'][] = 'Composer plugin '.$plugin.' permission changed before configuration failed; inspect composer.json.';
                }

                return false;
            }
            if (($after['config']['allow-plugins'][$plugin] ?? null) !== true) {
                $report['failures'][] = 'Composer reported success but did not configure the named plugin permission '.$plugin.'.';

                return false;
            }
            $report['changes'][] = 'Allowed item-declared Composer plugin '.$plugin.' in the project.';
            $policy = (array) $policy;
            $policy[$plugin] = true;
        }

        return true;
    }

    private function parse(string $output): mixed
    {
        // The runner combines stdout and stderr; Composer may include warnings
        // alongside its single-line JSON configuration value.
        $plain = preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $output) ?? $output;
        preg_match_all('/^(?:\{[^\r\n]*\}|\[\]|true|false)[ \t\r]*$/m', $plain, $matches);
        if (count($matches[0]) !== 1) {
            throw new RuntimeException('Cannot parse effective Composer allow-plugins policy; existing settings preserved.');
        }

        return json_decode(trim($matches[0][0]), flags: JSON_THROW_ON_ERROR);
    }

    private function validate(mixed $policy): void
    {
        if (is_bool($policy)) {
            return;
        }
        if (! is_array($policy) && ! $policy instanceof stdClass) {
            throw new RuntimeException('Composer allow-plugins policy must be a boolean or a mapping of package patterns to booleans.');
        }
        foreach ($policy as $pattern => $allowed) {
            if (! is_string($pattern) || $pattern === '' || ! is_bool($allowed)) {
                throw new RuntimeException('Composer allow-plugins policy must map package patterns to boolean decisions.');
            }
        }
    }

    private function decision(mixed $policy, string $plugin): ?bool
    {
        if (is_bool($policy)) {
            return $policy;
        }
        foreach ($policy as $pattern => $allowed) {
            // Composer treats only * as a wildcard and uses the first match.
            $regex = '/^'.str_replace('\\*', '.*', preg_quote($pattern, '/')).'$/iD';
            if (preg_match($regex, $plugin)) {
                return $allowed;
            }
        }

        return null;
    }
}
