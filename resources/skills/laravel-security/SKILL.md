---
name: laravel-security
description: "Use when adding or reviewing Laravel authorization, policies, authentication, API tokens, CORS, CSRF exceptions, file uploads, raw queries, HTML output, rate limits, or production configuration, or before deploying a Laravel application."
---

# Security

Applies to: `app/Policies/**`, `app/Http/**`, `app/Providers/**`, `bootstrap/app.php`, `config/**`, `routes/**`.

Middleware, CSRF, and proxies are configured in `bootstrap/app.php`. Gates and rate limiters are registered in `AppServiceProvider::boot()`.

## Authorization

- Every model a user can act on has a policy in `app/Policies/` with one method per ability (`viewAny`, `view`, `create`, `update`, `delete`, `restore`, `forceDelete`). Rely on policy discovery; when names don't follow convention, add `#[UsePolicy(UserPolicy::class)]` to the model.
- Policy methods return `bool` from the user, the model, and its loaded attributes. A check that needs a query, such as team membership, calls an injected action (see `architecture`).
- Use a guest-nullable `?User $user` parameter only for abilities that guests may hold.
- Use a policy's `before()` or `Gate::before()` for super-admin overrides. It returns `true` to grant and `null` to fall through to the normal check. Returning `false` denies everything, including the abilities the method never meant to cover.
- Authorize in the FormRequest's `authorize()`, or with `#[Authorize]` on the controller method when there is no FormRequest (see `requests`, `controllers`). Route-model binding finds a record; it never proves the caller may use it. Nested routes use `scopeBindings()` so a child can't be resolved through another tenant's parent.

## Privileged fields and mass assignment

- Keep privileged columns out of `#[Fillable]`: role, admin and verification flags, owner, tenant, and team IDs. Never unguard the model.
- Never take those values from request input. The action sets them from the authenticated actor or the route-bound parent, either through relationship creation (`$team->projects()->create([...])`) or by explicit attribute assignment.
- Hide secrets from serialization with `#[Hidden]`: password, remember token, two-factor secrets and recovery codes. Resources remain the output contract; `#[Hidden]` is the backstop.
- Use `encrypted` casts for sensitive values stored at rest. Rotate `APP_KEY` with `APP_PREVIOUS_KEYS` so existing encrypted values stay readable.

## Queries and output

- Pass user input to queries only as bindings: `whereRaw('email = ?', [$email])`, never interpolated into `whereRaw`, `selectRaw`, `orderByRaw`, `DB::statement`, or `DB::select`.
- Column names can't be bound. The FormRequest allowlists user-chosen sort and filter values, and the action maps them to real columns (see `requests`).
- In Blade, `{{ }}` escapes and `{!! !!}` does not. Use `{!! !!}` only for HTML the application generated or sanitized with an allowlist sanitizer such as HTMLPurifier. Use `@js()` to put data into a script context.
- In React, JSX escapes by default. `dangerouslySetInnerHTML` takes only server-sanitized HTML.

## Authentication and sessions

- Starter kits and Fortify already regenerate the session on login, and invalidate it and regenerate the CSRF token on logout. Custom authentication controllers do the same.
- Configure password strength once with `Password::defaults()` in `AppServiceProvider` (for example `Password::min(12)->uncompromised()`), then use `Password::defaults()` in every password rule.
- Sanctum tokens get an `expiration` and the narrowest abilities, such as `posts:read`. Routes enforce them with the `abilities`/`ability` middleware aliases. Use Passport only when third-party OAuth clients are required.
- CORS lists explicit origins. `supports_credentials` is `true` only for a first-party SPA, and is never combined with `'*'` origins.

## CSRF and webhooks

- Exclude only stateless endpoints that verify their own signature, such as payment-provider webhooks: `$middleware->validateCsrfTokens(except: ['stripe/*'])`. Never exclude `api/*` wholesale; stateful Sanctum SPA routes rely on CSRF.
- A webhook controller verifies the provider signature before invoking any action.

## Rate limiting

- Define named limiters in `AppServiceProvider` with `RateLimiter::for()`. Key them by user ID, falling back to IP. Apply them with `throttle:<name>`.
- Login, registration, password reset, two-factor, and upload routes always have a stricter limiter than general API routes.
- Let 429 responses flow through the central exception handling (see `api-responses`) instead of building JSON inside the limiter.

## File uploads

- Validate type by content and extension, and size: `File::types(['pdf', 'docx'])->max('10mb')`, or `mimes:` with `extensions:`. Add `dimensions:` for images.
- Store private files on a non-public disk under generated names; never trust the client filename for the path.
- Serve private files only after authorizing the download. Then stream with `Storage::download()` or redirect to a short-lived `Storage::temporaryUrl()` on disks that support it.

## Production configuration

- `APP_DEBUG=false`, `APP_KEY` set, `SESSION_SECURE_COOKIE=true`, and HTTPS enforced at the proxy or with `URL::forceScheme('https')`.
- Trust only the load balancer's address ranges: `$middleware->trustProxies(at: [...])`. Never trust `'*'` on a host reachable directly.
- Call `env()` only inside `config/*.php`. After `config:cache`, `env()` elsewhere returns null.
- Set security headers in middleware: `X-Content-Type-Options: nosniff`, `Referrer-Policy`, and a Content-Security-Policy with `frame-ancestors`. Use `Vite::useCspNonce()` when the CSP blocks inline scripts. Omit the deprecated `X-XSS-Protection`.
- Commit `composer.lock` and run `composer audit` in CI.
- Log security events (failed logins, role and permission changes, token creation) to a dedicated channel with the actor ID and IP. Never log passwords, tokens, or full request bodies.

Adapted from [laravel-security](https://github.com/affaan-m/everything-claude-code/blob/main/skills/laravel-security/SKILL.md) in everything-claude-code.
