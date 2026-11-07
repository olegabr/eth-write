<?php

/**
 * The contract: calldata, calls and their refusals.
 *
 * @package olegabr/eth-write
 */

declare(strict_types=1);

namespace Ethereumico\EthWrite\Tests;

use Ethereumico\EthWrite\Client;
use Ethereumico\EthWrite\Contract;
use Olegabr\EthAbi\Problem;
use PHPUnit\Framework\TestCase;

final class ContractTest extends TestCase
{
    private const ABI = '[
        {"type":"function","name":"balanceOf","stateMutability":"view",
         "inputs":[{"name":"owner","type":"address"}],
         "outputs":[{"name":"","type":"uint256"}]},
        {"type":"function","name":"decimals","stateMutability":"view",
         "inputs":[],"outputs":[{"name":"","type":"uint8"}]},
        {"type":"function","name":"transfer","stateMutability":"nonpayable",
         "inputs":[{"name":"to","type":"address"},{"name":"amount","type":"uint256"}],
         "outputs":[{"name":"","type":"bool"}]}
    ]';

    private const TOKEN = '0x1111111111111111111111111111111111111111';

    public function testDataEncodesSelectorAndArguments(): void
    {
        $contract = Contract::fromJson(self::ABI)['contract'];
        $owner = '0x2222222222222222222222222222222222222222';

        $encoded = $contract->data('balanceOf', [$owner]);

        self::assertArrayHasKey('data', $encoded);
        self::assertStringStartsWith('0x70a08231', $encoded['data']);
        self::assertStringEndsWith(str_repeat('0', 24) . substr($owner, 2), $encoded['data']);
    }

    public function testCallDecodesASingleOutput(): void
    {
        $transport = new FakeTransport();
        $transport->queue('0x0000000000000000000000000000000000000000000000000000000000000012');
        $client = new Client('https://rpc.test', $transport);
        $contract = Contract::fromJson(self::ABI, $client, self::TOKEN)['contract'];

        $answer = $contract->call('decimals');

        self::assertArrayHasKey('value', $answer);
        self::assertSame('18', (string) $answer['value']);
    }

    public function testCallUsesTheBoundAddress(): void
    {
        $transport = new FakeTransport();
        $transport->queue('0x0000000000000000000000000000000000000000000000000000000000000001');
        $client = new Client('https://rpc.test', $transport);
        $contract = Contract::fromJson(self::ABI, $client, self::TOKEN)['contract'];

        $contract->call('decimals');

        $params = $transport->lastPayload()['params'];
        self::assertSame(self::TOKEN, $params[0]['to']);
    }

    public function testCallRefusesAStateChangingFunction(): void
    {
        $transport = new FakeTransport();
        $client = new Client('https://rpc.test', $transport);
        $contract = Contract::fromJson(self::ABI, $client, self::TOKEN)['contract'];

        $answer = $contract->call('transfer', [self::TOKEN, '1']);

        self::assertInstanceOf(Problem::class, $answer['problem']);
        self::assertSame('abi.not_read_only', $answer['problem']->code());
    }

    public function testCallWithoutClientIsRefused(): void
    {
        $contract = Contract::fromJson(self::ABI)['contract']->at(self::TOKEN);

        $answer = $contract->call('decimals');

        self::assertInstanceOf(Problem::class, $answer['problem']);
        self::assertSame('contract.unbound', $answer['problem']->code());
    }
}
