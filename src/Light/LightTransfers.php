<?php

namespace ItHealer\LaravelBitcoin\Light;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;
use ItHealer\LaravelBitcoin\Facades\Bitcoin;
use ItHealer\LaravelBitcoin\Light\Api\EsploraApi;
use ItHealer\LaravelBitcoin\Light\Api\ProviderPool;
use ItHealer\LaravelBitcoin\Light\Psbt\PsbtBuilder;
use ItHealer\LaravelBitcoin\Light\Psbt\Signer;
use ItHealer\LaravelBitcoin\Light\Tx\Script;
use ItHealer\LaravelBitcoin\Light\Tx\Transaction;
use ItHealer\LaravelBitcoin\Models\BitcoinAddress;
use ItHealer\LaravelBitcoin\Models\BitcoinTransfer;
use ItHealer\LaravelBitcoin\Models\BitcoinWallet;

/**
 * Sending from a light wallet, with the same semantics as the node mode: the coins of one
 * address are spent, the change returns to that address unless another one is given.
 *
 * Spendable: confirmed outputs and unconfirmed outputs of the wallet's own transactions
 * (change); outputs just spent by this wallet are reserved for `reserve_hours` so a second
 * send cannot pick them before the providers see the first one.
 */
class LightTransfers
{
    public function __construct(protected readonly BitcoinWallet $wallet)
    {
    }

    /**
     * @param list<array{address: string, amount: string|int|float|BigDecimal, comment?: ?string}> $recipients
     * @return array{from: string, recipients: list<array>, totalAmount: string, fee: string, feeRate: float, vsize: int, beforeBalance: string, afterBalance: string, inputs: list<array{txid: string, vout: int}>, hex: null, plan: array}
     */
    public function preview(
        BitcoinAddress|string $from,
        array $recipients,
        bool $subtractFeeFromOutputs = false,
        BitcoinAddress|string|null $changeAddress = null,
        ?float $feeRate = null,
        ?string $data = null,
    ): array {
        $from = $this->address($from);
        $utxos = $this->spendableUtxos($from);
        $changeAddress = $changeAddress instanceof BitcoinAddress ? $changeAddress->address : ($changeAddress ?? $from->address);

        if ($data !== null && (!ctype_xdigit($data) || strlen($data) % 2 !== 0 || strlen($data) > 160)) {
            throw new \InvalidArgumentException('OP_RETURN data must be hex, up to 80 bytes');
        }

        if ($utxos === []) {
            throw new \RuntimeException('На адресе '.$from->address.' нет доступного баланса, баланс: 0 BTC!');
        }

        $plan = PsbtBuilder::build(
            utxos: $utxos,
            recipients: array_map(fn ($r) => ['address' => $r['address'], 'amount' => $r['amount']], array_values($recipients)),
            feeRate: $this->feeRate($feeRate),
            changeAddress: $changeAddress,
            subtractFeeFromFirst: $subtractFeeFromOutputs,
            data: $data !== null ? hex2bin($data) : null,
        );

        $before = array_sum(array_column($utxos, 'value'));
        $sent = array_sum(array_map(fn ($o) => $o['role'] === 'recipient' ? $o['value'] : 0, $plan['outputs']));

        $recipientOutputs = array_values(array_filter($plan['outputs'], fn ($o) => $o['role'] === 'recipient'));
        $recipients = array_map(fn ($r, $i) => [...$r, 'amount' => (string)self::btc($recipientOutputs[$i]['value'])], array_values($recipients), array_keys(array_values($recipients)));

        return [
            'from' => $from->address,
            'recipients' => $recipients,
            'totalAmount' => (string)self::btc($sent),
            'fee' => (string)self::btc($plan['fee']),
            'feeRate' => $plan['fee_rate'],
            'vsize' => $plan['vsize'],
            'beforeBalance' => (string)self::btc($before),
            'afterBalance' => (string)self::btc($before - $sent - $plan['fee']),
            'inputs' => array_map(fn ($u) => ['txid' => $u['txid'], 'vout' => $u['vout']], $plan['inputs']),
            'hex' => null,
            'plan' => $plan,
        ];
    }

