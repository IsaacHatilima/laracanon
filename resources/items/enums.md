---
name: enums
description: 'Implement stable string-backed enums, translated labels, and select options only where needed.'
paths:
  - 'app/Enums/**'
---

## Rules

- Enums in `app/Enums` are string-backed, with TitleCase case names and stable explicit values. Persist the backing value, never its translated label. Changing a stored value is a data/contract change, not a cosmetic rename.
- Each enum provides `label(): string` using translated strings. Add `options(): array` only when a caller needs select options, with the PHPDoc contract `list<array{value: string, label: string}>`. Build labels in the caller's current locale.
- Store enum values in string columns and cast them on the model. Validate external values at the request boundary, then carry the enum in typed input. Controllers pass needed options into established page props or API representations without rebuilding labels or executing queries in the enum.

Use the `laracanon-enums` skill for implementation and the User example.

## Skill

Use this **Laracanon-authored workflow** when adding or changing an enum. It adds no package dependencies and does not invent status fields or options for every domain.

1. Inspect the existing stored values, schema, request validation, Data contracts, model casts, Resources, and frontend types. Decide whether the domain really has a closed set of values. Reuse an existing enum when it already describes that contract. Preserve published/stored values; renaming a case or label must not accidentally change its backing string.
2. Define the enum under `app/Enums`, with a string backing type and TitleCase case names. Give each case an explicit unique value. Implement `label(): string` with an exhaustive `match` and translated strings, keeping translation out of persistence. PHP's [backed enum documentation](https://www.php.net/manual/en/language.enumerations.backed.php) defines values and `from()`/`tryFrom()`; [cases()](https://www.php.net/manual/en/language.enumerations.listing.php) preserves declaration order.
3. When request input accepts the enum, use the installed Laravel version's `Rule::enum()` validation in its FormRequest. After authorization and validation, the explicit Data `fromRequest()` factory constructs the typed enum from the known validated field; it does not silently substitute a default for an unknown value. Keep the action's column mapping explicit, using the backing value where the storage contract requires a string. Cast that column to the enum in the existing model's `casts()`. Use a string column sized for its backing values; add a stable default only when the domain needs one. Do not create a database `enum` type. See Laravel's [enum validation](https://laravel.com/docs/13.x/validation#rule-enum) and [enum casting](https://laravel.com/docs/13.x/eloquent-mutators#enum-casting).
4. Add `options()` only for a real select/options caller. Return a list of `value`/`label` pairs by mapping `self::cases()`. Pass these options directly through the existing controller prop or established API contract. Do not cache translated labels across locales or store them in the database. If the contract needs a subset or a different order, make that selection explicit rather than changing backing values.
5. When changing cases, inspect existing rows and consumers first. A backing-value change requires the relevant forward migration/backfill and compatible input/output contract within the requested scope. A removed value must not leave rows that the model can no longer cast. Preserve already deployed migrations and coordinate any frontend type changes.
6. Run focused checks for unique backing values, labels in supported locales, valid/invalid request input, the model's storage/cast round trip, and any actual options caller. Verify the options are a list with the documented shape and stable values. Test affected historical values when cases change, then report what passed and what remains unverified.

### Example: User status options

Use this example only when the application needs a User status and a status select. It assumes a string `users.status` column and a corresponding model cast. It extends the same User domain as the Data/controller workflow; it does not add a status input to that creation form automatically. An enum without a select caller omits `options()`.

`app/Enums/UserStatus.php`:

```php
<?php

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
