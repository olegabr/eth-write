<?php

/**
 * A transport that answers from a queue, for tests.
 *
 * @package olegabr/eth-write
 */

declare(strict_types=1);

namespace Ethereumico\EthWrite\Tests;

use Olegabr\EthRead\Response;
use Olegabr\EthRead\Transport;
use Olegabr\EthRead\TransportError;

/**
 * Canned JSON-RPC answers behind the real {@see Transport} interface.
 */
final class FakeTransport implements Transport
{
    /** @var array<int,Response|null> */
    private array $responses = [];

    /** @var string[] */
    public array $requests = [];

    /**
     * Queue a successful `result`.
     */
    public function queue(mixed $result): void
    {
        $this->responses[] = new Response(200, (string) json_encode(
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]
        ));
    }

    /**
     * Queue a JSON-RPC error object.
     */
    public function queueError(int $code, string $message, ?string $data = null): void
    {
        $error = ['code' => $code, 'message' => $message];

        if (null !== $data) {
            $error['data'] = $data;
        }

        $this->responses[] = new Response(200, (string) json_encode(
            ['jsonrpc' => '2.0', 'id' => 1, 'error' => $error]
        ));
    }

    /**
     * Queue a raw HTTP answer.
     */
    public function queueRaw(int $status, string $body): void
    {
        $this->responses[] = new Response($status, $body);
    }

    /**
     * Queue a transport failure (no response at all).
     */
    public function queueTransportFailure(): void
    {
        $this->responses[] = null;
    }

    public function post(string $url, string $json, int $timeout_seconds): Response
    {
        $this->requests[] = $json;

        $next = array_shift($this->responses);

        if (null === $next) {
            throw new TransportError('no answer queued');
        }

        return $next;
    }

    public function count(): int
    {
        return count($this->requests);
    }

    public function lastPayload(): array
    {
        $last = end($this->requests);

        return is_string($last) ? (array) json_decode($last, true) : [];
    }
}
