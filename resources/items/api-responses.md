---
name: api-responses
description: A consistent project API envelope with Resource output, validation errors, pagination, and real HTTP statuses.
paths:
  - 'app/Http/Responses/**'
  - 'app/Http/Controllers/Api/**'
  - 'bootstrap/app.php'
  - 'bootstrap/api-exceptions.php'
---

## Rules

- This project's API JSON bodies always contain `data`, `message`, `errors`, `meta`, and `links`. `data` is a Resource representation, a list of representations, or null. `message` is a translated string. `errors` is null or a field-to-list-of-messages map. `meta` and `links` are null or the Resource's pagination metadata and links. This is an authored project convention, not a universal Laravel standard or JSON:API.
- Keep the actual HTTP status: typically 200, 201, or 202 for successful JSON responses and the real 401, 403, 404, 422, 429, or 500 for failures. A 204 response has no JSON body; do not manufacture an envelope for it. Do not turn errors into HTTP 200.
- Read actions prepare output Resources. Run the installed Resource's response pipeline first with an explicit `data` wrapper, then apply the envelope once. Preserve pagination metadata and links. Do not return models or input Data, map model attributes in the response helper, infer wrappers, or add output queries.
- Centralize API exception formatting after Laravel renders the exception. Preserve validation messages, protocol headers, and unrelated web responses. Client-facing 4xx messages must be safe and translated at their source. Server errors return a generic translated message without exception names, file paths, SQL, or traces. Keep reporting and logging in the existing exception handler.
- The helper and exception configuration belong to the application's runtime code. Installing this item supplies rules and an authored workflow; it does not create PHP files, change routes, or overwrite exception configuration automatically.

Use the `laracanon-api-responses` skill for implementation and the complete helper.

## Skill

This is a **Laracanon-authored implementation workflow**. It adds no dependency and uses the application's installed Resource implementation.

1. Inspect the callers, Resource adapter, API route predicate, existing envelope, authentication, translations, pagination, and exception configuration. Adopt this convention deliberately at the API boundary; preserve unrelated web/Inertia behavior, exception reporting, authentication, and existing bootstrap configuration. If another established API contract must remain supported, keep its boundary explicit.
2. Add the application-owned helper below at `app/Http/Responses/ApiResponse.php`. Keep it in runtime application code, with no dependency on the development-only Laracanon package. A success consumes a `JsonResponse` already wrapped under `data`, or null for a success with no representation. It takes its HTTP status from the explicit `$status` argument. An error retains its existing failure status. No method accepts a model or performs a query.
3. Have a read action build the Resource. Invoke the Resource's supported response method before the helper so its transformations, allowed field selection, wrapping, pagination, and headers survive. Require the explicit `data` wrapper; an unwrapped payload is rejected rather than guessed. Do not wrap a list or paginated response again under another `data` key. Preserve empty objects as objects and empty lists as lists.
4. Translate success messages where they are chosen. Keep safe 4xx messages and validation messages translated at their source. The error helper falls back to a translated HTTP status phrase when a client error has no usable message, and uses a generic translated server-error message for every 5xx response. It never forwards arbitrary error-body fields.
5. Merge the exception integration below into the application's existing bootstrap configuration. Reuse its actual API predicate. Laravel calls the response callback as `(response, exception, request)` after native exception rendering, including authentication and validation errors. Apply the envelope only to API exception `JsonResponse` failures. Compose existing `shouldRenderJsonWhen`/`respond` behavior inside the existing callbacks; registering another callback replaces that hook. Preserve web redirects and HTML responses. Do not move authentication into the response helper or catch every exception in controllers.
6. Return bodyless responses directly: `return response()->noContent();`. The JSON helper rejects 204 and 205. Preserve the application's existing asynchronous-operation and error contracts. Validate the real 200/201/202 and 401/403/404/422/429/500 paths, pagination, private-field exclusion, null data, wrapper rejection, translation, and protocol headers. Changing a body removes stale body lengths and ETags; authentication and retry headers remain intact.

### Application helper

