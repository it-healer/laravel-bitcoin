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
            App::make(ProviderPool::class)->broadcast($signed['hex'], $plan['txid']);

            $this->reserve($plan['inputs']);

            return $this->record($from, $preview, $plan);
        });
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
        $reserved = Cache::get($this->reserveKey(), []);
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
     * @param list<array{txid: string, vout: int}> $inputs
     */
    protected function reserve(array $inputs): void
    {
        $reserved = Cache::get($this->reserveKey(), []);

        foreach ($inputs as $input) {
            $reserved[$input['txid'].':'.$input['vout']] = true;
        }

        Cache::put($this->reserveKey(), $reserved, now()->addHours((int)config('bitcoin.light.reserve_hours', 6)));
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
