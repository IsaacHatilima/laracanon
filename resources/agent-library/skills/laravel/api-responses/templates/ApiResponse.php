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
