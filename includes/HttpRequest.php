<?php
declare(strict_types=1);

namespace Maradigma;

/**
 * Small wrapper around WordPress HTTP API.
 *
 * This allows you to centralize:
 * - timeouts
 * - user-agent
 * - default headers
 * - consistent error objects
 *
 * ExternalApiClient should delegate actual HTTP calls to this helper.
 */
final class HttpRequest
{
    /**
     * Executes an HTTP request using WP HTTP API.
     *
     * @param string $method GET|POST|PUT|DELETE
     * @param string $url
     * @param array<string,string> $headers
     * @param array<string,mixed>|string|null $body
     * @param int $timeoutSeconds
     * @return array{status:int, body:string, headers:array<string,string>, error:?string}
     */
    public static function request(
        string $method,
        string $url,
        array $headers = [],
        array|string|null $body = null,
        int $timeoutSeconds = 15
    ): array {
        if (!\function_exists('wp_remote_request')) {
            return [
                'status' => 0,
                'body' => '',
                'headers' => [],
                'error' => 'wp_remote_request() is not available.',
            ];
        }

        $args = [
            'method'  => \strtoupper($method),
            'timeout' => $timeoutSeconds,
            'headers' => $headers,
        ];

        if ($body !== null) {
            $args['body'] = $body;
        }

        // User-Agent is useful for external API debugging
        if (!isset($args['headers']['User-Agent'])) {
            $args['headers']['User-Agent'] = 'MaradigmaWPPlugin/1.0 (+WordPress)';
        }

        $response = \wp_remote_request($url, $args);

        if (\is_wp_error($response)) {
            return [
                'status' => 0,
                'body' => '',
                'headers' => [],
                'error' => $response->get_error_message(),
            ];
        }

        $status = (int) \wp_remote_retrieve_response_code($response);
        $bodyStr = (string) \wp_remote_retrieve_body($response);
        $headersObj = \wp_remote_retrieve_headers($response);

        $headersOut = [];
        if (\is_array($headersObj)) {
            foreach ($headersObj as $k => $v) {
                if (\is_string($k)) {
                    $headersOut[$k] = \is_array($v) ? \implode(',', $v) : (string) $v;
                }
            }
        } elseif (\is_object($headersObj) && \method_exists($headersObj, 'getAll')) {
            /** @var array<string,mixed> $all */
            $all = $headersObj->getAll();
            foreach ($all as $k => $v) {
                if (\is_string($k)) {
                    $headersOut[$k] = \is_array($v) ? \implode(',', $v) : (string) $v;
                }
            }
        }

        return [
            'status' => $status,
            'body' => $bodyStr,
            'headers' => $headersOut,
            'error' => null,
        ];
    }
}
