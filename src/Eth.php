<?php

/**
 * The `eth` namespace as a typed facade over {@see Client}.
 *
 * @package olegabr/eth-write
 */

declare(strict_types=1);

namespace Ethereumico\EthWrite;

use Olegabr\EthAbi\Problem;

/**
 * Writing to a chain: submission, gas, receipts and the reads a write needs.
 *
 * Every method returns `['result' => …]` or `['value' => …]` on success and
 * `['problem' => Problem]` otherwise - never a sentence, never an exception for a
 * protocol refusal. A caller that wants the chain's words translates the code and
 * its params itself.
 *
 * Nothing here signs. `sendRawTransaction()` takes a signed string; if a caller
 * has an unsigned transaction it signs it with `olegabr/ethereum-tx` (or a
 * `wallet-chains-*` adapter) and passes the hex in.
 *
 * @since 0.1.0
 */
final class Eth
{
    /**
     * @since 0.1.0
     */
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * `eth_sendRawTransaction`: submit an already-signed transaction.
     *
     * @since 0.1.0
     *
     * @param string $signedHex Hex-encoded signed transaction, `0x`-prefixed.
     *
     * @return array{result?:string,problem?:Problem} `result` is the tx hash.
     */
    public function sendRawTransaction(string $signedHex): array
    {
        $answer = $this->client->request('eth_sendRawTransaction', [$signedHex]);

        if (isset($answer['problem'])) {
            return $answer;
        }

        if (!is_string($answer['result']) || '' === $answer['result']) {
            return ['problem' => new Problem('tx.no_hash', [])];
        }

        return ['result' => $answer['result']];
    }

    /**
     * `eth_sendTransaction`: ask a node-managed account to send.
     *
     * Only meaningful when the node holds the key (`eth_accounts`); a caller that
     * signs locally uses {@see self::sendRawTransaction()} instead.
     *
     * @since 0.1.0
     *
     * @param array $transaction A JSON-RPC transaction object (`from`, `to`, …).
     *
     * @return array{result?:string,problem?:Problem} `result` is the tx hash.
     */
    public function sendTransaction(array $transaction): array
    {
        $answer = $this->client->request('eth_sendTransaction', [$transaction]);

        if (isset($answer['problem'])) {
            return $answer;
        }

        if (!is_string($answer['result']) || '' === $answer['result']) {
            return ['problem' => new Problem('tx.no_hash', [])];
        }

        return ['result' => $answer['result']];
    }

    /**
     * `eth_estimateGas`.
     *
     * @since 0.1.0
     *
     * @param array $transaction A JSON-RPC transaction object.
     *
     * @return array{value?:int,problem?:Problem} `value` is gas as an integer.
     */
    public function estimateGas(array $transaction): array
    {
        $answer = $this->client->request('eth_estimateGas', [$transaction]);

        if (isset($answer['problem'])) {
            return $answer;
        }

        if (!is_string($answer['result'])) {
            return ['problem' => new Problem('rpc.unexpected_result', ['eth_estimateGas'])];
        }

        return ['value' => (int) hexdec(substr($answer['result'], 2))];
    }

    /**
     * `eth_call`: simulate a call at a block, without sending a transaction.
     *
     * @since 0.1.0
     *
     * @param array  $transaction A JSON-RPC call object (`to`, `data`, …).
     * @param string $block       Block tag or number.
     *
     * @return array{result?:mixed,problem?:Problem} Raw hex answer.
     */
    public function call(array $transaction, string $block = 'latest'): array
    {
        return $this->client->request('eth_call', [$transaction, $block]);
    }

    /**
     * `eth_gasPrice`.
     *
     * @since 0.1.0
     *
     * @return array{value?:string,problem?:Problem} Wei as a decimal string.
     */
    public function gasPrice(): array
    {
        $answer = $this->client->request('eth_gasPrice', []);

        if (isset($answer['problem'])) {
            return $answer;
        }

        if (!is_string($answer['result'])) {
            return ['problem' => new Problem('rpc.unexpected_result', ['eth_gasPrice'])];
        }

        return ['value' => (string) \Olegabr\EthAbi\Support\Uint::fromHex($answer['result'])];
    }

    /**
     * `eth_getTransactionReceipt`.
     *
     * @since 0.1.0
     *
     * @param string $hash Transaction hash.
     *
     * @return array{result?:array|null,problem?:Problem} `null` while pending.
     */
    public function getTransactionReceipt(string $hash): array
    {
        return $this->client->request('eth_getTransactionReceipt', [$hash]);
    }

