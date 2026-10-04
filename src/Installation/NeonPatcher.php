<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Installation;

use Nette\Neon\Exception as NeonException;
use Nette\Neon\Neon;
use RuntimeException;

/** Changes scalar values in block mappings without serializing unrelated configuration. */
final class NeonPatcher
{
    /** @param array<string, scalar|null> $updates */
    public function patch(string $contents, array $updates): string
    {
        $expected = $this->decode($contents);
        $paths = $this->paths(array_keys($updates));
        foreach ($updates as $path => $value) {
            if (! is_scalar($value) && $value !== null) {
                throw new RuntimeException("NEON update {$path} must be a scalar or null.");
            }
            if (is_float($value) && ! is_finite($value)) {
                throw new RuntimeException("NEON update {$path} must be finite.");
            }
            $this->assign($expected, $paths[$path], $value, $path);
        }

        $result = $contents;
        foreach ($updates as $path => $value) {
            $current = $this->values($result, [$path])[$path];
            if (! $current['exists'] || $current['value'] !== $value) {
                $result = $this->patchValue($result, $paths[$path], $value, $path);
            }
        }
        // A misleading line in an inline map or multiline string must never cause
        // us to change an unrelated value, even when it resembles the target key.
        if (serialize($this->decode($result)) !== serialize($expected)) {
            throw new RuntimeException('Cannot safely patch NEON: unrelated configuration would change.');
        }

        return $result;
    }

    /** @return array<string, array{exists: bool, value: mixed}> */
    public function values(string $contents, array $paths): array
    {
        $decoded = $this->decode($contents);
        $values = [];
        foreach ($this->paths($paths) as $path => $segments) {
            $value = $decoded;
            $exists = true;
            foreach ($segments as $segment) {
                if (! is_array($value) || ! array_key_exists($segment, $value)) {
                    $exists = false;
                    $value = null;
                    break;
                }
                $value = $value[$segment];
            }
            $values[$path] = ['exists' => $exists, 'value' => $value];
        }

        return $values;
    }

    private function decode(string $contents): array
    {
        try {
            $decoded = Neon::decode($contents);
        } catch (NeonException $exception) {
            throw new RuntimeException('Invalid NEON configuration: '.$exception->getMessage(), 0, $exception);
        }
        if ($decoded === null) {
            return [];
        }
        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new RuntimeException('NEON configuration must contain a root mapping.');
        }

