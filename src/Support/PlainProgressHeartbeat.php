<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Support;

use Closure;

final class PlainProgressHeartbeat
{
    private const INTERVAL_SECONDS = 15;

    private readonly Closure $clock;

    private readonly float $startedAt;

    private float $lastOutputAt;

    public function __construct(private string $stage, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
        $this->startedAt = $this->lastOutputAt = ($this->clock)();
    }

    public function stage(string $stage): void
    {
        $this->stage = $stage;
        $this->lastOutputAt = ($this->clock)();
    }

    public function message(): ?string
    {
        $now = ($this->clock)();
        if ($now - $this->lastOutputAt < self::INTERVAL_SECONDS) {
            return null;
        }
        $this->lastOutputAt = $now;

        return 'Still working: '.$this->stage.' ('.(int) ($now - $this->startedAt).'s elapsed)';
    }
}
