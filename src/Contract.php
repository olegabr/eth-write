<?php

/**
 * A contract at an address, addressed by function name or signature.
 *
 * @package olegabr/eth-write
 */

declare(strict_types=1);

namespace Ethereumico\EthWrite;

use JsonException;
use Ethereumico\EthAbi\Abi\Ethabi;
use Ethereumico\EthAbi\Problem;
use Ethereumico\EthRead\Abi;
use Ethereumico\EthRead\FunctionAbi;
use Throwable;

/**
 * A contract bound to an ABI, optionally to an address.
 *
 * The value this class adds is the whole trip of one call in one place: look the
 * function up by name or signature (overloads are refused, not guessed), encode
 * the arguments, make the RPC call, decode the answer against the ABI's outputs.
 * Nothing is signed here; {@see self::data()} returns the calldata a caller then
 * signs and submits through {@see Eth::sendRawTransaction()}.
 *
 * @since 0.1.0
 */
final class Contract
{
    private readonly Abi $abi;

    /**
     * @since 0.1.0
     *
     * @param array       $items   Decoded ABI items.
     * @param Client|null $client  RPC client, needed only for network calls.
     * @param string|null $address Bound address, if any.
     */
    public function __construct(
        private readonly array $items,
        private readonly ?Client $client = null,
        private readonly ?string $address = null,
    ) {
        $this->abi = Abi::fromArray($items);
    }

    /**
     * Build a contract from ABI JSON, as an explorer returns it.
     *
     * @since 0.1.0
     *
     * @param string      $json    ABI JSON.
     * @param Client|null $client  RPC client.
     * @param string|null $address Bound address.
     *
     * @return array{contract?:self,problem?:Problem}
     */
    public static function fromJson(string $json, ?Client $client = null, ?string $address = null): array
    {
        try {
            $items = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['problem' => new Problem('abi.invalid', ['json'])];
        }

        if (!is_array($items)) {
            return ['problem' => new Problem('abi.invalid', ['json'])];
        }

        return ['contract' => new self($items, $client, $address)];
    }

    /**
     * Bind an address, returning a contract that knows where it lives.
     *
     * @since 0.1.0
     *
     * @param string $address Contract address.
     *
     * @return self
     */
    public function at(string $address): self
    {
        return new self($this->items, $this->client, $address);
    }

    /**
     * Encode a function call into calldata (`0x` + selector + arguments).
     *
     * @since 0.1.0
     *
     * @param string $function Function name or signature.
     * @param array  $args     Argument values, in ABI order.
     *
     * @return array{data?:string,problem?:Problem}
     */
    public function data(string $function, array $args = []): array
    {
        $resolved = $this->function($function);

        if (isset($resolved['problem'])) {
            return $resolved;
        }

        $fn = $resolved['function'];
        $types = $fn->inputTypes();
        $values = array_values($args);

        if (count($types) !== count($values)) {
            return ['problem' => new Problem('abi.argument_count', [count($types), count($values)])];
        }

        try {
            $encoded = (new Ethabi())->encodeParameters($types, $values);
        } catch (Throwable $error) {
            return ['problem' => new Problem('abi.encode_failed', [$fn->signature(), $error->getMessage()])];
        }

        return ['data' => $fn->selector() . substr($encoded, 2)];
    }

    /**
     * Read a `view`/`pure` function and return its first output as a value.
     *
     * @since 0.1.0
     *
     * @param string $function Function name or signature.
     * @param array  $args     Argument values, in ABI order.
     * @param string $block    Block tag or number.
     *
     * @return array{value?:mixed,problem?:Problem}
     */
    public function call(string $function, array $args = [], string $block = 'latest'): array
    {
        if (null === $this->client || null === $this->address) {
            return ['problem' => new Problem('contract.unbound', ['call'])];
        }

        $resolved = $this->function($function);

        if (isset($resolved['problem'])) {
            return $resolved;
        }

        $fn = $resolved['function'];

        if (!$fn->isReadOnly()) {
            return ['problem' => new Problem('abi.not_read_only', [$fn->signature()])];
        }

        $encoded = $this->data($function, $args);

        if (isset($encoded['problem'])) {
            return $encoded;
        }

        $answer = $this->client->request(
            'eth_call',
            [['to' => $this->address, 'data' => $encoded['data']], $block]
        );

        if (isset($answer['problem'])) {
            return $answer;
        }

        $result = $answer['result'];

        if (!is_string($result) || '' === $result || '0x' === $result) {
            return ['problem' => new Problem('contract.empty_answer', [$this->address, $fn->signature()])];
        }

        $outputs = $fn->outputTypes();

        if ([] === $outputs) {
            return ['value' => ''];
        }

        try {
            $decoded = (new Ethabi())->decodeParameters($outputs, $result);
        } catch (Throwable $error) {
            return ['problem' => new Problem('abi.decode_failed', [$fn->signature(), $error->getMessage()])];
        }

        return ['value' => $this->nameOutputs($fn, $decoded)];
    }

    /**
     * Estimate gas for a state-changing call.
     *
     * @since 0.1.0
     *
     * @param string $function Function name or signature.
     * @param array  $args     Argument values, in ABI order.
     * @param array  $transaction Extra transaction fields (`from`, `value`, `gas`).
     *
     * @return array{value?:int,problem?:Problem}
     */
    public function estimateGas(string $function, array $args = [], array $transaction = []): array
    {
        if (null === $this->client || null === $this->address) {
            return ['problem' => new Problem('contract.unbound', ['estimateGas'])];
        }

        $encoded = $this->data($function, $args);

        if (isset($encoded['problem'])) {
            return $encoded;
        }

        $transaction['to'] = $this->address;
        $transaction['data'] = $encoded['data'];

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
     * @since 0.1.0
     *
     * @param string $nameOrSignature `balanceOf` or `balanceOf(address)`.
     *
     * @return array{function?:FunctionAbi,problem?:Problem}
     */
    private function function(string $nameOrSignature): array
    {
        return $this->abi->function($nameOrSignature);
    }

    /**
     * Decoded outputs, named when the ABI names them.
     *
     * @param FunctionAbi $function Function.
     * @param mixed       $decoded  Decoder answer.
     *
     * @return mixed
     */
    private function nameOutputs(FunctionAbi $function, mixed $decoded): mixed
    {
        if (!is_array($decoded)) {
            return $decoded;
        }

        $outputs = $function->outputs();

        if (1 === count($outputs)) {
            return $decoded[0] ?? null;
        }

        $named = [];

        foreach ($outputs as $index => $output) {
            $name = (string) ($output['name'] ?? '');
            $named['' !== $name ? $name : (string) $index] = $decoded[$index] ?? null;
        }

        return $named;
    }
}
