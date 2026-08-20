<?php

declare(strict_types=1);

namespace {
    if (!class_exists('WP_REST_Request')) {
        class WP_REST_Request
        {
            /**
             * @param array<string,string> $headers
             * @param array<string,mixed>  $params
             */
            public function __construct(
                private array $headers = [],
                private array $params = [],
                private string $route = '/maradigma/v1/booking/online'
            )
            {
            }

            public function get_header(string $name): string
            {
                return (string) ($this->headers[strtolower($name)] ?? '');
            }

            public function get_param(string $name): mixed
            {
                return $this->params[$name] ?? null;
            }

            /** @return array<string,mixed>|null */
            public function get_json_params(): ?array
            {
                return $this->params;
            }

            public function get_route(): string
            {
                return $this->route;
            }
        }
    }

    if (!class_exists('WP_Error')) {
        class WP_Error
        {
            /** @param array<string,mixed> $data */
            public function __construct(
                private string $code,
                private string $message,
                private array $data = []
            ) {
            }

            public function get_error_code(): string
            {
                return $this->code;
            }

            public function get_error_message(): string
            {
                return $this->message;
            }

            /** @return array<string,mixed> */
            public function get_error_data(): array
            {
                return $this->data;
            }
        }
    }

    if (!class_exists('WP_REST_Response')) {
        class WP_REST_Response
        {
            /** @var array<string,string> */
            private array $headers = [];

            public function __construct(
                private mixed $data = null,
                private int $status = 200
            ) {
            }

            public function get_status(): int
            {
                return $this->status;
            }

            public function get_data(): mixed
            {
                return $this->data;
            }

            public function header(string $name, string $value): void
            {
                $this->headers[$name] = $value;
            }

            public function get_header(string $name): string
            {
                return $this->headers[$name] ?? '';
            }
        }
    }

    if (!function_exists('wp_create_nonce')) {
        function wp_create_nonce(string $action): string
        {
            return 'nonce-' . $action;
        }
    }

    if (!function_exists('wp_verify_nonce')) {
        function wp_verify_nonce(string $nonce, string $action): int|false
        {
            return hash_equals('nonce-' . $action, $nonce) ? 1 : false;
        }
    }

    if (!function_exists('sanitize_text_field')) {
        function sanitize_text_field(string $value): string
        {
            return trim($value);
        }
    }

    if (!function_exists('sanitize_key')) {
        function sanitize_key(string $value): string
        {
            return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)) ?? '';
        }
    }

    if (!function_exists('wp_unslash')) {
        /** @param mixed $value */
        function wp_unslash($value): mixed
        {
            return $value;
        }
    }

    if (!function_exists('apply_filters')) {
        /** @param mixed $value */
        function apply_filters(string $hookName, $value, mixed ...$args): mixed
        {
            $callback = $GLOBALS['maradigma_test_filters'][$hookName] ?? null;
            if (is_callable($callback)) {
                return $callback($value, ...$args);
            }

            return $value;
        }
    }

    if (!function_exists('get_transient')) {
        function get_transient(string $key): mixed
        {
            return $GLOBALS['maradigma_test_transients'][$key] ?? false;
        }
    }

    if (!function_exists('set_transient')) {
        function set_transient(string $key, mixed $value, int $expiration): bool
        {
            $GLOBALS['maradigma_test_transients'][$key] = $value;
            return true;
        }
    }

    if (!function_exists('delete_transient')) {
        function delete_transient(string $key): bool
        {
            unset($GLOBALS['maradigma_test_transients'][$key]);
            return true;
        }
    }

    if (!function_exists('wp_json_encode')) {
        /** @param mixed $value */
        function wp_json_encode($value): string|false
        {
            return json_encode($value);
        }
    }

    if (!function_exists('wp_salt')) {
        function wp_salt(string $scheme = 'auth'): string
        {
            return 'maradigma-test-salt-' . $scheme;
        }
    }

    if (!function_exists('__')) {
        function __(string $text, string $domain = 'default'): string
        {
            return $text;
        }
    }
}

namespace Maradigma\Tests\Unit\Support {
    use Maradigma\Support\PublicBookingGuard;
    use PHPUnit\Framework\TestCase;
    use WP_Error;
    use WP_REST_Request;
    use WP_REST_Response;

