<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Boost;

use Isaachatilima\Laracanon\Support\InstallationProgress;
use Isaachatilima\Laracanon\Support\ProcessRunner;
use Throwable;

class BoostIntegration
{
    public function __construct(private readonly ProcessRunner $processes, private readonly ?InstallationProgress $progress = null) {}

    /** @return list<string> */
    public function packageSkillNames(string $projectPath): array
    {
        return (new PackageSkillDiscovery)->names($projectPath);
    }

    /** @return array{resources: list<string>, failures: list<string>, notes: list<string>, conflicts: list<string>} */
    public function refresh(string $projectPath, bool $dryRun = false, array $selectedPackages = []): array
    {
        $this->progress?->stage($dryRun ? 'Previewing Laravel Boost rules and skills' : 'Refreshing Laravel Boost rules and skills');
        if ($dryRun) {
            return [
                'resources' => [],
                'failures' => [],
                'conflicts' => [],
                'notes' => ['Would configure missing Boost resources, discover installed package guidance and skills, and regenerate the project rules index.'],
            ];
        }

        try {
            // Bootstrap a fresh application after Composer installation: the parent
            // process has already loaded its package registry and service providers.
            $result = $this->processes->run([
                PHP_BINARY,
                dirname(__DIR__, 2).'/resources/boost-refresh.php',
                $projectPath,
                json_encode(array_values($selectedPackages), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ], $projectPath);

            if (preg_match('/LARACANON_BOOST_RESULT:(\{[^\r\n]*\})/', $result->output, $matches) === 1) {
                $report = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);

                if (is_array($report) && isset($report['resources'], $report['failures'], $report['notes'])) {
                    return $report;
                }
            }

            return ['resources' => [], 'failures' => ['Boost refresh failed: '.trim($result->output)], 'notes' => [], 'conflicts' => []];
        } catch (Throwable $exception) {
            return ['resources' => [], 'failures' => ['Boost refresh failed: '.$exception->getMessage()], 'notes' => [], 'conflicts' => []];
        }
    }
}
