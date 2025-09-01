<?php

namespace ItHealer\LaravelBitcoin\Concerns;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Date;
use ItHealer\LaravelBitcoin\Facades\Bitcoin;
use ItHealer\LaravelBitcoin\Models\BitcoinAddress;
use ItHealer\LaravelBitcoin\Models\BitcoinTransfer;
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
            $amount = BigDecimal::of((string)$amount);
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

    /**
     * Предварительная отправка перевода, оценка возможности и комиссии.
     *
     * @param  BitcoinWallet  $wallet  Bitcoin кошелек
     * @param  BitcoinAddress|string  $fromAddress  Bitcoin адрес отправителя
     * @param  BitcoinAddress|string  $toAddress  Bitcoin адрес получателя
     * @param  int|float|string|BigDecimal|null  $amount  Сумма к переводу (null - весь баланс)
     * @param  bool  $subtractFeeFromOutputs  Комиссию платит получатель? (false)
     * @param  BitcoinAddress|string|null  $changeAddress  Адрес для сдачи (null - отправитель)
     * @return array
     * @throws \Exception
     */
    public function previewPSBT(
        BitcoinWallet $wallet,
        BitcoinAddress|string $fromAddress,
        BitcoinAddress|string $toAddress,
        int|float|string|BigDecimal|null $amount = null,
        bool $subtractFeeFromOutputs = false,
        BitcoinAddress|string|null $changeAddress = null,
    ): array {
        if ($fromAddress instanceof BitcoinAddress) {
            $fromAddress = $fromAddress->address;
        }

        if ($toAddress instanceof BitcoinAddress) {
            $toAddress = $toAddress->address;
        }

        if ($changeAddress instanceof BitcoinAddress) {
            $changeAddress = $changeAddress->address;
        }

        $api = $wallet->node->api();

        if ($wallet->plain_password) {
            $api->request('walletpassphrase', [
                'passphrase' => $wallet->plain_password,
                'timeout' => 60
            ], $wallet->name);
        }

        $listUnspent = $api->request('listunspent', ['minconf' => 0, 'addresses' => [$fromAddress]], $wallet->name);
        if (empty($listUnspent)) {
            throw new \Exception("На адресе $fromAddress нет доступного баланса, баланс: 0 BTC!");
        }
        usort($listUnspent, function ($a, $b) {
            return BigDecimal::of((string)$b['amount'])->compareTo($a['amount']);
        });

        $beforeBalance = BigDecimal::of(0);
        foreach( $listUnspent as $item ) {
            $beforeBalance = $beforeBalance->plus((string)$item['amount']);
        }

        if ($amount === null) {
            $subtractFeeFromOutputs = true;

            $amount = BigDecimal::of(0);
            foreach ($listUnspent as $item) {
                $amount = $amount->plus(BigDecimal::of((string)$item['amount']));
            }
        } elseif (!($amount instanceof BigDecimal)) {
            $amount = BigDecimal::of((string)$amount);
        }

        $funderOptions = [
            'add_inputs' => false,
            'replaceable' => true,
            'changeAddress' => $changeAddress ?? $fromAddress,
        ];
        if ($subtractFeeFromOutputs) {
            $funderOptions['subtractFeeFromOutputs'] = [0];
        }

        $candidates = array_values(
            array_filter(
                $listUnspent,
                fn($item) => $amount->isLessThanOrEqualTo((string)$item['amount'])
            )
        );
        usort($candidates, function ($a, $b) use ($amount) {
            $da = BigDecimal::of((string)$a['amount'])->minus($amount);
            $db = BigDecimal::of((string)$b['amount'])->minus($amount);
            return $da->compareTo($db);
        });

        foreach ($candidates as $u) {
            $inputs = [['txid' => $u['txid'], 'vout' => $u['vout']]];

            try {
                $createPSBT = $api->request('walletcreatefundedpsbt', [
                    'inputs' => $inputs,
                    'outputs' => [$toAddress => $amount->__toString()],
                    'options' => $funderOptions,
                ], $wallet->name);
                $processPSBT = $api->request('walletprocesspsbt', [
                    'psbt' => $createPSBT['psbt'],
                ], $wallet->name);
                $finalizePSBT = $api->request('finalizepsbt', [
                    'psbt' => $processPSBT['psbt'],
                    'extract' => true,
                ]);

                return [
                    'from' => $fromAddress,
                    'to' => $toAddress,
                    'amount' => $amount->__toString(),
                    'fee' => BigDecimal::of($createPSBT['fee'])->__toString(),
                    'beforeBalance' => $beforeBalance->__toString(),
                    'afterBalance' => $beforeBalance->minus($amount)->minus($createPSBT['fee'])->__toString(),
                    'inputs' => $inputs,
                    'hex' => $finalizePSBT['hex'],
                ];
            } catch (\Exception $e) {
                if (!preg_match('~(Insufficient funds|fee|not enough|amount does not)~i', $e->getMessage())) {
                    throw $e;
                }
            }
        }

        $inputs = [];
        $totalBalance = BigDecimal::of(0);
        foreach ($listUnspent as $u) {
            $totalBalance = $totalBalance->plus($u['amount']);
            $inputs[] = ['txid' => $u['txid'], 'vout' => $u['vout']];

            if ($totalBalance->isLessThan($amount)) {
                continue;
            }

            try {
                $createPSBT = $api->request('walletcreatefundedpsbt', [
                    'inputs' => $inputs,
                    'outputs' => [$toAddress => $amount->__toString()],
                    'options' => $funderOptions,
                ], $wallet->name);
                $processPSBT = $api->request('walletprocesspsbt', [
                    'psbt' => $createPSBT['psbt'],
                ], $wallet->name);
                $finalizePSBT = $api->request('finalizepsbt', [
                    'psbt' => $processPSBT['psbt'],
                    'extract' => true,
                ]);

                return [
                    'from' => $fromAddress,
                    'to' => $toAddress,
                    'amount' => $amount->__toString(),
                    'fee' => BigDecimal::of($createPSBT['fee'])->__toString(),
                    'inputs' => $inputs,
                    'hex' => $finalizePSBT['hex'],
                ];
            } catch (\Exception $e) {
                if (!preg_match('~(Insufficient funds|fee|not enough|amount does not)~i', $e->getMessage())) {
                    throw $e;
                }
            }
        }

        throw new \Exception("На адресе $fromAddress нет суммы $amount BTC, баланс: $totalBalance BTC!");
    }

    /**
     * Отправка перевода используя механизм PSBT.
     *
     * @param  BitcoinWallet  $wallet  Bitcoin кошелек
     * @param  BitcoinAddress|string  $fromAddress  Bitcoin адрес отправителя
     * @param  BitcoinAddress|string  $toAddress  Bitcoin адрес получателя
     * @param  int|float|string|BigDecimal|null  $amount  Сумма к переводу (null - весь баланс)
     * @param  bool  $subtractFeeFromOutputs  Комиссию платит получатель? (false)
     * @param  BitcoinAddress|string|null  $changeAddress  Адрес для сдачи (null - отправитель)
     * @param  string|null  $comment  Комментарий к переводу (не обязательно)
     * @return BitcoinTransfer Bitcoin перевод
     * @throws \Exception
     */
    public function sendPSBT(
        BitcoinWallet $wallet,
        BitcoinAddress|string $fromAddress,
        BitcoinAddress|string $toAddress,
        int|float|string|BigDecimal|null $amount = null,
        bool $subtractFeeFromOutputs = false,
        BitcoinAddress|string|null $changeAddress = null,
        string $comment = null,
    ): BitcoinTransfer {
        if (!($fromAddress instanceof BitcoinAddress)) {
            $fromAddress = $wallet->addresses()->whereAddress($fromAddress)->firstOrFail();
        }
        if ($changeAddress === null) {
            $changeAddress = $fromAddress;
        }
        if ($changeAddress instanceof BitcoinAddress) {
            $changeAddress = $changeAddress->address;
        }

        $previewData = $this->previewPSBT(
            $wallet,
            $fromAddress,
            $toAddress,
            $amount,
            $subtractFeeFromOutputs,
            $changeAddress
        );

        $api = $wallet->node->api();

        $sendRawTransaction = $api->request('sendrawtransaction', [
            'hexstring' => $previewData['hex'],
        ], $wallet->name);

        $txid = $sendRawTransaction['result'];

        $to = $wallet->addresses()->whereAddress($previewData['to'])->first();
        if ($to) {
            $model = Bitcoin::getModelDeposit();

            $model::create([
                'wallet_id' => $wallet->id,
                'address_id' => $to->id,
                'txid' => $txid,
                'amount' => $amount,
                'confirmations' => 0,
                'time_at' => Date::now()->utc(),
                'webhook_status' => 4,
            ]);
        }

        $change = $wallet->addresses()->whereAddress($changeAddress)->first();
        if ($change) {
            $model = Bitcoin::getModelDeposit();

            $model::create([
                'wallet_id' => $wallet->id,
                'address_id' => $change->id,
                'txid' => $txid,
                'amount' => 0,
                'confirmations' => 0,
                'time_at' => Date::now()->utc(),
                'webhook_status' => 5,
            ]);
        }

        $model = Bitcoin::getModelTransfer();
        $transfer = $model::create([
            'wallet_id' => $wallet->id,
            'address_id' => $fromAddress->id,
            'txid' => $txid,
            'receiver_address' => $previewData['to'],
            'amount' => $previewData['amount'],
            'comment' => $comment,
            'confirmations' => 0,
            'time_at' => Date::now()->utc(),
        ]);
        $transfer->setRelation('wallet', $wallet);
        $transfer->setRelation('address', $fromAddress);

        return $transfer;
    }

    public function previewMassPSBT(
        BitcoinWallet $wallet,
        BitcoinAddress|string $fromAddress,
        array $recipients,
        bool $subtractFeeFromOutputs = false,
        BitcoinAddress|string|null $changeAddress = null
    ): array {
        if ($fromAddress instanceof BitcoinAddress) {
            $fromAddress = $fromAddress->address;
        }

        $outputs = [];
        $totalAmount = BigDecimal::of(0);
        foreach ($recipients as $i => $item) {
            $address = $item['address'] ?? null;
            $amount = $item['amount'] ?? null;

            if (!$address) {
                throw new \Exception('У получателя #'.$i.' не задано поле address!');
            }
            if (!$amount) {
                throw new \Exception('У получателя #'.$i.' не задано поле amount!');
            }

            if (!($amount instanceof BigDecimal)) {
                $amount = BigDecimal::of((string)$amount);
            }

            if ($amount->isLessThanOrEqualTo(0)) {
                throw new \Exception('У получателя #'.$i.' сумма перевода <= 0!');
            }

            $outputs[$address] = $amount->__toString();
            $totalAmount = $totalAmount->plus($amount);

            $recipients[$i]['amount'] = $amount->__toString();
        }

        if ($changeAddress instanceof BitcoinAddress) {
            $changeAddress = $changeAddress->address;
        }

        $api = $wallet->node->api();

        if ($wallet->plain_password) {
            $api->request('walletpassphrase', [
                'passphrase' => $wallet->plain_password,
                'timeout' => 60
            ], $wallet->name);
        }

        $listUnspent = $api->request('listunspent', ['minconf' => 0, 'addresses' => [$fromAddress]], $wallet->name);
        if (empty($listUnspent)) {
            throw new \Exception("На адресе $fromAddress нет доступного баланса, баланс: 0 BTC!");
        }
        usort($listUnspent, function ($a, $b) {
            return BigDecimal::of((string)$b['amount'])->compareTo($a['amount']);
        });

        $beforeBalance = BigDecimal::of(0);
        foreach( $listUnspent as $item ) {
            $beforeBalance = $beforeBalance->plus((string)$item['amount']);
        }

        $funderOptions = [
            'add_inputs' => false,
            'replaceable' => true,
            'changeAddress' => $changeAddress ?? $fromAddress,
        ];
        if ($subtractFeeFromOutputs) {
            $funderOptions['subtractFeeFromOutputs'] = [0];
        }

        $inputs = [];
        $totalBalance = BigDecimal::of(0);
        foreach ($listUnspent as $u) {
            $totalBalance = $totalBalance->plus($u['amount']);
            $inputs[] = ['txid' => $u['txid'], 'vout' => $u['vout']];
            if ($totalBalance->isLessThan($totalAmount)) {
                continue;
            }

            try {
                $createPSBT = $api->request('walletcreatefundedpsbt', [
                    'inputs' => $inputs,
                    'outputs' => $outputs,
                    'options' => $funderOptions,
                ], $wallet->name);
                $processPSBT = $api->request('walletprocesspsbt', [
                    'psbt' => $createPSBT['psbt'],
                ], $wallet->name);
                $finalizePSBT = $api->request('finalizepsbt', [
                    'psbt' => $processPSBT['psbt'],
                    'extract' => true,
                ]);

                return [
                    'from' => $fromAddress,
                    'recipients' => $recipients,
                    'totalAmount' => $totalAmount->__toString(),
                    'fee' => BigDecimal::of($createPSBT['fee'])->__toString(),
                    'beforeBalance' => $beforeBalance->__toString(),
                    'afterBalance' => $beforeBalance->minus($totalAmount)->minus($createPSBT['fee'])->__toString(),
                    'inputs' => $inputs,
                    'hex' => $finalizePSBT['hex'],
                ];
            } catch (\Exception $e) {
                if (!preg_match('~(Insufficient funds|fee|not enough|amount does not)~i', $e->getMessage())) {
                    throw $e;
                }
            }
        }

        throw new \Exception("На адресе $fromAddress нет суммы $totalAmount BTC, баланс: $totalBalance BTC!");
    }

    public function sendMassPSBT(
        BitcoinWallet $wallet,
        BitcoinAddress|string $fromAddress,
        array $recipients,
        bool $subtractFeeFromOutputs = false,
        BitcoinAddress|string|null $changeAddress = null,
        ?array &$preview = null
    ): array {
        if (!($fromAddress instanceof BitcoinAddress)) {
            $fromAddress = $wallet->addresses()->whereAddress($fromAddress)->firstOrFail();
        }
        if ($changeAddress === null) {
            $changeAddress = $fromAddress;
        }
        if ($changeAddress instanceof BitcoinAddress) {
            $changeAddress = $changeAddress->address;
        }

        $preview = $this->previewMassPSBT(
            $wallet,
            $fromAddress,
            $recipients,
            $subtractFeeFromOutputs,
            $changeAddress
        );

        $api = $wallet->node->api();

        $sendRawTransaction = $api->request('sendrawtransaction', [
            'hexstring' => $preview['hex'],
        ], $wallet->name);

        $txid = $sendRawTransaction['result'];

        $model = Bitcoin::getModelDeposit();
        foreach ($preview['recipients'] as $item) {
            $to = $wallet->addresses()->whereAddress($item['address'])->first();
            if ($to) {
                $model::create([
                    'wallet_id' => $wallet->id,
                    'address_id' => $to->id,
                    'txid' => $txid,
                    'amount' => $item['amount'],
                    'confirmations' => 0,
                    'time_at' => Date::now()->utc(),
                    'webhook_status' => 4,
                ]);
            }
        }

        $change = $wallet->addresses()->whereAddress($changeAddress)->first();
        if ($change) {
            $model = Bitcoin::getModelDeposit();

            $model::create([
                'wallet_id' => $wallet->id,
                'address_id' => $change->id,
                'txid' => $txid,
                'amount' => 0,
                'confirmations' => 0,
                'time_at' => Date::now()->utc(),
                'webhook_status' => 5,
            ]);
        }

        $model = Bitcoin::getModelTransfer();

        $transfers = [];

        foreach ($preview['recipients'] as $item) {
            $transfer = $model::create([
                'wallet_id' => $wallet->id,
                'address_id' => $fromAddress->id,
                'txid' => $txid,
                'receiver_address' => $item['address'],
                'amount' => $item['amount'],
                'comment' => $item['comment'] ?? null,
                'confirmations' => 0,
                'time_at' => Date::now()->utc(),
            ]);
            $transfer->setRelation('wallet', $wallet);
            $transfer->setRelation('address', $fromAddress);

            $transfers[] = $transfer;
        }

        return $transfers;
    }
}