`app/Http/Responses/ApiResponse.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final class ApiResponse
{
    public static function success(
        ?JsonResponse $resourceResponse,
        string $message,
        int $status = Response::HTTP_OK,
    ): JsonResponse {
        if ($status < 200 || $status >= 300 || in_array($status, [204, 205], true)) {
            throw new InvalidArgumentException('API JSON successes require a body-bearing 2xx status.');
        }

        if ($resourceResponse !== null && ($resourceResponse->getStatusCode() < 200
            || $resourceResponse->getStatusCode() >= 300
            || in_array($resourceResponse->getStatusCode(), [204, 205], true))) {
            throw new InvalidArgumentException('The Resource response must also have a body-bearing 2xx status.');
        }

        $body = $resourceResponse?->getData();

        if ($resourceResponse !== null && (! is_object($body) || ! property_exists($body, 'data'))) {
            throw new InvalidArgumentException('Wrap the Resource response under an explicit data key first.');
        }

        $data = $body->data ?? null;

        if ($data !== null && ! is_object($data) && ! is_array($data)) {
            throw new InvalidArgumentException('Resource data must be an object, a list, or null.');
        }

        $response = $resourceResponse === null ? new JsonResponse : clone $resourceResponse;
        $response->setStatusCode($status);
        $response->setData([
            'data' => $data,
            'message' => $message,
            'errors' => null,
            'meta' => $body->meta ?? null,
            'links' => $body->links ?? null,
        ]);

        return self::withoutStaleBodyHeaders($response);
    }

    public static function error(JsonResponse $response): JsonResponse
    {
        $status = $response->getStatusCode();

        if ($status < 400 || $status >= 600) {
            throw new InvalidArgumentException('API errors require a 4xx or 5xx status.');
        }

        $body = $response->getData();
        $message = is_object($body) ? ($body->message ?? null) : null;

        if ($status >= 500) {
            $message = __('Server Error');
        } elseif (! is_string($message) || trim($message) === '') {
            $message = __(Response::$statusTexts[$status] ?? 'Request failed.');
        }

        $response = clone $response;
        $response->setData([
            'data' => null,
            'message' => $message,
            'errors' => $status < 500 ? self::validationErrors($body->errors ?? null) : null,
            'meta' => null,
            'links' => null,
        ]);

        return self::withoutStaleBodyHeaders($response);
    }

    private static function validationErrors(mixed $errors): ?object
    {
        if ((! is_object($errors) && ! is_array($errors)) || (array) $errors === []) {
            return null;
        }

        foreach ((array) $errors as $messages) {
            if (! is_array($messages) || ! array_is_list($messages)) {
                return null;
            }

            foreach ($messages as $message) {
                if (! is_string($message)) {
                    return null;
                }
            }
        }

        return (object) $errors;
    }

    private static function withoutStaleBodyHeaders(JsonResponse $response): JsonResponse
    {
        $response->headers->remove('Content-Length');
        $response->headers->remove('ETag');

        return $response;
    }
}
```

The helper retains the Resource response's encoding options, cookies, and headers, including `Location`, `WWW-Authenticate`, and `Retry-After`. It replaces only the JSON body and the caller-selected success status. It removes the old `Content-Length` and `ETag` because those described the previous body. Successful pagination keeps the Resource's top-level `meta` and `links`; failure metadata is null. A repeated envelope conversion does not introduce `data.data`.

### Success at the API boundary

The shared `CreateUser` and `GetUser` flow in the controller skill can return:

```php
return ApiResponse::success(
    resourceResponse: $getUser->handle($user)->wrap('data')->toResponse($request),
    message: __('User created successfully.'),
    status: Response::HTTP_CREATED,
);
```

Import `App\Http\Responses\ApiResponse` and `Symfony\Component\HttpFoundation\Response` in that API controller. The web controller continues to redirect. For a success without a representation, call `ApiResponse::success(null, __('Request accepted.'), Response::HTTP_ACCEPTED)`. For a paginated result, configure the installed Resource adapter's native response with the same explicit `data` wrapper before invoking `success()`; its top-level pagination `meta` and `links` remain siblings of `data`. Do not serialize a paginator yourself or discard its links.

### Central exception integration

Create `bootstrap/api-exceptions.php` with this standalone configuration callback. The example API predicate matches `/api` and `/api/*`; replace that predicate with the application's actual route/domain convention. The non-API JSON negotiation below matches Laravel's default behavior. Preserve any existing custom negotiation in that branch.

```php
<?php

use App\Http\Responses\ApiResponse;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return static function (Exceptions $exceptions): void {
    $isApi = static fn (Request $request): bool => $request->is('api', 'api/*');

    $exceptions->shouldRenderJsonWhen(
        static fn (Request $request, \Throwable $exception): bool => $isApi($request) || $request->expectsJson(),
    );

    $exceptions->respond(
        static function (Response $response, \Throwable $exception, Request $request) use ($isApi): Response {
            if ($isApi($request) && $response instanceof JsonResponse && $response->getStatusCode() >= 400) {
                return ApiResponse::error($response);
            }

            return $response;
        },
    );
};
```

In an application with no existing exception callbacks, pass `require __DIR__.'/api-exceptions.php'` to its existing `Application::configure(...)->withExceptions(...)` chain in `bootstrap/app.php`. When callbacks already exist, merge the callback body into them and retain their behavior; do not add a second `withExceptions()` hook or replace the bootstrap file. These methods and callback ordering are documented in Laravel's [exception rendering guidance](https://laravel.com/docs/13.x/errors#rendering-exceptions-as-json) and verified in the official Handler source for [Laravel 11](https://github.com/laravel/framework/blob/11.x/src/Illuminate/Foundation/Exceptions/Handler.php), [Laravel 12](https://github.com/laravel/framework/blob/12.x/src/Illuminate/Foundation/Exceptions/Handler.php), and [Laravel 13](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Foundation/Exceptions/Handler.php).

The callback runs after native authentication, authorization, validation, and HTTP-exception rendering. `shouldRenderJsonWhen` makes the API predicate receive JSON even without an Accept header; `respond` reshapes only API JSON failures. An intentional custom exception renderer must still return an API `JsonResponse` if it participates in this contract. No controller-level catch block is required, and non-API responses keep their existing shape.

Inspect an existing guest-redirect callback as well. Authentication middleware can calculate that redirect before Laravel renders the authentication exception. In an API-only application without a named login route, a web-style `route('login')` callback can throw first. Where needed, return null for the same API predicate and retain the existing web destination. For example, merge this into the existing middleware callback when the web destination really is `login`:

```php
$middleware->redirectGuestsTo(
    static fn (Request $request): ?string => $request->is('api', 'api/*') ? null : route('login'),
);
```

This does not install an authentication mode, create a login route, or change a working guest-redirect policy. Reuse the application's real API predicate and existing web destination.
