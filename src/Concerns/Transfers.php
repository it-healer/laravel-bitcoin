<?php

namespace ItHealer\LaravelBitcoin\Concerns;

use Brick\Math\BigDecimal;
use ItHealer\LaravelBitcoin\Models\BitcoinWallet;

trait Transfers
{
    public function sendAll(BitcoinWallet $wallet, string $address, int|float|null $feeRate = null): string
    {
        $api = $wallet->node->api();

        if ($wallet->plain_password) {
            $api->request('walletpassphrase', [
                'passphrase' => $wallet->plain_password,
                'timeout' => 60
            ], $wallet->name);
        }

        $sendAll = $api->request('sendall', [
            'recipients' => [$address],
            'estimate_mode' => $feeRate ? 'unset' : 'economical',
            'fee_rate' => $feeRate,
            'options' => [
                'send_max' => true,
            ]
        ], $wallet->name);

        if (!($sendAll['complete'] ?? false)) {
            throw new \Exception(json_encode($sendAll));
        }

        return $sendAll['txid'];
    }

    public function send(
        BitcoinWallet $wallet,
        string $address,
        int|float|string|BigDecimal $amount,
        int|float|null $feeRate = null,
        bool $subtractFeeFromAmount = false
    ): string {
        $api = $wallet->node->api();

        if (($amount instanceof BigDecimal)) {
            $amount = BigDecimal::ofUnscaledValue((string)$amount, 8);
        }

        if ($wallet->plain_password) {
            $api->request('walletpassphrase', [
                'passphrase' => $wallet->plain_password,
                'timeout' => 60
            ], $wallet->name);
        }

        $sendToAddress = $api->request('sendtoaddress', [
            'address' => $address,
            'amount' => $amount->__toString(),
            'subtractfeefromamount' => $subtractFeeFromAmount,
            'estimate_mode' => $feeRate ? 'unset' : 'economical',
            'fee_rate' => $feeRate
        ], $wallet->name);

        if (!is_string($sendToAddress['result'])) {
            throw new \Exception(json_encode($sendToAddress));
        }

        return $sendToAddress['result'];
    }
}