    /**
     * Plans, signs, checks and broadcasts; records the transfers. One send of a wallet at a time.
     *
     * @return list<BitcoinTransfer>
     */
    public function send(
        BitcoinAddress|string $from,
        array $recipients,
        #[\SensitiveParameter] ?string $seedHex,
        bool $subtractFeeFromOutputs = false,
        BitcoinAddress|string|null $changeAddress = null,
        ?float $feeRate = null,
        ?string $data = null,
        ?array &$preview = null,
    ): array {
        if (!$seedHex) {
            throw new \RuntimeException('Sending from a light wallet needs the wallet seed to sign.');
        }

        $from = $this->address($from);

        return Cache::lock('bitcoin:light-send:'.$this->wallet->getKey(), 120)->block(15, function () use ($from, $recipients, $seedHex, $subtractFeeFromOutputs, $changeAddress, $feeRate, $data, &$preview): array {
            $preview = $this->preview($from, $recipients, $subtractFeeFromOutputs, $changeAddress, $feeRate, $data);
            $plan = $preview['plan'];

            $signed = Signer::sign($plan, $seedHex);

            // Reserved before the broadcast: a provider may accept it even when the answer is lost
            $this->reserve($plan['inputs'], now()->addHours((int)config('bitcoin.light.reserve_hours', 6)));

            try {
                App::make(ProviderPool::class)->broadcast($signed['hex'], $plan['txid']);
            } catch (\RuntimeException $e) {
                if (!$this->isKnown($plan['txid'])) {
                    // Most likely not sent; the coins stay reserved a little longer in case it was
                    $this->reserve($plan['inputs'], now()->addMinutes((int)config('bitcoin.light.failed_reserve_minutes', 10)));

                    throw $e;
                }
            }

            return $this->record($from, $preview, $plan);
        });
    }

    /**
     * Plans collecting every spendable coin of several addresses to one address in a single
     * transaction: the fee is taken from the amount, there is no change. `perAddress` — what
     * each address gives (in BTC); addresses without spendable coins are left out.
     *
     * @param list<BitcoinAddress|string> $from
     * @return array{from: list<string>, to: string, amount: string, fee: string, feeRate: float, vsize: int, total: string, perAddress: array<string, string>, inputs: list<array{txid: string, vout: int}>, plan: array}
     */
    public function previewSweep(array $from, string $to, ?float $feeRate = null): array
    {
        if ($from === []) {
            throw new \InvalidArgumentException('No addresses to collect from.');
        }

        $addresses = [];
        $utxos = [];
        $perAddress = [];

        foreach ($from as $item) {
            $address = $this->address($item);

            foreach ($this->spendableUtxos($address) as $utxo) {
                $utxos[] = $utxo;
                $perAddress[$address->address] = ($perAddress[$address->address] ?? 0) + $utxo['value'];
            }

            if (isset($perAddress[$address->address])) {
                $addresses[] = $address;
            }
        }

        if ($utxos === []) {
            throw new \RuntimeException('Nothing to collect: the addresses have no spendable coins.');
        }

        $total = array_sum(array_column($utxos, 'value'));

        $plan = PsbtBuilder::build(
            utxos: $utxos,
            recipients: [['address' => $to, 'amount' => self::btc($total)]],
            feeRate: $this->feeRate($feeRate),
            changeAddress: $addresses[0]->address,
            subtractFeeFromFirst: true,
        );

        $sent = array_sum(array_map(fn ($o) => $o['role'] === 'recipient' ? $o['value'] : 0, $plan['outputs']));

        return [
            'from' => array_map(fn (BitcoinAddress $address) => $address->address, $addresses),
            'to' => $to,
            'amount' => (string)self::btc($sent),
            'fee' => (string)self::btc($plan['fee']),
            'feeRate' => $plan['fee_rate'],
            'vsize' => $plan['vsize'],
            'total' => (string)self::btc($total),
            'perAddress' => array_map(fn (int $value) => (string)self::btc($value), $perAddress),
            'inputs' => array_map(fn ($u) => ['txid' => $u['txid'], 'vout' => $u['vout']], $plan['inputs']),
            'plan' => $plan,
        ];
    }

