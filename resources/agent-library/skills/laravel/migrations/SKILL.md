---
name: laravel-migrations
description: "Use when adding or changing a Laravel migration, table, column, index, foreign key, or schema backfill."
---

# Migrations

Applies to: `database/migrations/**`.

## Rules

- Never edit a migration that has been applied or shared; add a new forward migration. Every migration returns an anonymous class with `up()` and `down()`.
- Table names are plural snake_case. Pivot tables join the two singular names alphabetically (`role_user`).
- Keys:
  - Entity tables use `$table->uuid('id')->primary()`, with `HasUuids` on the model. Pivot and lookup tables use `$table->id()`.
  - A foreign key matches the referenced key's type (`foreignUuid()` for UUID parents), whatever the child's own key type.
  - UUID-only polymorphic targets use `uuidMorphs()`.
- Every foreign key states a deletion policy chosen for the domain:
  - `restrictOnDelete()`;
  - `cascadeOnDelete()` only for truly dependent data;
  - `nullOnDelete()`, on a column declared `nullable()` before `constrained()`.

  Soft deletes never trigger database cascades.
- Enum values go in `string()` columns sized for the backing values. A default is a literal such as `'active'`, not an enum case. No database `enum` columns.
- Store money as integer minor units (`unsignedBigInteger('total_cents')`), never as a float.
- Index columns that queries filter or sort on in large tables. MySQL indexes foreign keys automatically; PostgreSQL needs explicit indexes.
- `down()` reverses `up()` in dependency order. Say when a rollback loses data; recreating a column doesn't restore its contents.

## Workflow

1. Read the existing migrations and models, and find out which migrations are already deployed. Check the database engine.
2. Before narrowing a type, adding a required column, or changing values, inspect existing rows and plan a forward backfill. Don't assume schema changes are transactional on the target engine.
3. Run the migration, then roll back and run it again, only on a disposable database; never on a shared or deployed one.
4. Check keys, types, defaults, indexes, and delete behaviour, and report the engine you tested on.
