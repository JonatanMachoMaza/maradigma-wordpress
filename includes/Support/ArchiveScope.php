<?php

declare(strict_types=1);

namespace Maradigma\Support;

/**
 * Signed "scope" of a boats listing: the attributes fixed by the shortcode author
 * (boat type, builders, specific boats, ...) that the visitor cannot change.
 *
 * The listing container carries the signed scope, the archive JS sends it back with
 * every AJAX request and the server only trusts it when the signature matches, so a
 * visitor can neither drop nor alter the author's scope.
 */
final class ArchiveScope
{
    private const VERSION = 'v1';
    private const SIGNATURE_CONTEXT = 'maradigma-boats-archive-scope';
    private const MAX_TOKEN_LENGTH = 6144;

    /**
     * Picks the attributes that define the scope: allowed, non-empty and different
     * from their default.
     *
     * @param array<string,mixed>  $atts
     * @param list<string>         $allowedKeys
     * @param array<string,mixed>  $defaults
     *
     * @return array<string,string>
     */
    public static function extract(array $atts, array $allowedKeys, array $defaults = []): array
    {
        $scope = [];

        foreach ($allowedKeys as $key) {
            $value = $atts[$key] ?? null;

            if (!\is_scalar($value)) {
                continue;
            }

            $value = \trim((string) $value);

            if ($value === '') {
                continue;
            }

            if (isset($defaults[$key]) && \is_scalar($defaults[$key]) && $value === \trim((string) $defaults[$key])) {
                continue;
            }

            $scope[$key] = $value;
        }

        \ksort($scope);

        return $scope;
    }

    /**
     * Encodes and signs a scope. Returns an empty string when there is nothing to
     * carry or the token would be too large to travel in a request URL.
     *
     * @param array<string,string> $scope
     */
    public static function encode(array $scope, string $secret): string
    {
        if ($scope === [] || $secret === '') {
            return '';
        }

        $json = \json_encode($scope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!\is_string($json)) {
            return '';
        }

        $payload = self::base64UrlEncode($json);
        $token = self::VERSION . '.' . $payload . '.' . self::sign($payload, $secret);

        return \strlen($token) <= self::MAX_TOKEN_LENGTH ? $token : '';
    }

    /**
     * Verifies a token and returns its scope, or an empty array when it is missing,
     * malformed or not signed with this site's secret.
     *
     * @param list<string> $allowedKeys
     *
     * @return array<string,string>
     */
    public static function decode(string $token, string $secret, array $allowedKeys): array
    {
        $token = \trim($token);

        if ($token === '' || $secret === '' || \strlen($token) > self::MAX_TOKEN_LENGTH) {
            return [];
        }

        $parts = \explode('.', $token);

        if (\count($parts) !== 3 || $parts[0] !== self::VERSION) {
            return [];
        }

        if (!\hash_equals(self::sign($parts[1], $secret), $parts[2])) {
            return [];
        }

        $json = self::base64UrlDecode($parts[1]);
        $data = \is_string($json) ? \json_decode($json, true) : null;

        if (!\is_array($data)) {
            return [];
        }

        $scope = [];

        foreach ($allowedKeys as $key) {
            if (isset($data[$key]) && \is_string($data[$key]) && $data[$key] !== '') {
                $scope[$key] = $data[$key];
            }
        }

        return $scope;
    }

    private static function sign(string $payload, string $secret): string
    {
        return \hash_hmac('sha256', self::SIGNATURE_CONTEXT . '|' . $payload, $secret);
    }

    private static function base64UrlEncode(string $value): string
    {
        return \rtrim(\strtr(\base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string|false
    {
        return \base64_decode(\strtr($value, '-_', '+/'), true);
    }
}
