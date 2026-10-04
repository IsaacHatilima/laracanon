<?php

declare(strict_types=1);

namespace Isaachatilima\Laracanon\Tests\Integration\Examples;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ApiResponseExampleTest extends TestCase
{
    private Closure $configureExceptions;

    protected function setUp(): void
    {
        parent::setUp();
        $source = file_get_contents(dirname(__DIR__, 3).'/resources/items/api-responses.md');
        preg_match_all('/```php\n(.*?)```/s', $source, $matches);
        $helper = null;
        $configuration = null;

        foreach ($matches[1] as $code) {
            if (str_contains($code, 'final class ApiResponse')) {
                $helper = $code;
            }

            if (str_contains($code, 'return static function (Exceptions $exceptions)')) {
                $configuration = $code;
            }
        }

        self::assertNotNull($helper, 'Test the exact authored application helper.');
        self::assertNotNull($configuration, 'Test the exact authored exception callback.');
        if (! class_exists(ApiResponse::class, false)) {
            $this->loadSource($helper);
        }

        $this->configureExceptions = $this->loadSource($configuration);
    }

    #[DataProvider('successStatuses')]
    public function test_success_has_one_envelope_and_uses_the_selected_http_status(int $status): void
    {
        $resource = new JsonResponse(['data' => ['id' => '42', 'name' => 'Ada']], 201);
        $response = ApiResponse::success($resource, 'Saved.', $status);

        self::assertSame($status, $response->getStatusCode());
        self::assertSame([
            'data' => ['id' => '42', 'name' => 'Ada'],
            'message' => 'Saved.',
            'errors' => null,
            'meta' => null,
            'links' => null,
        ], $response->getData(true));
        self::assertSame(['data' => ['id' => '42', 'name' => 'Ada']], $resource->getData(true));
        self::assertSame($response->getData(true), ApiResponse::success($response, 'Saved.', $status)->getData(true));
    }

    public static function successStatuses(): array
    {
        return [[200], [201], [202]];
    }

    public function test_null_representation_and_empty_object_and_list_keep_their_distinct_json_shapes(): void
    {
        self::assertSame([
            'data' => null, 'message' => 'Accepted.', 'errors' => null, 'meta' => null, 'links' => null,
        ], ApiResponse::success(null, 'Accepted.', 202)->getData(true));
        self::assertInstanceOf(stdClass::class, ApiResponse::success(new JsonResponse(['data' => new stdClass]), 'Empty.')->getData()->data);
        self::assertSame([], ApiResponse::success(new JsonResponse(['data' => []]), 'Empty.')->getData()->data);
        self::assertNull(ApiResponse::success(new JsonResponse(['data' => null]), 'Empty.')->getData()->data);
    }

    public function test_native_resource_pipeline_removes_private_fields_before_the_envelope(): void
    {
        $request = Request::create('/api/users', 'POST');
        $resource = new SafeApiUserResource(['id' => 42, 'name' => 'Ada', 'password' => 'secret']);
        $response = ApiResponse::success($resource->toResponse($request), 'Created.', 201);

        self::assertSame(['id' => '42', 'name' => 'Ada'], $response->getData(true)['data']);
        self::assertStringNotContainsString('password', $response->getContent());
        self::assertSame(201, $response->getStatusCode());
    }

    public function test_native_resource_pagination_metadata_and_links_survive_without_double_wrapping(): void
    {
        $paginator = new LengthAwarePaginator(
            [['id' => 42, 'name' => 'Ada', 'password' => 'secret']],
            total: 3,
            perPage: 1,
            currentPage: 2,
            options: ['path' => 'http://localhost/api/users'],
        );
        $resourceResponse = SafeApiUserResource::collection($paginator)->toResponse(Request::create('/api/users', 'GET'));
        $original = $resourceResponse->getData(true);
        $response = ApiResponse::success($resourceResponse, 'Users loaded.');
        $body = $response->getData(true);

        self::assertSame([['id' => '42', 'name' => 'Ada']], $body['data']);
        self::assertSame($original['meta'], $body['meta']);
        self::assertSame($original['links'], $body['links']);
        self::assertSame(2, $body['meta']['current_page']);
        self::assertSame(3, $body['meta']['total']);
        self::assertNotEmpty($body['links']['next']);
        self::assertSame(['data', 'message', 'errors', 'meta', 'links'], array_keys($body));
    }

