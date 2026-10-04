<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Items;

final readonly class Dependency
{
    public function __construct(
        public string $package,
        public string $type,
    ) {}
}
