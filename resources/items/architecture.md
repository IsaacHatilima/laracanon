---
name: architecture
description: 'Shared Laravel boundaries and an authored workflow for placing application behavior.'
paths:
  - 'app/**'
---

## Rules

- HTTP input follows FormRequest authorization and validation → explicit Data `fromRequest()` → action. Actions return Resources for reads or models for writes; controllers choose the API envelope, Inertia page, redirect, or other web response. Web, API, and internal callers reuse transport-independent actions.
- Every application query, write, transaction, relationship load, and database-dependent business decision belongs in `app/Actions/**`. Other layers call injected actions. Data and Resources never query or lazy-load; output Resources expose explicit `fromModel()` factories.
- Only framework lookups are exempt: route model binding, `exists`/`unique` validation rules, `$request->user()`, Gate, policy and `can()` checks, and installed Spatie permission helpers `hasRole()`/`hasPermissionTo()`. Custom lookup logic inside a policy or framework adapter still belongs in an action.
- FormRequests own authorization and validation; Data owns input normalization; actions own persistence and output preparation; controllers and response helpers own transport. Request payloads use Data contracts. Small internal arrays may remain documented typed application values.
- Translate user-facing PHP strings with `__()`. Follow these boundaries for new work; migrate unrelated legacy violations as separate changes.

Use the `laracanon-architecture` skill to place behavior, then the relevant sibling skill for implementation details.

## Skill

Use this **Laracanon-authored workflow** to assess a feature or refactor against the shared architecture. It adds no packages and coordinates the focused item workflows rather than replacing them.

1. Trace the existing entry points, authorization, input, database work, output, and response. Identify web/API callers and jobs or commands that should share the same operation. Read the application's current contracts before introducing new classes.
2. Assign each responsibility to its boundary. HTTP input gets a FormRequest and explicit Data factory; database work and business decisions get actions; read representations get Resources; HTTP behavior stays with controllers and response helpers. Distinguish the listed framework lookups from custom queries that need an injected action.
3. Choose the focused workflows: `laracanon-requests` for validation and authorization, `laracanon-data` for input/output factories, `laracanon-actions` for persistence and reads, and `laracanon-controllers` for HTTP wiring. For APIs, also use `laracanon-api-responses` for the shared envelope; use the frontend, model, migration, enum, and test item skills where those concerns change. Consult only the items relevant to the application's stack.
4. Check the interfaces across those boundaries before implementation: request fields versus Data properties, action inputs and return types, Resource fields, and the caller's response contract. Keep request input typed, allow documented small internal arrays, and share actions across transports. Select related rules or skills explicitly rather than assuming this overview installs dependencies or sibling items.
5. Keep the change focused. Follow the agreed boundaries in new code, record unrelated legacy violations separately, and use `laracanon-plans` when the work needs sequencing or contract review. Verify the changed operation through its actual callers and the application's existing checks; report any remaining evidence gaps.
