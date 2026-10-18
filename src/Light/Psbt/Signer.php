<?php

namespace ItHealer\LaravelBitcoin\Light\Psbt;

use Elliptic\EC;
use Illuminate\Support\Facades\Process;
use ItHealer\LaravelBitcoin\Light\Keys\ExtendedKey;
use ItHealer\LaravelBitcoin\Light\Tx\Transaction;

/**
 * Signs a planned transaction with the bundled Node signer (signer/dist/sign.mjs, keys from
 * the BIP39 seed passed through stdin) and checks the result independently before it can be
 * broadcast: same inputs and outputs as planned, same txid, and every input carries a valid
 * BIP143 signature of the key its P2WPKH output pays to.
 */
class Signer
{
    /**
     * @param array{inputs: list<array{txid: string, vout: int, value: int, script: string, path: string}>, outputs: list<array{value: int, script: string}>, txid: string, psbt: string} $plan
     * @return array{hex: string, txid: string}
     */
    public static function sign(array $plan, #[\SensitiveParameter] string $seedHex): array
    {
        $result = Process::timeout(30)
            ->input(json_encode([
                'psbt' => $plan['psbt'],
                'seed' => $seedHex,
                'inputs' => array_map(fn ($input, $index) => ['index' => $index, 'path' => $input['path']], $plan['inputs'], array_keys($plan['inputs'])),
            ]))
            ->run(self::command());

        $answer = json_decode($result->output(), true);

        if (!is_array($answer) || !($answer['ok'] ?? false)) {
            throw new \RuntimeException('Signing failed: '.(is_array($answer) ? ($answer['error'] ?? 'unknown error') : trim($result->errorOutput() ?: $result->output())));
        }

        self::verify($plan, (string)$answer['hex']);

        return ['hex' => (string)$answer['hex'], 'txid' => (string)$answer['txid']];
    }

    /**
     * @throws \RuntimeException when the signed transaction is not exactly the planned one
     */
    public static function verify(array $plan, string $hex): void
    {
        $tx = Transaction::fromHex($hex);

        if ($tx->txid() !== $plan['txid']) {
            throw new \RuntimeException('Signed transaction differs from the planned one (txid).');
        }

        if (count($tx->inputs) !== count($plan['inputs']) || count($tx->outputs) !== count($plan['outputs'])) {
            throw new \RuntimeException('Signed transaction differs from the planned one (inputs/outputs).');
        }

        foreach ($plan['outputs'] as $i => $output) {
            if ($tx->outputs[$i]['value'] !== $output['value'] || $tx->outputs[$i]['script'] !== $output['script']) {
                throw new \RuntimeException('Signed transaction differs from the planned one (output '.$i.').');
            }
        }

        $curve = new EC('secp256k1');

        foreach ($plan['inputs'] as $i => $input) {
            $txInput = $tx->inputs[$i];
            [$signature, $pubkey] = $txInput['witness'] + [null, null];

            if ($txInput['txid'] !== $input['txid'] || $txInput['vout'] !== $input['vout'] || count($txInput['witness']) !== 2) {
                throw new \RuntimeException('Signed transaction differs from the planned one (input '.$i.').');
            }

            $pubkeyHash = ExtendedKey::hash160((string)$pubkey);

            if ("\x00\x14".$pubkeyHash !== $input['script']) {
                throw new \RuntimeException('Input '.$i.' is signed by a key that does not own it.');
            }

            if (substr((string)$signature, -1) !== "\x01") {
                throw new \RuntimeException('Input '.$i.' is not signed with SIGHASH_ALL.');
            }

            $sighash = $tx->p2wpkhSighash($i, $pubkeyHash, $input['value']);
            $key = $curve->keyFromPublic(bin2hex((string)$pubkey), 'hex');

            if (!$key->verify(bin2hex($sighash), bin2hex(substr((string)$signature, 0, -1)))) {
                throw new \RuntimeException('Input '.$i.' has an invalid signature.');
            }
        }
    }

    /**
     * @return list<string>
     */
    protected static function command(): array
    {
        $configured = config('bitcoin.light.signer_command');

        if (is_array($configured) && $configured !== []) {
            return array_values($configured);
        }

        return ['node', dirname(__DIR__, 3).'/signer/dist/sign.mjs'];
    }
}
