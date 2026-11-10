---
name: laravel-api-responses
description: "Use when adding or changing a Laravel JSON API endpoint, its response envelope or pagination output, or how API errors such as 401, 403, 404, 422, 429, and 500 are rendered."
---

# API responses

Applies to: `app/Http/Responses/**`, `app/Http/Controllers/Api/**`, `bootstrap/app.php`. This is a project convention, not JSON:API.

## Envelope

Every API JSON body has exactly these five keys:

| Key | Success | Failure |
|---|---|---|
| `data` | Resource, list of Resources, or `null` | `null` |
| `message` | Translated string | Safe translated message; generic for 5xx |
| `errors` | `null` | For 422, field → list of messages; otherwise `null` |
| `meta`, `links` | Pagination metadata and links, or `null` | `null` |

## Rules

- Keep the real HTTP status.
  - Successes use 200, 201, or 202.
  - Failures use the actual 401, 403, 404, 422, 429, or 500.
  - Never return an error as 200.
  - A 204 or 205 has no body: `return response()->noContent();`.
- Actions return the Resource (see `actions`). Run its own response pipeline with an explicit `data` wrapper, then apply the envelope once. Field selection, pagination `meta`/`links`, and headers then survive.
- Never serialize a paginator by hand or wrap twice (`data.data`).
- Format API errors centrally after Laravel renders the exception; controllers have no catch blocks.
- Server errors never expose exception names, file paths, SQL, or traces. Reporting and logging stay in the existing handler.
- The helper replaces only the body and the success status. It keeps headers such as `Location`, `WWW-Authenticate`, and `Retry-After`, and drops the stale `Content-Length` and `ETag`.

## Setup

1. Copy [templates/ApiResponse.php](templates/ApiResponse.php) to `app/Http/Responses/ApiResponse.php`. It accepts a `JsonResponse` already wrapped under `data`, or `null`, and rejects anything else.
2. Copy [templates/api-exceptions.php](templates/api-exceptions.php) to `bootstrap/api-exceptions.php`.
   - Replace its `/api` predicate with the application's real one.
   - Pass it to `->withExceptions(require __DIR__.'/api-exceptions.php')` in `bootstrap/app.php`.
   - If exception callbacks already exist, merge the body into them instead. A second `respond()` callback replaces the first.
3. If guest redirects use `route('login')` and the API has no login route, return `null` for API requests so unauthenticated API calls get a 401 instead of an exception:

```php
$middleware->redirectGuestsTo(
    static fn (Request $request): ?string => $request->is('api', 'api/*') ? null : route('login'),
);
```

## Usage

```php
return ApiResponse::success(
    resourceResponse: $createUser->handle($data)->wrap('data')->toResponse($request),
    message: __('User created successfully.'),
    status: Response::HTTP_CREATED,
);
```

For a success with no representation, call `ApiResponse::success(null, __('Request accepted.'), Response::HTTP_ACCEPTED)`.

## Verify

Tests cover:
- each success and failure status;
- exactly five keys;
- pagination without `data.data`;
- private fields excluded;
- errors with and without an `Accept: application/json` header;
- authentication and retry headers preserved;
- empty 204 bodies.
