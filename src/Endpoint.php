<?php

declare(strict_types=1);

namespace Mnd\DmPlatform;

use SensitiveParameter;

/**
 * One installation the cabinet already decided to call. The package does not look
 * installations up: the application owns that registry. The secrets stay out of
 * stack traces and dumps.
 */
final class Endpoint
{
    public function __construct(
        public readonly string $code,
        public readonly string $baseUrl,
        #[SensitiveParameter] public readonly string $apiKey,
        #[SensitiveParameter] public readonly string $webhookSecret,
    ) {
    }

    public function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/api/v1' . $path;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'code'          => $this->code,
            'baseUrl'       => $this->baseUrl,
            'apiKey'        => '***',
            'webhookSecret' => '***',
        ];
    }
}
