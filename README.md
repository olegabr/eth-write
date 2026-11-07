# olegabr/eth-write

The write half of our EVM JSON-RPC stack, cut out of `olegabr/web3.php` by the
same rule that produced `olegabr/eth-read`: **only the surface our consumers
actually call** enters the package, and only that surface.

* Namespace — `Ethereumico\EthWrite\` (ADR-0033).
* PHP floor — `>=8.1`, inherited from `eth-read`: a plugin on 7.2/7.4 cannot
  take this package, and that is a product consequence, not a build detail.
* No keys, no signing, no HTTP client of its own. A raw transaction arrives
  already signed (by `olegabr/ethereum-tx` or a `wallet-chains-*` adapter); the
  transport is the caller's (a WordPress site passes a `wp_remote_post` adapter).

## Why it exists

`olegabr/web3.php` is a fork that carries Guzzle, phpseclib, a WebSocket
transport and a bag of `Methods/*` classes nobody calls. ADR-0032 replaces it,
like ADR-0024 replaced the read half, with a package that holds exactly the write
operations the plugins use:

| call site (grep over consumers) | this package |
|---|---|
| `new Contract($provider, $abi)` + `->at($addr)->call($fn, …)` | `Contract` |
| `$eth->sendRawTransaction($signed, …)` | `Eth::sendRawTransaction()` |
| `$eth->sendTransaction($tx, …)` | `Eth::sendTransaction()` |
| `$eth->estimateGas($tx, …)` | `Eth::estimateGas()` |
| `$eth->call($tx, …, …)` | `Eth::call()` |
| `new HttpProvider($url, $timeout)` | the caller's `Olegabr\EthRead\Transport` |

Everything else in `web3.php` — WS, `Shh`, batch, the per-method classes, the
formatters and validators — is not ported. A method that no consumer calls is a
method that does not ship.

## Design

```php
use Ethereumico\EthWrite\Client;
use Ethereumico\EthWrite\Eth;
use Ethereumico\EthWrite\Contract;
use Olegabr\EthRead\Transport;

$client = new Client('https://rpc.example', $transport /* Transport */, timeout_seconds: 10);
$eth    = new Eth($client);

// send an already-signed transaction
$sent = $eth->sendRawTransaction('0x…');
if (isset($sent['problem'])) { /* translate $sent['problem']->code() */ }

// wait for it, with a deadline (a page render must not hang)
$receipt = $eth->waitForReceipt($sent['result'], timeout_seconds: 120);

// read a view function
$token = Contract::fromJson($erc20AbiJson, $client, $tokenAddress)['contract'];
$decimals = $token->call('decimals');      // ['value' => '18']

// build calldata for a call you will sign and submit yourself
$data = $token->data('transfer', [$to, $amount])['data'];
```

Three rules, the same three as `eth-read`:

1. **Results are values.** A method returns `['result' => …]` or
   `['value' => …]` on success and `['problem' => Problem]` otherwise. The
   library never builds a sentence — the caller translates `Problem::code()` and
   `Problem::params()`.
2. **Every call has a deadline.** The timeout is required on the `Client`
   constructor and passed to the transport on every request; `waitForReceipt()`
   is bounded too.
3. **Only the listed methods exist.** `Client::ALLOWED_METHODS` is closed; a
   method outside it is refused before any request is made.

## Refusal codes added here

On top of the `abi.*`, `rpc.*` and `revert.*` codes that `eth-abi`/`eth-read`
already produce:

| code | params | meaning |
|---|---|---|
| `tx.no_hash` | `[]` | the node accepted a send but returned no transaction hash |
| `tx.not_mined` | `[hash, seconds]` | the receipt did not appear before the deadline |
| `contract.unbound` | `[operation]` | a network call on a contract with no client/address |
| `rpc.unexpected_result` | `[method]` | a method returned a JSON type its decoder did not expect |

## Tests and CI

```bash
composer install
composer test
```

The suite runs offline against a `FakeTransport`; nothing opens a socket, and
the deadline test is bounded (no silent waits).

## Status

`0.1.0`. First consumer to migrate is `EPGW` (owner decision U1-A). Until the
seven consumers move over, `olegabr/web3.php` remains the donor and the
transitional package.
