<?php

/**
 * The entry point: one object that hands out the eth facade and contracts.
 *
 * @package olegabr/eth-write
 */

declare(strict_types=1);

namespace Ethereumico\EthWrite;

use Olegabr\EthAbi\Problem;
use Olegabr\EthRead\Transport;

/**
 * A small facade over {@see Client}, {@see Eth} and {@see Contract}.
 *
 * It exists so a consumer wires a transport and a URL once and then reaches the
 * write surface without re-plumbing. There is no global state and no static
 * endpoint: the transport is the caller's, exactly as in `olegabr/eth-read`.
 *
 * @since 0.1.0
 */
final class Web3
{
    /**
     * @since 0.1.0
     */
    public function __construct(
        private readonly Client $client,
        private readonly Eth $eth,
    ) {
    }

    /**
     * Build a facade from a URL and a caller-supplied transport.
     *
     * @since 0.1.0
     *
     * @param string    $url             Endpoint.
     * @param Transport $transport       How a request gets there.
     * @param int       $timeout_seconds Deadline for one round trip.
     *
     * @return self
     */
    public static function fromTransport(string $url, Transport $transport, int $timeout_seconds = 10): self
    {
        $client = new Client($url, $transport, $timeout_seconds);

        return new self($client, new Eth($client));
    }

    /**
     * @since 0.1.0
     */
    public function client(): Client
    {
        return $this->client;
    }

    /**
     * @since 0.1.0
     */
    public function eth(): Eth
    {
        return $this->eth;
    }

    /**
     * A contract from ABI JSON, bound to the facade's client.
     *
     * @since 0.1.0
     *
     * @param string      $abiJson ABI JSON.
     * @param string|null $address Address to bind, if any.
     *
     * @return array{contract?:Contract,problem?:Problem}
     */
    public function contract(string $abiJson, ?string $address = null): array
    {
        return Contract::fromJson($abiJson, $this->client, $address);
    }
}
