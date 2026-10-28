---
name: laravel-frontend
description: "Use when adding or changing React or Inertia pages, page props, Wayfinder routes, forms, or shared UI in a Laravel application's resources/js."
---

# Frontend

Applies to: `resources/js/**`. Apply each tool's rules only if that tool is installed; don't add Inertia, Wayfinder, or shadcn to an application without them. For the app shell, overlays, and tables, see `frontend/app-shell`, `frontend/overlays`, and `frontend/data-display`.

## Structure

- Domain code lives in `resources/js/pages/{domain}/`: pages, components, hooks, and `types.ts`. Move code into the shared `resources/js/{components,hooks,lib,types}` only when a second domain uses it.
- Page files:
  - Named after the controller method (`index`, `show`, `create`, `edit`), with nested resources in subfolders.
  - A single-action controller's page takes the controller's name.
  - Keep Fortify's `auth/*` pages.
  - The `Inertia::render()` name equals the file path.
- Pages default-export the component, declare `type Props`, and set a `Head` title. Other components use named exports in kebab-case files. Hooks live in `use-*.ts` files.

## Props and types

- Write one type per output Resource, named without the `Resource` suffix (`UserResource` → `User`). Match its camelCase fields, nullability, and collection shape. Never type props from Eloquent models or input Data.
- Keep shared props separate from page props.

## Routing and forms

- Import URLs and form actions from the generated Wayfinder exports (`@/routes/...`, `@/actions/...`) after checking they exist.
  - `.form()` variants exist only when generated with `--with-form`.
  - When routes change, regenerate through the project's existing workflow.
- Use the form API of the installed Inertia version. Input names match the FormRequest fields. Show labels, field errors, and a pending state.
- Inertia forms follow the web redirect flow; only API clients read the `api-responses` envelope.
- Use the existing flash mechanism only after deliberate changes. Don't toast every write or replay a toast on navigation.

## UI

- Reuse the existing layout and breadcrumb mechanism; don't restructure `app.tsx` for one page.
- Reuse generated shadcn primitives and add new ones through its CLI. Domain-specific composition stays in the domain folder.
- Add `data-test` attributes only to elements that tests use.

## Verify

Run the existing type, lint, and build checks plus focused browser tests. A passing build doesn't prove browser behaviour.
