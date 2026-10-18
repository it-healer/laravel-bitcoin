<?php

namespace ItHealer\LaravelBitcoin\Light\Psbt;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use ItHealer\LaravelBitcoin\Light\Tx\Script;
use ItHealer\LaravelBitcoin\Light\Tx\Transaction;

/**
 * Builds an unsigned transaction of a light wallet and its PSBT.
 *
 * Inputs are taken largest first until the outputs and the fee are covered. Outputs keep the
 * order of the old node mode: recipients, change, then OP_RETURN. A change below the dust
 * limit goes to the fee. With `subtractFeeFromFirst` the first recipient pays the fee.
 *
 * Virtual size: 10.5 vB of header, 68 vB per P2WPKH input (the largest signature), outputs by
 * their script. All inputs are RBF-signalling (sequence 0xfffffffd).
 */
class PsbtBuilder
{
    public const SEQUENCE_RBF = 0xfffffffd;

    protected const INPUT_VSIZE = 68;

    protected const HEADER_VSIZE = 10.5;

    /**
     * @param list<array{txid: string, vout: int, value: int, script: string, path: string}> $utxos
     * @param list<array{address: string, amount: string|BigDecimal}> $recipients
     * @return array{
     *     inputs: list<array{txid: string, vout: int, value: int, script: string, path: string}>,
     *     outputs: list<array{value: int, script: string, address: ?string, role: string}>,
     *     fee: int, vsize: int, fee_rate: float, txid: string, psbt: string
     * }
     */
    public static function build(
        array $utxos,
        array $recipients,
        float $feeRate,
        string $changeAddress,
        bool $subtractFeeFromFirst = false,
        ?string $data = null,
    ): array {
        if ($recipients === []) {
            throw new \InvalidArgumentException('No recipients.');
        }

        $outputs = [];
        foreach ($recipients as $i => $recipient) {
            $parsed = Script::parse((string)$recipient['address']);
            $value = self::satoshi($recipient['amount']);

            if ($value <= 0) {
                throw new \InvalidArgumentException('Recipient #'.$i.': amount must be positive.');
            }

            $outputs[] = ['value' => $value, 'script' => $parsed['script'], 'address' => $recipient['address'], 'role' => 'recipient', 'kind' => $parsed['kind']];
        }

        $dataOutput = $data !== null ? ['value' => 0, 'script' => Script::opReturn($data), 'address' => null, 'role' => 'data', 'kind' => 'data'] : null;
        $change = Script::parse($changeAddress);

        $total = array_sum(array_column($outputs, 'value'));
        $baseVsize = self::HEADER_VSIZE + array_sum(array_map(fn ($o) => Script::outputVsize($o['script']), $outputs))
            + ($dataOutput ? Script::outputVsize($dataOutput['script']) : 0);
        $changeVsize = Script::outputVsize($change['script']);

        usort($utxos, fn ($a, $b) => $b['value'] <=> $a['value']);

        $selected = [];
        $inputSum = 0;

        foreach ($utxos as $utxo) {
            $selected[] = $utxo;
            $inputSum += $utxo['value'];

            $inputsVsize = count($selected) * self::INPUT_VSIZE;
            $feeWithChange = (int)ceil($feeRate * (int)ceil($baseVsize + $inputsVsize + $changeVsize));
            $feeNoChange = (int)ceil($feeRate * (int)ceil($baseVsize + $inputsVsize));

            $plan = $subtractFeeFromFirst
                ? self::planSubtracting($inputSum, $total, $feeWithChange, $feeNoChange, $change)
                : self::planAdding($inputSum, $total, $feeWithChange, $feeNoChange, $change);

            if ($plan === null) {
                continue;
            }

            [$subtract, $changeValue] = $plan;
            $final = $outputs;

            if ($subtract > 0) {
                $final[0]['value'] -= $subtract;

                if ($final[0]['value'] < Script::dust($final[0]['kind'])) {
                    throw new \RuntimeException('The amount does not cover the network fee.');
                }
            }

            foreach ($final as $i => $output) {
                if ($output['value'] < Script::dust($output['kind'])) {
                    throw new \InvalidArgumentException('Recipient #'.$i.': amount is below the dust limit ('.Script::dust($output['kind']).' sat).');
                }
            }

            if ($changeValue > 0) {
                $final[] = ['value' => $changeValue, 'script' => $change['script'], 'address' => $changeAddress, 'role' => 'change', 'kind' => $change['kind']];
            }

            if ($dataOutput) {
                $final[] = $dataOutput;
            }

            $final = array_map(fn ($o) => array_diff_key($o, ['kind' => true]), $final);
            $vsize = (int)ceil($baseVsize + count($selected) * self::INPUT_VSIZE + ($changeValue > 0 ? $changeVsize : 0));

            $tx = new Transaction(
                inputs: array_map(fn ($u) => ['txid' => $u['txid'], 'vout' => $u['vout'], 'sequence' => self::SEQUENCE_RBF], $selected),
                outputs: array_map(fn ($o) => ['value' => $o['value'], 'script' => $o['script']], $final),
            );

            return [
                'inputs' => $selected,
                'outputs' => $final,
                'fee' => $inputSum - array_sum(array_column($final, 'value')),
                'vsize' => $vsize,
                'fee_rate' => $feeRate,
                'txid' => $tx->txid(),
                'psbt' => base64_encode(self::psbt($tx, $selected)),
            ];
        }

        $available = BigDecimal::ofUnscaledValue($inputSum, 8);

        throw new \RuntimeException('Not enough funds: available '.$available.' BTC, needed '.BigDecimal::ofUnscaledValue($total, 8).' BTC plus the network fee.');
    }

