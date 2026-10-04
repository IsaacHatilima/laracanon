<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Support;

final readonly class ProcessResult
{
    public function __construct(public int $exitCode, public string $output) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }
}