    final class PublicBookingGuardTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();

            $GLOBALS['maradigma_test_transients'] = [];
            $GLOBALS['maradigma_test_filters'] = [];
            $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
            $_SERVER['HTTP_USER_AGENT'] = 'Maradigma PHPUnit';
        }

        protected function tearDown(): void
        {
            unset(
                $GLOBALS['maradigma_test_transients'],
                $GLOBALS['maradigma_test_filters'],
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            );

            parent::tearDown();
        }

        public function testCreatesDedicatedBookingNonce(): void
        {
            self::assertSame(
                'nonce-' . PublicBookingGuard::NONCE_ACTION,
                PublicBookingGuard::createNonce()
            );
        }

        public function testRejectsRequestWithoutValidNonce(): void
        {
            $result = PublicBookingGuard::authorize(new WP_REST_Request());

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('maradigma_booking_invalid_nonce', $result->get_error_code());
            self::assertSame(403, $result->get_error_data()['status'] ?? null);
        }

        public function testAcceptsValidNonceAndStoresOnlyHashedRateKey(): void
        {
            $request = $this->createAuthorizedRequest();

            self::assertTrue(PublicBookingGuard::authorize($request));
            self::assertCount(1, $GLOBALS['maradigma_test_transients']);

            $key = (string) array_key_first($GLOBALS['maradigma_test_transients']);
            self::assertStringStartsWith('maradigma_booking_rate_', $key);
            self::assertStringNotContainsString('203.0.113.10', $key);
        }

        public function testRejectsRequestsThatExceedRateLimit(): void
        {
            $request = $this->createAuthorizedRequest();
            $GLOBALS['maradigma_test_filters']['maradigma_booking_rate_limit'] = static fn (): int => 3;

            for ($attempt = 0; $attempt < 3; $attempt++) {
                self::assertTrue(PublicBookingGuard::authorize($request));
            }

            $result = PublicBookingGuard::authorize($request);

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('maradigma_booking_rate_limited', $result->get_error_code());
            self::assertSame(429, $result->get_error_data()['status'] ?? null);
            self::assertSame('ip', $result->get_error_data()['policy'] ?? null);
            self::assertGreaterThan(0, $result->get_error_data()['retry_after'] ?? 0);
        }

        public function testChangingUserAgentDoesNotBypassIpRateLimit(): void
        {
            $request = $this->createAuthorizedRequest();

            self::assertTrue(PublicBookingGuard::authorize($request));
            $_SERVER['HTTP_USER_AGENT'] = 'Rotated User Agent';
            self::assertTrue(PublicBookingGuard::authorize($request));

            self::assertCount(1, $GLOBALS['maradigma_test_transients']);
            $bucket = reset($GLOBALS['maradigma_test_transients']);
            self::assertIsArray($bucket);
            self::assertSame(2, $bucket['count'] ?? null);
        }

        public function testRateLimitUsesAFixedWindow(): void
        {
            $now = 1_000;
            $GLOBALS['maradigma_test_filters']['maradigma_booking_rate_now'] = static function () use (&$now): int {
                return $now;
            };

            $request = $this->createAuthorizedRequest();
            self::assertTrue(PublicBookingGuard::authorize($request));
            $firstBucket = reset($GLOBALS['maradigma_test_transients']);

            $now = 1_100;
            self::assertTrue(PublicBookingGuard::authorize($request));
            $secondBucket = reset($GLOBALS['maradigma_test_transients']);

            self::assertSame(1_600, $firstBucket['reset_at'] ?? null);
            self::assertSame(1_600, $secondBucket['reset_at'] ?? null);
        }

        public function testAnonymousSessionsHaveIndependentBucketsBehindTheSameIp(): void
        {
            self::assertTrue(PublicBookingGuard::authorize($this->createAuthorizedRequest('session_aaaaaaaaaaaaaaaaaaaa')));
            self::assertTrue(PublicBookingGuard::authorize($this->createAuthorizedRequest('session_bbbbbbbbbbbbbbbbbbbb')));

            self::assertCount(3, $GLOBALS['maradigma_test_transients']);
        }

        public function testPaymentContextHasATighterLimit(): void
        {
            $request = $this->createAuthorizedRequest(
                'session_aaaaaaaaaaaaaaaaaaaa',
                ['step' => 3, 'uuid_shop_cart' => 'cart-test-123']
            );

            for ($attempt = 0; $attempt < 5; $attempt++) {
                self::assertTrue(PublicBookingGuard::authorize($request));
            }

            $result = PublicBookingGuard::authorize($request);
            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('payment_context', $result->get_error_data()['policy'] ?? null);
        }

        public function testPaymentIpLimitAllowsNormalTrafficBehindSharedNetworks(): void
        {
            for ($attempt = 0; $attempt < 60; $attempt++) {
                $request = $this->createAuthorizedRequest(
                    '',
                    ['step' => 3, 'uuid_shop_cart' => 'cart-test-' . $attempt]
                );

                self::assertTrue(PublicBookingGuard::authorize($request));
            }

            $result = PublicBookingGuard::authorize(
                $this->createAuthorizedRequest('', ['step' => 3, 'uuid_shop_cart' => 'cart-test-over-limit'])
            );

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('payment_ip', $result->get_error_data()['policy'] ?? null);
        }

        public function testCompletedPaymentWriteIsReplayedForTheSameIdempotencyKey(): void
        {
            $request = $this->createAuthorizedRequest(
                'session_aaaaaaaaaaaaaaaaaaaa',
                ['step' => 3, 'uuid_shop_cart' => 'cart-test-123'],
                'booking-request-1234567890'
            );

            $claim = PublicBookingGuard::beginIdempotentWrite($request);
            self::assertIsArray($claim);
            self::assertSame('claimed', $claim['state'] ?? null);

            $response = ['success' => true, 'data' => ['payment_url' => 'https://example.test/pay']];
            PublicBookingGuard::completeIdempotentWrite($claim, $request, $response);

            $replay = PublicBookingGuard::beginIdempotentWrite($request);
            self::assertIsArray($replay);
            self::assertSame('replay', $replay['state'] ?? null);
            self::assertSame($response, $replay['response'] ?? null);
        }

        public function testIdempotencyKeyCannotBeReusedWithDifferentBookingData(): void
        {
            $first = $this->createAuthorizedRequest(
                'session_aaaaaaaaaaaaaaaaaaaa',
                ['step' => 3, 'uuid_shop_cart' => 'cart-one'],
                'booking-request-1234567890'
            );
            $second = $this->createAuthorizedRequest(
                'session_aaaaaaaaaaaaaaaaaaaa',
                ['step' => 3, 'uuid_shop_cart' => 'cart-two'],
                'booking-request-1234567890'
            );

            self::assertIsArray(PublicBookingGuard::beginIdempotentWrite($first));
            $result = PublicBookingGuard::beginIdempotentWrite($second);

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('maradigma_booking_idempotency_conflict', $result->get_error_code());
        }

        public function testThrottledResponseIncludesRetryAfterHeaders(): void
        {
            $response = new WP_REST_Response(
                [
                    'code' => 'maradigma_booking_rate_limited',
                    'data' => [
                        'status' => 429,
                        'limit' => 5,
                        'retry_after' => 42,
                        'reset_at' => 1_700,
                    ],
                ],
                429
            );

            $result = PublicBookingGuard::addRateLimitHeaders(
                $response,
                null,
                new WP_REST_Request()
            );

            self::assertSame($response, $result);
            self::assertSame('42', $response->get_header('Retry-After'));
            self::assertSame('5', $response->get_header('X-RateLimit-Limit'));
            self::assertSame('0', $response->get_header('X-RateLimit-Remaining'));
            self::assertSame('1700', $response->get_header('X-RateLimit-Reset'));
        }

        /** @param array<string,mixed> $params */
        private function createAuthorizedRequest(
            string $session = '',
            array $params = [],
            string $idempotencyKey = ''
        ): WP_REST_Request
        {
            $headers = [
                strtolower(PublicBookingGuard::NONCE_HEADER) => PublicBookingGuard::createNonce(),
            ];
            if ($session !== '') {
                $headers[strtolower(PublicBookingGuard::SESSION_HEADER)] = $session;
            }
            if ($idempotencyKey !== '') {
                $headers[strtolower(PublicBookingGuard::IDEMPOTENCY_HEADER)] = $idempotencyKey;
            }

            return new WP_REST_Request($headers, $params);
        }
    }
}
