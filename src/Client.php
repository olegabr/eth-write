<?php

/**
 * The write half of JSON-RPC, over the same transport the read half uses.
 *
 * @package olegabr/eth-write
 */

declare(strict_types=1);

namespace Ethereumico\EthWrite;

use InvalidArgumentException;
use JsonException;
use Ethereumico\EthAbi\Problem;
use Ethereumico\EthRead\Response;
use Ethereumico\EthRead\Revert;
use Ethereumico\EthRead\Transport;
use Ethereumico\EthRead\TransportError;
use Throwable;

/**
 * A client for the read *and* write halves of JSON-RPC.
 *
 * The read half already lives in `olegabr/eth-read`; this client exists only
 * because sending and estimating need methods that library deliberately refuses
 * (`eth_sendRawTransaction`, `eth_sendTransaction`, `eth_gasPrice`, ...). It keeps
 * the three rules of the read client verbatim:
 *
 *  * **the transport is injected** - a WordPress site passes a `wp_remote_post`
 *    adapter, a CLI passes curl or streams, a test passes canned answers;
 *  * **every call has a deadline** - `$timeout_seconds` is required and passed to
 *    the transport on every request;
 *  * **only the listed methods exist** - {@see self::ALLOWED_METHODS}. A method
 *    outside the list is refused before any request is made.
 *
 * It does **not** sign: a raw transaction arrives already signed by the caller
 * (`ethereum-tx` or `wallet-chains-*`). Keys never enter this package.
 *
 * @since 0.1.0
 */
final class Client
{
    /**
     * Methods that read chain state. Mirrored from `olegabr/eth-read` so this
     * client can serve a caller that still needs a read between writes.
     *
     * @var string[]
     */
    public const READ_METHODS = [
        'eth_call',
        'eth_getCode',
        'eth_getBalance',
        'eth_blockNumber',
        'eth_chainId',
        'eth_getLogs',
        'eth_estimateGas',
        'eth_getTransactionReceipt',
        'eth_getTransactionCount',
        'net_version',
    ];

    /**
     * Methods that move value or ask the node to move it.
     *
     * `eth_sendTransaction` and `eth_accounts` assume a node-managed account; a
     * caller that signs locally uses `eth_sendRawTransaction` and never touches
     * them. They are listed because the write surface of our consumers calls them.
     *
     * @var string[]
     */
    public const WRITE_METHODS = [
        'eth_sendRawTransaction',
        'eth_sendTransaction',
        'eth_gasPrice',
        'eth_maxPriorityFeePerGas',
        'eth_feeHistory',
        'eth_getTransactionByHash',
        'eth_accounts',
    ];

    /**
     * Everything this client will send.
     *
     * @var string[]
     */
    public const ALLOWED_METHODS = [
        'eth_call',
        'eth_getCode',
        'eth_getBalance',
        'eth_blockNumber',
        'eth_chainId',
        'eth_getLogs',
        'eth_estimateGas',
        'eth_getTransactionReceipt',
        'eth_getTransactionCount',
        'net_version',
        'eth_sendRawTransaction',
        'eth_sendTransaction',
        'eth_gasPrice',
        'eth_maxPriorityFeePerGas',
        'eth_feeHistory',
        'eth_getTransactionByHash',
        'eth_accounts',
    ];

    /**
     * @since 0.1.0
     *
     * @param string    $url             Endpoint.
     * @param Transport $transport       How a request gets to the endpoint.
     * @param int       $timeout_seconds Deadline for one round trip.
     * @param int       $attempts        How many times one request may be tried.
     * @param int       $max_body_bytes  Refuse an answer larger than this.
     *
     * @throws InvalidArgumentException When the timeout is not positive.
     */
    public function __construct(
        private readonly string $url,
        private readonly Transport $transport,
        private readonly int $timeout_seconds = 10,
        private readonly int $attempts = 2,
        private readonly int $max_body_bytes = 4_194_304,
    ) {
        if ($this->timeout_seconds <= 0) {
            throw new InvalidArgumentException('timeout_seconds must be positive');
        }
    }

    /**
     * One JSON-RPC call.
     *
     * @since 0.1.0
     *
     * @param string $method Allowed method name.
     * @param array  $params Positional parameters.
     *
     * @throws InvalidArgumentException When the method is not allowed.
     *
     * @return array{result?:mixed,problem?:Problem} Exactly one key is present:
     *                                               `result` on success,
     *                                               `problem` otherwise.
     */
    public function request(string $method, array $params = []): array
    {
        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            throw new InvalidArgumentException('rpc.method_not_allowed: ' . $method);
        }

        $payload = $this->payload($method, $params);
        $last = null;

        for ($attempt = 1; $attempt <= max(1, $this->attempts); $attempt++) {
            try {
                $response = $this->transport->post($this->url, $payload, $this->timeout_seconds);
            } catch (TransportError) {
                // The node said nothing, so nothing was decided: worth one retry.
                $last = new Problem('rpc.transport', [$method]);
                $this->sleep($attempt);

                continue;
            }

            if (strlen($response->body()) > $this->max_body_bytes) {
                return ['problem' => new Problem('rpc.body_too_large', [strlen($response->body())])];
            }

            if ($response->status() >= 500 || 429 === $response->status()) {
                $last = new Problem('rpc.http_status', [$response->status()]);
                $this->sleep($attempt);

                continue;
            }

            return $this->decode($response, $method);
        }

        return ['problem' => $last ?? new Problem('rpc.transport', [$method])];
    }

    /**
     * @param string $method Method name.
     * @param array  $params Parameters.
     *
     * @throws InvalidArgumentException When a parameter is not encodable.
     *
     * @return string JSON body.
     */
    private function payload(string $method, array $params): string
    {
        try {
            return (string) json_encode(
                [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => $method,
                    'params' => array_values($params),
                ],
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            throw new InvalidArgumentException('rpc.params_not_encodable: ' . $method);
        }
    }

    /**
     * @param Response $response Answer.
     * @param string   $method   Method, for the code.
     *
     * @return array{result?:mixed,problem?:Problem}
     */
    private function decode(Response $response, string $method): array
    {
        try {
            $decoded = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['problem' => new Problem('rpc.invalid_json', [$method])];
        }

        if (!is_array($decoded)) {
            return ['problem' => new Problem('rpc.invalid_json', [$method])];
        }

        if (isset($decoded['error'])) {
            $code = is_array($decoded['error']) ? ($decoded['error']['code'] ?? 0) : 0;
            $message = is_array($decoded['error']) ? (string) ($decoded['error']['message'] ?? '') : '';
            $data = is_array($decoded['error']) ? ($decoded['error']['data'] ?? null) : null;

            if (is_string($data) && str_starts_with($data, '0x') && strlen($data) > 2) {
                $revert = Revert::decode($data);

                if (null !== $revert) {
                    return ['problem' => $revert];
                }
            }

            return ['problem' => new Problem('rpc.error', [(int) $code, $message, $method])];
        }

        if (!array_key_exists('result', $decoded)) {
            return ['problem' => new Problem('rpc.no_result', [$method])];
        }

        return ['result' => $decoded['result']];
    }

    /**
     * A short backoff between attempts.
     *
     * @param int $attempt Attempt number, 1-based.
     */
    private function sleep(int $attempt): void
    {
        if ($attempt < 1) {
            return;
        }

        try {
            usleep(min(200_000, 25_000 * $attempt));
        } catch (Throwable) {
            // usleep does not throw; keep the signature honest for analysers.
        }
    }
}
