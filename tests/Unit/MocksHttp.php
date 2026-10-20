<?php

declare(strict_types=1);

namespace Mnd\DmPlatform\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * A Guzzle client that answers from a queue and remembers what it was asked.
 */
trait MocksHttp
{
    /** @var list<array{request: RequestInterface}> */
    protected array $history = [];

    protected MockHandler $mock;

    protected function mockHttp(): Client
    {
        $this->history = [];
        $this->mock    = new MockHandler();
        $stack         = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));

        return new Client(
            [
            'handler' => $stack,
            'http_errors' => false,
            ]
        );
    }

    /**
     * @param array<string, mixed>|null $body
     */
    protected static function jsonResponse(int $status, ?array $body, array $headers = []): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json', ...$headers],
            $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    protected static function apiError(int $status, string $code, string $message = 'Отказ', array $fields = []): Response
    {
        $error = [
            'code'    => $code,
            'message' => $message,
        ];

        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        return self::jsonResponse(
            $status,
            [
            'message' => $message,
            'error'   => $error,
            ]
        );
    }

    protected function sentRequest(int $index = 0): RequestInterface
    {
        return $this->history[$index]['request'];
    }

    protected function connectFailure(): ConnectException
    {
        return new ConnectException('connection refused', new Request('GET', 'https://ru-1.school.example'));
    }
}