    /**
     * Collects the coins of several addresses to one address (see previewSweep()): signs,
     * broadcasts and records one transfer, from the address that gives the most.
     *
     * @param list<BitcoinAddress|string> $from
     */
    public function sweep(
        array $from,
        string $to,
        #[\SensitiveParameter] ?string $seedHex,
        ?float $feeRate = null,
        ?string $comment = null,
        ?array &$preview = null,
    ): BitcoinTransfer {
        if (!$seedHex) {
            throw new \RuntimeException('Sending from a light wallet needs the wallet seed to sign.');
        }

        return Cache::lock('bitcoin:light-send:'.$this->wallet->getKey(), 120)->block(15, function () use ($from, $to, $seedHex, $feeRate, $comment, &$preview): BitcoinTransfer {
            $preview = $this->previewSweep($from, $to, $feeRate);
            $plan = $preview['plan'];

            $signed = Signer::sign($plan, $seedHex);

            // Reserved before the broadcast: a provider may accept it even when the answer is lost
            $this->reserve($plan['inputs'], now()->addHours((int)config('bitcoin.light.reserve_hours', 6)));

            try {
                App::make(ProviderPool::class)->broadcast($signed['hex'], $plan['txid']);
            } catch (\RuntimeException $e) {
                if (!$this->isKnown($plan['txid'])) {
                    $this->reserve($plan['inputs'], now()->addMinutes((int)config('bitcoin.light.failed_reserve_minutes', 10)));

                    throw $e;
                }
            }

            return $this->recordSweep($preview, $plan, $comment);
        });
    }

    /**
     * The sweep transfer (from the address that gives the most), the wallet transaction, and the
     * balances of the addresses lowered at once — the next sync brings the provider's view.
     */
    protected function recordSweep(array $preview, array $plan, ?string $comment): BitcoinTransfer
    {
        return DB::transaction(function () use ($preview, $plan, $comment): BitcoinTransfer {
            $now = Date::now();
            $amounts = $preview['perAddress'];
            uasort($amounts, fn (string $a, string $b) => BigDecimal::of($b)->compareTo($a));
            $addresses = $this->wallet->addresses()->whereIn('address', array_keys($amounts))->get()->keyBy('address');
            $main = $addresses->get(array_key_first($amounts));
            $output = collect($plan['outputs'])->firstWhere('role', 'recipient');
            $isOwn = $this->wallet->addresses()->where('address', $output['address'])->exists();
            $leaves = ($isOwn ? 0 : $output['value']) + $plan['fee'];

            $transfer = $this->wallet->transfers()->updateOrCreate(
                ['address_id' => $main->id, 'txid' => $plan['txid'], 'receiver_address' => $output['address']],
                [
                    'amount' => self::btc($output['value']),
                    'comment' => $comment,
                    'confirmations' => 0,
                    'time_at' => $now,
                ],
            );
            $transfer->setRelation('wallet', $this->wallet);
            $transfer->setRelation('address', $main);

            $this->wallet->transactions()->updateOrCreate(['txid' => $plan['txid']], [
                'direction' => $isOwn ? 'self' : 'out',
                'amount' => self::btc(-$leaves),
                'fee' => self::btc($plan['fee']),
                'time_at' => $now,
            ]);

            foreach ($amounts as $address => $amount) {
                $model = $addresses->get($address);
                $model?->update(['balance' => BigDecimal::of($model->balance ?? 0)->minus($amount), 'stats_hash' => null]);
            }

            $this->wallet->update([
                'balance' => BigDecimal::of($this->wallet->balance ?? 0)->minus(self::btc($leaves)),
                'touch_at' => $now,
            ]);

            return $transfer;
        });
    }

