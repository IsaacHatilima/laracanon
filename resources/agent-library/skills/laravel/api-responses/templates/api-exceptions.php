<?php

declare(strict_types=1);

use App\Http\Responses\ApiResponse;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return static function (Exceptions $exceptions): void {
    $isApi = static fn (Request $request): bool => $request->is('api', 'api/*');

    $exceptions->shouldRenderJsonWhen(
        static fn (Request $request, Throwable $exception): bool => $isApi($request) || $request->expectsJson(),
    );

    $exceptions->respond(
        static function (Response $response, Throwable $exception, Request $request) use ($isApi): Response {
            if ($isApi($request) && $response instanceof JsonResponse && $response->getStatusCode() >= 400) {
                return ApiResponse::error($response);
            }

            return $response;
        },
    );
};
