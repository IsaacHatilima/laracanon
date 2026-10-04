<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Installation;

final class InstallReport
{
    public array $dependencies = [];

    public array $rules = [];

    public array $skills = [];

    public array $files = [];

    public array $resources = [];

    public array $conflicts = [];

    public array $failures = [];

    public array $notes = [];

    public array $items = [];

    public function __construct(public readonly bool $dryRun = false) {}

    public function successful(): bool
    {
        return $this->failures === [] && $this->conflicts === [];
    }
}
