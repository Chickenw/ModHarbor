<?php

namespace GameNest\GameNestModManager\Services;

use RuntimeException;

/** Reject credential-bearing portable data without echoing the rejected value. */
final class PortableDefinitionGuard
{
    public static function check(mixed $value, array $secrets = [], int $depth = 0): void
    {
        if ($depth > 24) { throw new RuntimeException('Portable data is too complex.'); }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match('/(?:api[_-]?key|token|secret|password|passwd|credential|authorization|cookie|private[_-]?key|client[_-]?secret|signature)/i', $key)) {
                    self::reject();
                }
                if (is_string($key)) { self::check($key, $secrets, $depth + 1); }
                self::check($item, $secrets, $depth + 1);
            }
            return;
        }
        if (!is_scalar($value)) { return; }
        $value = (string) $value;
        foreach ($secrets as $secret) {
            if ($secret === '') { continue; }
            foreach ([$secret, rawurlencode($secret), base64_encode($secret), rtrim(strtr(base64_encode($secret), '+/', '-_'), '=')] as $variant) {
                if (str_contains($value, $variant)) { self::reject(); }
            }
        }
        if (preg_match('/(?:\b(?:Bearer|Basic)\s+\S+|-----BEGIN .*PRIVATE KEY-----|(?:password|token|secret|api[_-]?key|authorization|cookie)\s*[:=])/i', $value)) { self::reject(); }
        // Query/fragment URLs and URL userinfo are not portable public references.
        if (preg_match_all('~(?:https?://|/)[^\s<>"\']+~i', $value, $matches)) {
            foreach ($matches[0] as $url) {
                $parts = parse_url($url);
                if ($parts === false || isset($parts['user'], $parts['pass']) || isset($parts['user'])
                    || isset($parts['query']) || isset($parts['fragment'])) { self::reject(); }
            }
        }
        // Also cover percent-encoded and common base64/base64url URL/auth wrappers.
        $decoded = rawurldecode($value);
        if ($decoded !== $value) { self::check($decoded, $secrets, $depth + 1); }
        if (strlen($value) >= 12 && preg_match('/^[A-Za-z0-9_+\/=\-]+$/D', $value)) {
            $decoded = base64_decode(strtr($value, '-_', '+/'), true);
            if ($decoded !== false && $decoded !== $value && preg_match('/^[\x20-\x7e]+$/D', $decoded)) {
                self::check($decoded, $secrets, $depth + 1);
            }
        }
    }

    private static function reject(): never
    {
        throw new RuntimeException('Remove credentials, authorization data and private URL parameters before sharing. Credentials belong in Provider Settings.');
    }
}
