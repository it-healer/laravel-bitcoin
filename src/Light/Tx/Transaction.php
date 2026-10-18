<?php

namespace ItHealer\LaravelBitcoin\Light\Tx;

/**
 * A Bitcoin transaction: serialization (legacy and segwit), parsing, txid, and the BIP143
 * signature hash of P2WPKH inputs (used to check signatures independently of the signer).
 *
 * Inputs: ['txid' => hex (display order), 'vout' => int, 'sequence' => int, 'witness' => list<bin>]
 * Outputs: ['value' => int satoshi, 'script' => bin]
 */
class Transaction
{
    /**
     * @param list<array{txid: string, vout: int, sequence: int, witness?: list<string>}> $inputs
     * @param list<array{value: int, script: string}> $outputs
     */
    public function __construct(
        public array $inputs = [],
        public array $outputs = [],
        public int $version = 2,
        public int $locktime = 0,
    ) {
    }

    public static function fromHex(string $hex): self
    {
        if (!ctype_xdigit($hex) || strlen($hex) % 2 !== 0 || strlen($hex) > 2_000_000) {
            throw new \InvalidArgumentException('Invalid transaction hex.');
        }

        $reader = new ByteReader(hex2bin($hex));
        $tx = new self(version: $reader->uint32());

        $segwit = false;
        if ($reader->peek(2) === "\x00\x01") {
            $reader->read(2);
            $segwit = true;
        }

        for ($i = 0, $count = $reader->varInt(); $i < $count; $i++) {
            $tx->inputs[] = [
                'txid' => bin2hex(strrev($reader->read(32))),
                'vout' => $reader->uint32(),
                'script_sig' => $reader->read($reader->varInt()),
                'sequence' => $reader->uint32(),
                'witness' => [],
            ];
        }

        for ($i = 0, $count = $reader->varInt(); $i < $count; $i++) {
            $tx->outputs[] = ['value' => $reader->uint64(), 'script' => $reader->read($reader->varInt())];
        }

        if ($segwit) {
            foreach ($tx->inputs as $index => $input) {
                $items = [];
                for ($j = 0, $count = $reader->varInt(); $j < $count; $j++) {
                    $items[] = $reader->read($reader->varInt());
                }
                $tx->inputs[$index]['witness'] = $items;
            }
        }

        $tx->locktime = $reader->uint32();

        if (!$reader->eof()) {
            throw new \InvalidArgumentException('Trailing bytes after the transaction.');
        }

        return $tx;
    }

    /**
     * Serialization without witnesses: what the txid is computed from and what a PSBT holds.
     */
    public function serializeLegacy(): string
    {
        $data = pack('V', $this->version).self::varInt(count($this->inputs));

        foreach ($this->inputs as $input) {
            $scriptSig = $input['script_sig'] ?? '';
            $data .= strrev(hex2bin($input['txid'])).pack('V', $input['vout']).self::varInt(strlen($scriptSig)).$scriptSig.pack('V', $input['sequence']);
        }

        $data .= self::varInt(count($this->outputs));

        foreach ($this->outputs as $output) {
            $data .= pack('P', $output['value']).self::varInt(strlen($output['script'])).$output['script'];
        }

        return $data.pack('V', $this->locktime);
    }

    public function txid(): string
    {
        return bin2hex(strrev(hash('sha256', hash('sha256', $this->serializeLegacy(), true), true)));
    }

    /**
     * BIP143 signature hash (SIGHASH_ALL) of a P2WPKH input spending `$value` satoshi of the
     * key with this HASH160.
     */
    public function p2wpkhSighash(int $index, string $pubkeyHash, int $value): string
    {
        $dsha = fn (string $data): string => hash('sha256', hash('sha256', $data, true), true);

        $prevouts = $sequences = $outputs = '';
        foreach ($this->inputs as $input) {
            $prevouts .= strrev(hex2bin($input['txid'])).pack('V', $input['vout']);
            $sequences .= pack('V', $input['sequence']);
        }
        foreach ($this->outputs as $output) {
            $outputs .= pack('P', $output['value']).self::varInt(strlen($output['script'])).$output['script'];
        }

        $input = $this->inputs[$index];
        $scriptCode = "\x19\x76\xa9\x14".$pubkeyHash."\x88\xac";

        return $dsha(
            pack('V', $this->version).$dsha($prevouts).$dsha($sequences)
            .strrev(hex2bin($input['txid'])).pack('V', $input['vout'])
            .$scriptCode.pack('P', $value).pack('V', $input['sequence'])
            .$dsha($outputs).pack('V', $this->locktime).pack('V', 1)
        );
    }

    public static function varInt(int $value): string
    {
        return match (true) {
            $value < 0xfd => chr($value),
            $value <= 0xffff => "\xfd".pack('v', $value),
            $value <= 0xffffffff => "\xfe".pack('V', $value),
            default => "\xff".pack('P', $value),
        };
    }
}
