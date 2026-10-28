---
name: laravel-enums
description: "Use when adding or changing a PHP enum used by a Laravel model, or its stored values, labels, validation, or select options."
---

# Enums

Applies to: `app/Enums/**`.

## Rules

- Use an enum only for a genuinely closed set of values. Reuse an existing enum that fits.
- Enums are string-backed, with TitleCase case names and stable explicit values. Changing a backing value is a data migration, not a rename (see `migrations`).
- `label(): string` uses an exhaustive `match` with translated strings. Store the value, never the label.
- Add `options()` only when a caller renders a select.
  - It returns `list<array{value: string, label: string}>` built from `self::cases()` in the current locale.
  - Never cache labels across locales.
- Validate input with `Rule::enum()`. The Data factory builds the enum from the validated value without silently falling back to a default. Cast the column to the enum on the model (see `models`).
- Before removing or changing a case, check existing rows and consumers so no stored value becomes uncastable.

## Example

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Suspended => __('Suspended'),
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
```

## Verify

Tests cover:
- unique backing values;
- labels in each supported locale;
- valid and invalid input;
- the cast round trip;
- the shape of `options()`.