    /**
     * `eth_getTransactionCount`, the nonce.
     *
     * @since 0.1.0
     *
     * @param string $address Address.
     * @param string $block   Block tag or number.
     *
     * @return array{value?:int,problem?:Problem}
     */
    public function getTransactionCount(string $address, string $block = 'latest'): array
    {
        $answer = $this->client->request('eth_getTransactionCount', [$address, $block]);

        if (isset($answer['problem'])) {
            return $answer;
        }

        if (!is_string($answer['result'])) {
            return ['problem' => new Problem('rpc.unexpected_result', ['eth_getTransactionCount'])];
        }

        return ['value' => (int) hexdec(substr($answer['result'], 2))];
    }

    /**
     * `eth_getBalance`, in wei, as a decimal string.
     *
     * @since 0.1.0
     *
     * @param string $address Address.
     * @param string $block   Block tag or number.
     *
     * @return array{value?:string,problem?:Problem}
     */
    public function getBalance(string $address, string $block = 'latest'): array
    {
        $answer = $this->client->request('eth_getBalance', [$address, $block]);

        if (isset($answer['problem'])) {
            return $answer;
        }

        if (!is_string($answer['result'])) {
            return ['problem' => new Problem('rpc.unexpected_result', ['eth_getBalance'])];
        }

        return ['value' => (string) \Olegabr\EthAbi\Support\Uint::fromHex($answer['result'])];
    }

    /**
     * `eth_getCode`: whether an address is a contract at all.
     *
     * @since 0.1.0
     *
     * @param string $address Address.
     *
     * @return array{result?:string,problem?:Problem}
     */
    public function getCode(string $address, string $block = 'latest'): array
    {
        return $this->client->request('eth_getCode', [$address, $block]);
    }

    /**
     * `eth_blockNumber`, as an integer.
     *
     * @since 0.1.0
     *
     * @return array{value?:int,problem?:Problem}
     */
    public function blockNumber(): array
    {
        $answer = $this->client->request('eth_blockNumber', []);

        if (isset($answer['problem'])) {
            return $answer;
        }

        if (!is_string($answer['result'])) {
            return ['problem' => new Problem('rpc.unexpected_result', ['eth_blockNumber'])];
        }

        return ['value' => (int) hexdec(substr($answer['result'], 2))];
    }

    /**
     * `eth_chainId`, as an integer.
     *
     * @since 0.1.0
     *
     * @return array{value?:int,problem?:Problem}
     */
    public function chainId(): array
    {
        $answer = $this->client->request('eth_chainId', []);

        if (isset($answer['problem'])) {
            return $answer;
        }

        if (!is_string($answer['result'])) {
            return ['problem' => new Problem('rpc.unexpected_result', ['eth_chainId'])];
        }

        return ['value' => (int) hexdec(substr($answer['result'], 2))];
    }

    /**
     * Wait for a transaction to be mined, polling the receipt.
     *
     * A deadline is mandatory: a submit that never confirms must not become a page
     * that never renders. The wait is bounded by `$timeoutSeconds`; the poll
     * interval is `$pollMicroseconds`.
     *
     * @since 0.1.0
     *
     * @param string $hash              Transaction hash.
     * @param int    $timeoutSeconds    Upper bound on the wait.
     * @param int    $pollMicroseconds  Delay between receipt checks.
     *
     * @throws \InvalidArgumentException When the timeout is not positive.
     *
     * @return array{value?:array,problem?:Problem} `value` is the receipt.
     */
    public function waitForReceipt(string $hash, int $timeoutSeconds = 120, int $pollMicroseconds = 1_000_000): array
    {
        if ($timeoutSeconds <= 0) {
            throw new \InvalidArgumentException('timeout_seconds must be positive');
        }

        $deadline = microtime(true) + $timeoutSeconds;

        do {
            $answer = $this->getTransactionReceipt($hash);

            if (isset($answer['problem'])) {
                return $answer;
            }

            if (is_array($answer['result'])) {
                return ['value' => $answer['result']];
            }

            if (microtime(true) >= $deadline) {
                break;
            }

            usleep(max(0, $pollMicroseconds));
        } while (true);

        return ['problem' => new Problem('tx.not_mined', [$hash, $timeoutSeconds])];
    }
}
