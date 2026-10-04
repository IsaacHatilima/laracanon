<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Support;

final class ItemFilePath
{
    public static function isConfiguration(string $path): bool
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*\.neon(?:\.dist)?$/D', $path) === 1) {
            return true;
        }

        return preg_match('/^config\/(?:[A-Za-z0-9_.-]+\/)*[A-Za-z0-9_.-]+$/D', $path) === 1
            && array_intersect(['.', '..'], explode('/', $path)) === [];
    }
}