        return $decoded;
    }

    private function paths(array $paths): array
    {
        $segments = [];
        foreach ($paths as $path) {
            if (! is_string($path) || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)*$/D', $path)) {
                throw new RuntimeException('Invalid NEON scalar update path.');
            }
            foreach ($segments as $other => $_) {
                if ($path === $other || str_starts_with($path, $other.'.') || str_starts_with($other, $path.'.')) {
                    throw new RuntimeException('NEON update paths must be unique and must not overlap.');
                }
            }
            $segments[$path] = explode('.', $path);
        }

        return $segments;
    }

    private function assign(array &$mapping, array $segments, mixed $value, string $path): void
    {
        $key = array_shift($segments);
        if ($segments === []) {
            if (array_key_exists($key, $mapping) && ! is_scalar($mapping[$key]) && $mapping[$key] !== null) {
                throw new RuntimeException("Cannot patch NEON {$path}: the existing value is not a scalar.");
            }
            $mapping[$key] = $value;

            return;
        }
        if (! array_key_exists($key, $mapping) || $mapping[$key] === null) {
            $mapping[$key] = [];
        }
        if (! is_array($mapping[$key]) || ($mapping[$key] !== [] && array_is_list($mapping[$key]))) {
            throw new RuntimeException("Cannot patch NEON {$path}: {$key} is not a mapping.");
        }
        $this->assign($mapping[$key], $segments, $value, $path);
    }

    private function patchValue(string $contents, array $segments, mixed $value, string $path): string
    {
        if (preg_match('/\r(?!\n)/', $contents)) {
            throw new RuntimeException('Cannot safely patch NEON with bare carriage returns.');
        }
        $newline = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        preg_match_all('/[^\r\n]*(?:\r\n|\n|$)/', $contents, $matches);
        $lines = array_values(array_filter($matches[0], static fn (string $line): bool => $line !== ''));
        $start = 0;
        $end = count($lines);
        $indent = $this->rootIndent($lines);

        foreach ($segments as $position => $segment) {
            $candidates = [];
            for ($line = $start; $line < $end; $line++) {
                $entry = $this->entry($lines[$line]);
                if ($entry !== null && $entry['indent'] === $indent && $entry['key'] === $segment) {
                    $candidates[$line] = $entry;
                }
            }
            if (count($candidates) > 1) {
                throw new RuntimeException("Cannot safely patch NEON {$path}: ambiguous block key {$segment}.");
            }
            if ($candidates === []) {
                $insertion = '';
                foreach (array_slice($segments, $position) as $offset => $missing) {
                    $last = $position + $offset === count($segments) - 1;
                    $insertion .= $indent.$missing.':'.($last ? ' '.$this->scalar($value) : '').$newline;
                    $indent .= $this->indentUnit($lines);
                }
                if ($end > 0 && ! str_ends_with($lines[$end - 1], "\n")) {
                    $lines[$end - 1] .= $newline;
                }
                array_splice($lines, $end, 0, [$insertion]);

                return implode('', $lines);
            }
            $line = array_key_first($candidates);
            $entry = $candidates[$line];
            if ($position === count($segments) - 1) {
                $tail = $entry['tail'];
                if (str_starts_with($tail, "'''") || str_starts_with($tail, '"""')) {
                    throw new RuntimeException("Cannot safely patch NEON {$path}: multiline scalar syntax is unsupported.");
                }
                $comment = $this->scalarComment($tail);
                $lines[$line] = $entry['prefix'].$this->scalar($value).$comment.$entry['newline'];

                return implode('', $lines);
            }
            if (trim($entry['tail']) !== '' && ! str_starts_with(ltrim($entry['tail']), '#')) {
                throw new RuntimeException("Cannot safely patch NEON {$path}: {$segment} must use a block mapping.");
            }
            $start = $line + 1;
            for ($end = $start; $end < count($lines); $end++) {
                if ($this->significant($lines[$end]) && strlen($this->indent($lines[$end])) <= strlen($indent)) {
                    break;
                }
            }
            $childIndent = null;
            for ($child = $start; $child < $end; $child++) {
                if ($this->significant($lines[$child])) {
                    $childIndent = $this->indent($lines[$child]);
                    break;
                }
            }
            $indent = $childIndent ?? $indent.$this->indentUnit($lines);
        }

        throw new RuntimeException("Cannot safely patch NEON {$path}.");
    }

    private function entry(string $line): ?array
    {
        $pattern = '~^([\t ]*)((?:[a-zA-Z_][a-zA-Z0-9_.-]*|\'(?:\'\'|[^\'\r\n])*\'|"(?:\\\\.|[^"\\\\\r\n])*"))[\t ]*[:=]([\t ]*)([^\r\n]*)(\r\n|\n|)$~D';
        if (! preg_match($pattern, $line, $match)) {
            return null;
        }
        try {
            $key = array_key_first(Neon::decode('{'.$match[2].': null}'));
        } catch (NeonException) {
            return null;
        }
        $prefix = substr($line, 0, strlen($line) - strlen($match[4]) - strlen($match[5]));
        if ($match[3] === '' && str_ends_with($prefix, ':')) {
            $prefix .= ' ';
        }

        return ['indent' => $match[1], 'key' => $key, 'prefix' => $prefix, 'tail' => $match[4], 'newline' => $match[5]];
    }

    private function scalarComment(string $tail): string
    {
        if (str_starts_with($tail, '#')) {
            return ' '.$tail;
        }
        $quoted = '~^(?:\'(?:\'\'|[^\'])*\'|"(?:\\\\.|[^"\\\\])*")([\t ]*#.*|[\t ]*)$~D';
        if (preg_match($quoted, $tail, $match)) {
            return str_starts_with($match[1], '#') ? ' '.$match[1] : $match[1];
        }
        if (preg_match('/([\t ]+#.*|[\t ]*)$/D', $tail, $match)) {
            return $match[1];
        }

        return '';
    }

    private function scalar(mixed $value): string
    {
        return Neon::encode($value);
    }

    private function significant(string $line): bool
    {
        return trim($line) !== '' && ! str_starts_with(ltrim($line), '#');
    }

    private function indent(string $line): string
    {
        preg_match('/^[\t ]*/', $line, $match);

        return $match[0];
    }

    private function rootIndent(array $lines): string
    {
        foreach ($lines as $line) {
            if ($this->significant($line)) {
                return $this->indent($line);
            }
        }

        return '';
    }

    private function indentUnit(array $lines): string
    {
        foreach ($lines as $line) {
            if ($this->significant($line) && ($indent = $this->indent($line)) !== '') {
                return str_contains($indent, "\t") ? "\t" : str_repeat(' ', strlen($indent));
            }
        }

        return '    ';
    }
}
