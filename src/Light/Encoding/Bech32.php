<?php

namespace ItHealer\LaravelBitcoin\Light\Encoding;

/**
 * Bech32 (BIP173) and Bech32m (BIP350) segwit addresses: v0 (P2WPKH/P2WSH) use Bech32,
 * v1+ (Taproot) use Bech32m.
 */
class Bech32
{
    protected const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

    protected const BECH32_CONST = 1;

    protected const BECH32M_CONST = 0x2bc830a3;

    /**
     * @return array{0: int, 1: string} witness version and program
     */
    public static function decodeSegwit(string $hrp, string $address): array
    {
        [$decodedHrp, $data, $constant] = self::decode($address);

        if ($decodedHrp !== $hrp || count($data) < 1) {
            throw new \InvalidArgumentException('Invalid segwit address prefix.');
        }

        $version = $data[0];
        $program = self::convertBits(array_slice($data, 1), 5, 8, false);
        $length = count($program);

        if ($version > 16 || $length < 2 || $length > 40) {
            throw new \InvalidArgumentException('Invalid segwit program.');
        }

        if ($version === 0 && $length !== 20 && $length !== 32) {
            throw new \InvalidArgumentException('Invalid segwit v0 program length.');
        }

        if (($version === 0) !== ($constant === self::BECH32_CONST)) {
            throw new \InvalidArgumentException('Wrong checksum variant for the witness version.');
        }

        return [$version, implode('', array_map('chr', $program))];
    }

    public static function encodeSegwit(string $hrp, int $version, string $program): string
    {
        $data = [$version, ...self::convertBits(array_values(unpack('C*', $program)), 8, 5, true)];

        return self::encode($hrp, $data, $version === 0 ? self::BECH32_CONST : self::BECH32M_CONST);
    }

    /**
     * @param list<int> $data
     */
    protected static function encode(string $hrp, array $data, int $constant): string
    {
        $values = [...self::hrpExpand($hrp), ...$data, 0, 0, 0, 0, 0, 0];
        $polymod = self::polymod($values) ^ $constant;
        $encoded = $hrp.'1';

        foreach ($data as $value) {
            $encoded .= self::CHARSET[$value];
        }

        for ($i = 0; $i < 6; $i++) {
            $encoded .= self::CHARSET[($polymod >> (5 * (5 - $i))) & 31];
        }

        return $encoded;
    }

    /**
     * @return array{0: string, 1: list<int>, 2: int} hrp, data without checksum, checksum constant
     */
    protected static function decode(string $address): array
    {
        if (strlen($address) > 90 || (strtolower($address) !== $address && strtoupper($address) !== $address)) {
            throw new \InvalidArgumentException('Invalid bech32 string.');
        }

        $address = strtolower($address);
        $separator = strrpos($address, '1');

        if ($separator === false || $separator < 1 || $separator + 7 > strlen($address)) {
            throw new \InvalidArgumentException('Invalid bech32 separator position.');
        }

        $hrp = substr($address, 0, $separator);
        $data = [];

        foreach (str_split(substr($address, $separator + 1)) as $char) {
            $value = strpos(self::CHARSET, $char);

            if ($value === false) {
                throw new \InvalidArgumentException('Invalid bech32 character.');
            }

            $data[] = $value;
        }

        $constant = self::polymod([...self::hrpExpand($hrp), ...$data]);

        if ($constant !== self::BECH32_CONST && $constant !== self::BECH32M_CONST) {
            throw new \InvalidArgumentException('Invalid bech32 checksum.');
        }

        return [$hrp, array_slice($data, 0, -6), $constant];
    }

    /**
     * @return list<int>
     */
    protected static function hrpExpand(string $hrp): array
    {
        $high = $low = [];

        foreach (str_split($hrp) as $char) {
            $high[] = ord($char) >> 5;
            $low[] = ord($char) & 31;
        }

        return [...$high, 0, ...$low];
    }

    /**
     * @param list<int> $values
     */
    protected static function polymod(array $values): int
    {
        $generators = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
        $checksum = 1;

        foreach ($values as $value) {
            $top = $checksum >> 25;
            $checksum = (($checksum & 0x1ffffff) << 5) ^ $value;

            for ($i = 0; $i < 5; $i++) {
                if (($top >> $i) & 1) {
                    $checksum ^= $generators[$i];
                }
            }
        }

        return $checksum;
    }

    /**
     * @param list<int> $data
     * @return list<int>
     */
    protected static function convertBits(array $data, int $from, int $to, bool $pad): array
    {
        $accumulator = 0;
        $bits = 0;
        $result = [];
        $maxValue = (1 << $to) - 1;

        foreach ($data as $value) {
            if ($value < 0 || $value >> $from) {
                throw new \InvalidArgumentException('Invalid value for bit conversion.');
            }

            $accumulator = ($accumulator << $from) | $value;
            $bits += $from;

            while ($bits >= $to) {
                $bits -= $to;
                $result[] = ($accumulator >> $bits) & $maxValue;
            }
        }

        if ($pad) {
            if ($bits > 0) {
                $result[] = ($accumulator << ($to - $bits)) & $maxValue;
            }
        } elseif ($bits >= $from || (($accumulator << ($to - $bits)) & $maxValue)) {
            throw new \InvalidArgumentException('Invalid padding in bit conversion.');
        }

        return $result;
    }
}
