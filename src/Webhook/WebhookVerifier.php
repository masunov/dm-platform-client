<?php

declare(strict_types=1);

namespace Mnd\DmPlatform\Webhook;

use Mnd\DmPlatform\Exceptions\InvalidSignatureException;
use SensitiveParameter;

/**
 * Checks `X-Signature: t=<unix>,v1=<hex>`: HMAC-SHA256 of `<t>.<body>` with the webhook secret
 * of this installation (platform-v1.yaml). An old signature is refused, so a captured request
 * cannot be replayed later. Deduplicating event_id stays in the application.
 *
 * The header may carry several v1 values while the installation rotates its secret;
 * the request is accepted when any of them matches.
 */
final class WebhookVerifier
{
    public const HEADER = 'X-Signature';

    public function __construct(
        #[SensitiveParameter] private readonly string $secret,
        private readonly int $toleranceSeconds = 300,
    ) {
    }

    public static function sign(#[SensitiveParameter] string $secret, string $body, int $timestamp): string
    {
        $mac = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        return 't=' . $timestamp . ',v1=' . $mac;
    }

    /**
     * @throws InvalidSignatureException
     */
    public function verify(?string $header, string $body, ?int $now = null): WebhookEvent
    {
        if ($this->secret === '') {
            throw new InvalidSignatureException('Webhook secret is not configured');
        }

        $timestamp  = null;
        $signatures = [];

        foreach (explode(',', (string) $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($name === 't') {
                $timestamp = $value;
            } elseif ($name === 'v1' && $value !== '') {
                $signatures[] = strtolower($value);
            }
        }

        if ($timestamp === null || $signatures === [] || ! ctype_digit($timestamp)) {
            throw new InvalidSignatureException('Webhook signature is missing or malformed');
        }

        if (abs(($now ?? time()) - (int) $timestamp) > $this->toleranceSeconds) {
            throw new InvalidSignatureException('Webhook signature is too old');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $this->secret);
        $matched  = false;

        // Every value is compared, so the time does not tell which one matched.
        foreach ($signatures as $signature) {
            $matched = hash_equals($expected, $signature) || $matched;
        }

        if (! $matched) {
            throw new InvalidSignatureException('Webhook signature does not match');
        }

        return WebhookEvent::fromJson($body);
    }
}
