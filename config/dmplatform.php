<?php

declare(strict_types=1);

/*
 * Settings shared by every installation. The installations themselves, with their URL,
 * API key and webhook secret, live in the application: the package does not look them up.
 */
return [
    // A cache store all workers share, so they see one circuit state. null is the default store.
    'cache_store' => env('DMPLATFORM_CACHE_STORE'),

    // Seconds for the whole request and for the connection.
    'timeout'         => (float) env('DMPLATFORM_TIMEOUT', 5),
    'connect_timeout' => (float) env('DMPLATFORM_CONNECT_TIMEOUT', 2),

    'retry' => [
        // Delays before each retry, comma separated; their count is the number of retries. Empty means no retries.
        'delays_ms' => env('DMPLATFORM_RETRY_DELAYS_MS', '200,500'),

        // A Retry-After longer than this is shortened, so a 429 does not stall the caller.
        'retry_after_cap' => (int) env('DMPLATFORM_RETRY_AFTER_CAP', 2),
    ],

    'circuit' => [
        // Outages in a row that stop the calls to one installation.
        'threshold' => (int) env('DMPLATFORM_CIRCUIT_THRESHOLD', 5),

        // Seconds before one probe call is let through.
        'cooldown' => (int) env('DMPLATFORM_CIRCUIT_COOLDOWN', 30),
    ],

    'webhooks' => [
        // A signature older than this is refused, so a captured webhook cannot be replayed.
        'tolerance' => (int) env('DMPLATFORM_WEBHOOK_TOLERANCE', 300),
    ],
];
