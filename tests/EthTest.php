<?php

/**
 * The eth facade: submission, gas and receipts.
 *
 * @package olegabr/eth-write
 */

declare(strict_types=1);

namespace Ethereumico\EthWrite\Tests;

use Ethereumico\EthWrite\Client;
use Ethereumico\EthWrite\Eth;
use Ethereumico\EthAbi\Problem;
use PHPUnit\Framework\TestCase;

final class EthTest extends TestCase
{
    public function testSendRawTransactionReturnsTheHash(): void
    {
        $transport = new FakeTransport();
        $transport->queue('0xhash');
        $eth = new Eth(new Client('https://rpc.test', $transport));

        self::assertSame(['result' => '0xhash'], $eth->sendRawTransaction('0xdead'));
    }

    public function testEstimateGasReturnsAnInteger(): void
    {
        $transport = new FakeTransport();
        $transport->queue('0x5208');
        $eth = new Eth(new Client('https://rpc.test', $transport));

        self::assertSame(['value' => 21000], $eth->estimateGas(['to' => '0x0']));
    }

    public function testWaitForReceiptReturnsWhenMined(): void
    {
        $transport = new FakeTransport();
        $transport->queue(null);
        $transport->queue(['transactionHash' => '0xhash', 'status' => '0x1']);
        $eth = new Eth(new Client('https://rpc.test', $transport, 10, 1));

        $answer = $eth->waitForReceipt('0xhash', 5, 1000);

        self::assertArrayHasKey('value', $answer);
        self::assertSame('0x1', $answer['value']['status']);
    }

    public function testWaitForReceiptGivesUpAtTheDeadline(): void
    {
        $transport = new FakeTransport();
        for ($i = 0; $i < 6; $i++) {
            $transport->queue(null);
        }
        $eth = new Eth(new Client('https://rpc.test', $transport, 10, 1));

        $answer = $eth->waitForReceipt('0xhash', 1, 300_000);

        self::assertInstanceOf(Problem::class, $answer['problem']);
        self::assertSame('tx.not_mined', $answer['problem']->code());
    }
}