    public function test_success_keeps_headers_cookies_and_encoding_options_and_drops_stale_body_headers(): void
    {
        $resource = new JsonResponse(['data' => ['name' => 'Áda', 'url' => 'https://example.test/users/1']], 201, [
            'Location' => '/api/users/1', 'X-Request-ID' => 'request-1', 'Content-Length' => '12', 'ETag' => 'old-body',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $cookie = Cookie::create('resource-token', 'opaque');
        $resource->headers->setCookie($cookie);
        $response = ApiResponse::success($resource, 'Created.', 201);

        self::assertSame('/api/users/1', $response->headers->get('Location'));
        self::assertSame('request-1', $response->headers->get('X-Request-ID'));
        self::assertSame($resource->getEncodingOptions(), $response->getEncodingOptions());
        self::assertSame([$cookie], $response->headers->getCookies());
        self::assertStringContainsString('https://example.test', $response->getContent());
        self::assertStringContainsString('Áda', $response->getContent());
        self::assertFalse($response->headers->has('Content-Length'));
        self::assertFalse($response->headers->has('ETag'));
        self::assertTrue($resource->headers->has('Content-Length'));
        self::assertTrue($resource->headers->has('ETag'));
    }

    #[DataProvider('invalidSuccessStatuses')]
    public function test_success_rejects_bodyless_and_non_success_statuses(int $status): void
    {
        $this->expectException(InvalidArgumentException::class);
        ApiResponse::success(null, 'Cannot envelope.', $status);
    }

    public static function invalidSuccessStatuses(): array
    {
        return [[199], [204], [205], [302], [400], [422], [500]];
    }

    #[DataProvider('invalidResourceStatuses')]
    public function test_a_failure_or_bodyless_resource_cannot_be_converted_into_success(int $status): void
    {
        $this->expectException(InvalidArgumentException::class);
        ApiResponse::success(new JsonResponse(['data' => null], $status), 'Cannot envelope.');
    }

    public static function invalidResourceStatuses(): array
    {
        return [[204], [205], [302], [401], [500]];
    }

    #[DataProvider('invalidResourceBodies')]
    public function test_unwrapped_and_scalar_resource_data_are_rejected(mixed $body): void
    {
        $this->expectException(InvalidArgumentException::class);
        ApiResponse::success(new JsonResponse($body), 'Cannot infer.');
    }

    public static function invalidResourceBodies(): array
    {
        return [[['id' => '42']], [[['id' => '42']]], [['data' => 'raw']], [['data' => false]]];
    }

    public function test_validation_errors_preserve_field_message_lists_and_remove_unrelated_debug_fields(): void
    {
        $errors = ['email' => ['The email is required.', 'The email is invalid.'], 'users.0.name' => ['The name is required.']];
        $response = ApiResponse::error(new JsonResponse([
            'message' => 'The given data was invalid.', 'errors' => $errors, 'exception' => 'InternalClass', 'trace' => [['secret' => 'hidden']],
        ], 422));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([
            'data' => null, 'message' => 'The given data was invalid.', 'errors' => $errors, 'meta' => null, 'links' => null,
        ], $response->getData(true));
    }

    public function test_server_errors_use_a_translated_generic_message_and_strip_all_details(): void
    {
        $this->app['translator']->addLines(['*.Server Error' => 'Erreur serveur'], 'fr');
        $this->app->setLocale('fr');
        $source = new JsonResponse([
            'message' => 'SQLSTATE secret', 'exception' => 'InternalClass', 'file' => '/private/file.php',
            'trace' => [['password' => 'secret']], 'errors' => ['sql' => ['secret']], 'data' => ['secret'],
        ], 503, ['Retry-After' => '60', 'Content-Length' => '999', 'ETag' => 'old-body']);
        $response = ApiResponse::error($source);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(['data' => null, 'message' => 'Erreur serveur', 'errors' => null, 'meta' => null, 'links' => null], $response->getData(true));
        self::assertSame('60', $response->headers->get('Retry-After'));
        self::assertFalse($response->headers->has('Content-Length'));
        self::assertFalse($response->headers->has('ETag'));
        self::assertStringNotContainsString('secret', $response->getContent());
        self::assertSame('SQLSTATE secret', $source->getData()->message);
    }

    public function test_error_preserves_authentication_and_retry_protocol_headers(): void
    {
        $response = ApiResponse::error(new JsonResponse(['message' => 'Unauthenticated.'], 401, [
            'WWW-Authenticate' => 'Bearer realm="API"', 'Retry-After' => '30', 'X-Request-ID' => 'error-1',
        ]));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer realm="API"', $response->headers->get('WWW-Authenticate'));
        self::assertSame('30', $response->headers->get('Retry-After'));
        self::assertSame('error-1', $response->headers->get('X-Request-ID'));
        self::assertNull($response->getData()->errors);
    }

    public function test_client_errors_with_missing_message_use_a_translated_status_phrase(): void
    {
        $this->app['translator']->addLines(['*.Not Found' => 'Introuvable'], 'fr');
        $this->app->setLocale('fr');
        $response = ApiResponse::error(new JsonResponse(['message' => '', 'errors' => ['email' => 'not a list']], 404));

        self::assertSame('Introuvable', $response->getData()->message);
        self::assertNull($response->getData()->errors);
        self::assertSame(404, $response->getStatusCode());
    }

    #[DataProvider('nonFailureStatuses')]
    public function test_error_does_not_rewrite_success_redirect_or_bodyless_responses(int $status): void
    {
        $this->expectException(InvalidArgumentException::class);
        ApiResponse::error(new JsonResponse([], $status));
    }

    public static function nonFailureStatuses(): array
    {
        return [[200], [201], [204], [205], [302]];
    }

    public function test_exact_exception_configuration_envelopes_native_auth_validation_and_http_errors_without_accept_header(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);
        ($this->configureExceptions)(new Exceptions($handler));
        $this->app['config']->set('app.debug', true);
        $request = Request::create('/api/users', 'POST');
        $cases = [
            [new AuthenticationException, 401, null],
            [new AuthorizationException('Forbidden.'), 403, null],
            [new HttpException(404, 'Not found.'), 404, null],
            [ValidationException::withMessages(['email' => ['Invalid email.']]), 422, ['email' => ['Invalid email.']]],
            [new HttpException(429, 'Too many requests.', headers: ['Retry-After' => '60']), 429, null],
            [new RuntimeException('Internal secret'), 500, null],
        ];

        foreach ($cases as [$exception, $status, $errors]) {
            $response = $handler->render($request, $exception);
            self::assertInstanceOf(JsonResponse::class, $response);
            self::assertSame($status, $response->getStatusCode());
            self::assertSame(['data', 'message', 'errors', 'meta', 'links'], array_keys($response->getData(true)));
            self::assertNull($response->getData()->data);
            self::assertSame($errors, $response->getData(true)['errors']);
            if ($status === 429) {
                self::assertSame('60', $response->headers->get('Retry-After'));
            }
            if ($status === 500) {
                self::assertSame('Server Error', $response->getData()->message);
                self::assertStringNotContainsString('Internal secret', $response->getContent());
            }
        }

        $challenge = $handler->render($request, new HttpException(401, 'Unauthenticated.', headers: ['WWW-Authenticate' => 'Bearer']));
        self::assertSame(401, $challenge->getStatusCode());
        self::assertSame('Bearer', $challenge->headers->get('WWW-Authenticate'));
        self::assertSame(['data', 'message', 'errors', 'meta', 'links'], array_keys($challenge->getData(true)));
    }

    public function test_exact_exception_configuration_preserves_non_api_html_and_json_handling(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);
        ($this->configureExceptions)(new Exceptions($handler));
        $exception = new HttpException(404, 'Not found.');
        $html = $handler->render(Request::create('/dashboard', 'GET'), $exception);
        self::assertInstanceOf(Response::class, $html);
        self::assertNotInstanceOf(JsonResponse::class, $html);
        self::assertSame(404, $html->getStatusCode());

        $jsonRequest = Request::create('/dashboard', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
        $json = $handler->render($jsonRequest, $exception);
        self::assertInstanceOf(JsonResponse::class, $json);
        self::assertSame(404, $json->getStatusCode());
        self::assertSame('Not found.', $json->getData()->message);
        self::assertArrayNotHasKey('data', $json->getData(true));
    }

    public function test_exception_configuration_leaves_intentional_success_and_bodyless_responses_untouched(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);
        ($this->configureExceptions)(new Exceptions($handler));
        $request = Request::create('/api/users', 'POST');
        $success = new JsonResponse(['accepted' => true], 202);
        $empty = new Response('', 204);

        self::assertSame($success, $handler->render($request, new HttpResponseException($success)));
        self::assertSame(['accepted' => true], $success->getData(true));
        self::assertSame($empty, $handler->render($request, new HttpResponseException($empty)));
        self::assertSame('', $empty->getContent());
        self::assertSame(204, $empty->getStatusCode());
    }

    private function loadSource(string $source): mixed
    {
        $path = tempnam(sys_get_temp_dir(), 'laracanon-api-example-');
        file_put_contents($path, $source);

        try {
            return require $path;
        } finally {
            unlink($path);
        }
    }
}

final class SafeApiUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->resource['id'], 'name' => $this->resource['name']];
    }
}
