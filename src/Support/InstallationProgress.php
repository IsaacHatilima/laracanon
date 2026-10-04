<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Support;

use Closure;

final class InstallationProgress
{
    private ?Closure $onStage = null;

    private ?Closure $onTick = null;

    private ?string $lastStage = null;

    public function listen(?Closure $onStage, ?Closure $onTick): void
    {
        $this->onStage = $onStage;
        $this->onTick = $onTick;
        $this->lastStage = null;
    }

    public function stage(string $message): void
    {
        if ($message === $this->lastStage) {
            return;
        }
        $this->lastStage = $message;
        if ($this->onStage !== null) {
            ($this->onStage)($message);
        }
    }

    public function tick(): void
    {
        if ($this->onTick !== null) {
            ($this->onTick)();
        }
    }

    public function clear(): void
    {
        $this->listen(null, null);
    }
}