    /**
     * The sender pays the fee on top of the outputs.
     *
     * @return array{0: int, 1: int}|null amount taken from the first output (0) and change,
     *                                    null when the inputs do not cover it
     */
    protected static function planAdding(int $inputSum, int $total, int $feeWithChange, int $feeNoChange, array $change): ?array
    {
        $changeValue = $inputSum - $total - $feeWithChange;

        if ($changeValue >= Script::dust($change['kind'])) {
            return [0, $changeValue];
        }

        // Change below dust (or none): what is left over goes to the fee
        return $inputSum >= $total + $feeNoChange ? [0, 0] : null;
    }

    /**
     * The first recipient pays the fee: it gets its amount minus what the fee needs.
     *
     * @return array{0: int, 1: int}|null amount taken from the first output and change
     */
    protected static function planSubtracting(int $inputSum, int $total, int $feeWithChange, int $feeNoChange, array $change): ?array
    {
        if ($inputSum < $total) {
            return null;
        }

        $changeValue = $inputSum - $total;

        if ($changeValue >= Script::dust($change['kind'])) {
            return [$feeWithChange, $changeValue];
        }

        // The rest below dust goes to the fee too, so the recipient pays only what is missing
        return [max($feeNoChange - $changeValue, 0), 0];
    }

    /**
     * PSBT v0 (BIP174): the unsigned transaction and the spent output of every input.
     *
     * @param list<array{value: int, script: string}> $inputs
     */
    public static function psbt(Transaction $tx, array $inputs): string
    {
        $pair = fn (string $key, string $value): string => Transaction::varInt(strlen($key)).$key.Transaction::varInt(strlen($value)).$value;

        $psbt = "psbt\xff".$pair("\x00", $tx->serializeLegacy())."\x00";

        foreach ($inputs as $input) {
            $witnessUtxo = pack('P', $input['value']).Transaction::varInt(strlen($input['script'])).$input['script'];
            $psbt .= $pair("\x01", $witnessUtxo)."\x00";
        }

        return $psbt.str_repeat("\x00", count($tx->outputs));
    }

    public static function satoshi(string|int|float|BigDecimal $amount): int
    {
        $value = BigDecimal::of((string)$amount);

        if ($value->getScale() > 8 && !$value->toScale(8, RoundingMode::Down)->isEqualTo($value)) {
            throw new \InvalidArgumentException('Bitcoin amounts have at most 8 decimals.');
        }

        return $value->toScale(8, RoundingMode::Unnecessary)->getUnscaledValue()->toInt();
    }
}
