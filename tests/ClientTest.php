<?php

/**
 * The client: allowed methods, retries, and refusals as values.
 *
 * @package olegabr/eth-write
 */

declare(strict_types=1);

namespace Ethereumico\EthWrite\Tests;

use Ethereumico\EthWrite\Client;
use InvalidArgumentException;
use Olegabr\EthAbi\Problem;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    public function testAllowsAWriteMethodAndReturnsItsResult(): void
    {
        $transport = new FakeTransport();
        $transport->queue('0xabc');
        $client = new Client('https://rpc.test', $transport);

        $answer = $client->request('eth_sendRawTransaction', ['0xdead']);

        self::assertSame('0xabc', $answer['result']);
        self::assertSame('eth_sendRawTransaction', $transport->lastPayload()['method']);
    }

    public function testRefusesAMethodOutsideTheList(): void
    {
        $client = new Client('https://rpc.test', new FakeTransport());

        $this->expectException(InvalidArgumentException::class);
        $client->request('eth_unknownThing', []);
    }

    public function testRetriesA5xxAndThenSucceeds(): void
    {
        $transport = new FakeTransport();
        $transport->queueRaw(502, 'bad gateway');
        $transport->queue('0x1');

        $client = new Client('https://rpc.test', $transport, 10, 2);
        $answer = $client->request('eth_blockNumber', []);

        self::assertSame('0x1', $answer['result']);
        self::assertSame(2, $transport->count());
    }

    public function testTransportFailureBecomesAProblem(): void
    {
        $transport = new FakeTransport();
        $transport->queueTransportFailure();
        $transport->queueTransportFailure();

        $client = new Client('https://rpc.test', $transport, 10, 2);
        $answer = $client->request('eth_gasPrice', []);

        self::assertInstanceOf(Problem::class, $answer['problem']);
        self::assertSame('rpc.transport', $answer['problem']->code());
    }

    public function testRevertDataBecomesARevertProblem(): void
    {
        $transport = new FakeTransport();
        // Standard `Error(string)` revert with "nope".
        $transport->queueError(3, 'execution reverted', '0x08c379a0000000000000000000000000000000000000000000000000000000000000002000000000000000000000000000000000000000000000000000000000000000046e6f706500000000000000000000000000000000000000000000000000000000');

        $client = new Client('https://rpc.test', $transport);
        $answer = $client->request('eth_call', [['to' => '0x0', 'data' => '0x'], 'latest']);

        self::assertArrayHasKey('problem', $answer);
    }

    public function testZeroTimeoutIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Client('https://rpc.test', new FakeTransport(), 0);
    }
}
