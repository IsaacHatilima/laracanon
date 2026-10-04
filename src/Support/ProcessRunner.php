<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Support;

interface ProcessRunner
{
    /** @param list<string> $command */
    public function run(array $command, string $cwd): ProcessResult;
}