    /**
     * Replaces an unconfirmed transaction of this wallet with the same one paying a higher fee
     * (BIP125): same inputs and recipients, the extra fee comes out of the change. The new fee
     * must exceed the old one by at least 1 sat/vB of the size. Transfers move to the new txid,
     * the old transaction is marked dropped.
     *
     * @return array{txid: string, old_txid: string, fee: string, fee_rate: float}
     */
    public function bumpFee(string $txid, float $feeRate, #[\SensitiveParameter] ?string $seedHex): array
    {
        if (!$seedHex) {
            throw new \RuntimeException('Speeding up a transaction needs the wallet seed to sign.');
        }

        $txid = strtolower($txid);

        if (!preg_match('/^[0-9a-f]{64}$/', $txid)) {
            throw new \InvalidArgumentException('Invalid txid.');
        }

        // Only a transaction this wallet sent, with its recipients recorded here
        $transfers = $this->wallet->transfers()->where('txid', $txid)->get();
        $record = $this->wallet->transactions()->where('txid', $txid)->whereIn('direction', ['out', 'self'])->first();

        if (!$record || $transfers->isEmpty()) {
            throw new \RuntimeException('Only a transaction sent from this wallet can be sped up.');
        }

        $maxRate = (float)config('bitcoin.light.max_fee_rate', 500);

        if ($feeRate > $maxRate) {
            throw new \RuntimeException('Fee rate '.$feeRate.' sat/vB is above the limit of '.$maxRate.' sat/vB.');
        }

        return Cache::lock('bitcoin:light-send:'.$this->wallet->getKey(), 120)->block(15, function () use ($txid, $feeRate, $seedHex, $transfers, $record, $maxRate): array {
            $pool = App::make(ProviderPool::class);

            if ($pool->call(fn (EsploraApi $api) => $api->transactionStatus($txid))['confirmed'] ?? false) {
                throw new \RuntimeException('The transaction is already confirmed.');
            }

            // Everything below comes from raw transactions whose txid is checked here: a provider
            // cannot change inputs, outputs or amounts without changing the txid
            $original = $this->verifiedTransaction($txid);
            $own = $this->wallet->addresses()->get()->keyBy('address');
            $inputs = [];

            foreach ($original->inputs as $input) {
                $previous = $this->verifiedTransaction($input['txid'])->outputs[$input['vout']] ?? null;
                $address = $previous ? $own->get((string)Script::toAddress($previous['script'])) : null;

                if (!$address || $address->chain === null || $input['sequence'] >= 0xfffffffe) {
                    throw new \RuntimeException('Only an RBF transaction spending this wallet\'s coins can be sped up.');
                }

                $inputs[] = [
                    'txid' => $input['txid'],
                    'vout' => $input['vout'],
                    'value' => $previous['value'],
                    'script' => $previous['script'],
                    'path' => rtrim((string)$this->wallet->derivation_path, '/').'/'.$address->chain.'/'.$address->index,
                ];
            }

            $outputs = [];
            foreach ($original->outputs as $output) {
                $address = Script::toAddress($output['script']);
                $role = $address === null ? 'data' : ($own->has($address) ? 'change' : 'recipient');

                if ($role === 'recipient' && !$transfers->contains(fn ($t) => $t->receiver_address === $address && self::satoshi($t->amount) === $output['value'])) {
                    throw new \RuntimeException('The transaction does not match the recorded transfer.');
                }

                $outputs[] = ['value' => $output['value'], 'script' => $output['script'], 'address' => $address, 'role' => $role];
            }

            $changeIndex = array_key_last(array_filter($outputs, fn ($o) => $o['role'] === 'change'));

            if ($changeIndex === null) {
                throw new \RuntimeException('The transaction has no change to pay a higher fee from.');
            }

            $oldFee = array_sum(array_column($inputs, 'value')) - array_sum(array_column($outputs, 'value'));
            $vsize = $this->vsize($original);
            $newFee = max((int)ceil($feeRate * $vsize), $oldFee + $vsize);

            if ($newFee > $maxRate * $vsize) {
                throw new \RuntimeException('The fee would be above the limit of '.$maxRate.' sat/vB.');
            }

            $outputs[$changeIndex]['value'] -= $newFee - $oldFee;

            if ($outputs[$changeIndex]['value'] < Script::dust(Script::parse((string)$outputs[$changeIndex]['address'])['kind'])) {
                throw new \RuntimeException('Not enough change to pay the higher fee.');
            }

            $unsigned = new Transaction(
                inputs: array_map(fn ($i) => ['txid' => $i['txid'], 'vout' => $i['vout'], 'sequence' => PsbtBuilder::SEQUENCE_RBF], $inputs),
                outputs: array_map(fn ($o) => ['value' => $o['value'], 'script' => $o['script']], $outputs),
                version: $original->version,
                locktime: $original->locktime,
            );

            $plan = [
                'inputs' => $inputs,
                'outputs' => $outputs,
                'fee' => $newFee,
                'vsize' => $vsize,
                'fee_rate' => round($newFee / $vsize, 2),
                'txid' => $unsigned->txid(),
                'psbt' => base64_encode(PsbtBuilder::psbt($unsigned, $inputs)),
            ];

            $signed = Signer::sign($plan, $seedHex);
            $this->reserve($inputs, now()->addHours((int)config('bitcoin.light.reserve_hours', 6)));
            $pool->broadcast($signed['hex'], $plan['txid']);

            DB::transaction(function () use ($record, $txid, $plan, $newFee, $oldFee): void {
                $this->wallet->transactions()->updateOrCreate(['txid' => $plan['txid']], [
                    'direction' => $record->direction,
                    'amount' => BigDecimal::of($record->amount ?? 0)->minus(self::btc($newFee - $oldFee)),
                    'fee' => self::btc($newFee),
                    'time_at' => Date::now(),
                ]);
                $record->update(['dropped_at' => Date::now()]);
                $this->wallet->transfers()->where('txid', $txid)->update(['txid' => $plan['txid']]);
            });

            return ['txid' => $plan['txid'], 'old_txid' => $txid, 'fee' => (string)self::btc($newFee), 'fee_rate' => $plan['fee_rate']];
        });
    }

    /**
     * Raw transaction from a provider, accepted only when its txid is the requested one.
     */
    protected function verifiedTransaction(string $txid): Transaction
    {
        $hex = App::make(ProviderPool::class)->call(fn (EsploraApi $api) => $api->transactionHex($txid));
        $tx = Transaction::fromHex($hex);

        if ($tx->txid() !== $txid) {
            throw new \RuntimeException('A provider returned another transaction for '.$txid.'.');
        }

        return $tx;
    }

    /**
     * Virtual size from what the txid covers: P2WPKH inputs at their largest (68 vB) and the
     * outputs. The witness of the original is not used — the txid does not cover it, so a
     * provider could pad it to inflate the fee.
     */
    protected function vsize(Transaction $tx): int
    {
        $outputs = array_sum(array_map(fn ($o) => Script::outputVsize($o['script']), $tx->outputs));

        return (int)ceil(10.5 + 68 * count($tx->inputs) + $outputs);
    }

    protected static function satoshi(BigDecimal|string|null $amount): int
    {
        return PsbtBuilder::satoshi($amount ?? 0);
    }

    /**
     * What the address can spend now (before the fee), in BTC.
     */
    public function spendableBalance(BitcoinAddress|string $from): BigDecimal
    {
        return self::btc(array_sum(array_column($this->spendableUtxos($this->address($from)), 'value')));
    }

    /**
     * @return list<array{txid: string, vout: int, value: int, script: string, path: string}>
     */
    protected function spendableUtxos(BitcoinAddress $from): array
    {
        if ($from->chain === null || $from->index === null) {
            throw new \RuntimeException('Address '.$from->address.' was not derived by this wallet, its key is unknown.');
        }

        $utxos = App::make(ProviderPool::class)->call(fn (EsploraApi $api) => $api->addressUtxos($from->address));
        $reserved = $this->reserved();
        $own = $this->wallet->transactions()->whereIn('direction', ['out', 'self'])->whereNull('dropped_at')->pluck('txid')->flip();
        $script = Script::forAddress($from->address);
        $path = rtrim((string)$this->wallet->derivation_path, '/').'/'.$from->chain.'/'.$from->index;

        $spendable = [];

        foreach ($utxos as $utxo) {
            $confirmed = (bool)($utxo['status']['confirmed'] ?? false);

            if (isset($reserved[$utxo['txid'].':'.$utxo['vout']]) || (!$confirmed && !$own->has($utxo['txid']))) {
                continue;
            }

            $spendable[] = ['txid' => $utxo['txid'], 'vout' => (int)$utxo['vout'], 'value' => (int)$utxo['value'], 'script' => $script, 'path' => $path];
        }

        return $spendable;
    }

    /**
     * Recommended fee rates in sat/vB (fastest, half_hour, hour, economy, minimum), cached a minute.
     *
     * @return array<string, float>
     */
    public static function recommendedFeeRates(): array
    {
        return Cache::remember(
            'bitcoin:light:fee-rates',
            60,
            fn (): array => App::make(ProviderPool::class)->call(fn (EsploraApi $api) => $api->feeRates()),
        );
    }

    protected function feeRate(?float $feeRate): float
    {
        $rate = $feeRate ?? self::recommendedFeeRates()[config('bitcoin.light.fee_rate', 'half_hour')] ?? 2.0;

        $max = (float)config('bitcoin.light.max_fee_rate', 500);

        if ($rate > $max) {
            throw new \RuntimeException('Fee rate '.$rate.' sat/vB is above the limit of '.$max.' sat/vB.');
        }

        return max(1.0, round($rate, 2));
    }

    /**
     * Reserves the outputs until `$until` (each output has its own expiry).
     *
     * @param list<array{txid: string, vout: int}> $inputs
     */
    protected function reserve(array $inputs, \DateTimeInterface $until): void
    {
        $reserved = $this->reserved();

        foreach ($inputs as $input) {
            $reserved[$input['txid'].':'.$input['vout']] = $until->getTimestamp();
        }

        Cache::put($this->reserveKey(), $reserved, now()->addHours((int)config('bitcoin.light.reserve_hours', 6)));
    }

    /**
     * @return array<string, int> outpoint => expiry timestamp, expired ones dropped
     */
    protected function reserved(): array
    {
        $now = Date::now()->getTimestamp();

        return array_filter((array)Cache::get($this->reserveKey(), []), fn ($until) => is_int($until) && $until > $now);
    }

    /**
     * Whether any provider knows the transaction (in the mempool or mined).
     */
    protected function isKnown(string $txid): bool
    {
        try {
            return App::make(ProviderPool::class)->call(fn (EsploraApi $api) => $api->transactionStatus($txid)) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Transfers per recipient (with their comments), the wallet transaction, and balances
     * lowered at once — the next sync brings the provider's view.
     *
     * @return list<BitcoinTransfer>
     */
    protected function record(BitcoinAddress $from, array $preview, array $plan): array
    {
        return DB::transaction(function () use ($from, $preview, $plan): array {
            $now = Date::now();
            $own = $this->wallet->addresses()->pluck('id', 'address');
            $transfers = [];
            $foreign = 0;

            foreach ($plan['outputs'] as $index => $output) {
                if ($output['role'] !== 'recipient') {
                    continue;
                }

                $recipient = $preview['recipients'][$index] ?? [];
                $isOwn = $own->has($output['address']);
                $foreign += $isOwn ? 0 : $output['value'];

                $transfer = $this->wallet->transfers()->updateOrCreate(
                    ['address_id' => $from->id, 'txid' => $plan['txid'], 'receiver_address' => $output['address']],
                    [
                        'amount' => self::btc($output['value']),
                        'comment' => $recipient['comment'] ?? null,
                        'confirmations' => 0,
                        'time_at' => $now,
                    ],
                );
                $transfer->setRelation('wallet', $this->wallet);
                $transfer->setRelation('address', $from);
                $transfers[] = $transfer;
            }

            $this->wallet->transactions()->updateOrCreate(['txid' => $plan['txid']], [
                'direction' => $foreign > 0 ? 'out' : 'self',
                'amount' => self::btc(-($foreign + $plan['fee'])),
                'fee' => self::btc($plan['fee']),
                'time_at' => $now,
            ]);

            $spent = array_sum(array_column($plan['inputs'], 'value'));
            $back = array_sum(array_map(fn ($o) => $o['address'] === $from->address ? $o['value'] : 0, $plan['outputs']));

            $from->update(['balance' => BigDecimal::of($from->balance ?? 0)->minus(self::btc($spent - $back)), 'stats_hash' => null]);
            $this->wallet->update([
                'balance' => BigDecimal::of($this->wallet->balance ?? 0)->minus(self::btc($foreign + $plan['fee'])),
                'touch_at' => $now,
            ]);

            return $transfers;
        });
    }

    protected function address(BitcoinAddress|string $address): BitcoinAddress
    {
        if ($address instanceof BitcoinAddress) {
            if ((int)$address->wallet_id !== (int)$this->wallet->getKey()) {
                throw new \InvalidArgumentException('The address does not belong to this wallet.');
            }

            return $address;
        }

        return $this->wallet->addresses()->where('address', $address)->firstOrFail();
    }

    protected function reserveKey(): string
    {
        return 'bitcoin:light:reserved:'.$this->wallet->getKey();
    }

    protected static function btc(int $satoshi): BigDecimal
    {
        return BigDecimal::ofUnscaledValue($satoshi, 8);
    }
}
