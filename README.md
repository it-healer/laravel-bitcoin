![Pest Laravel Expectations](https://banners.beyondco.de/Laravel%20BITCOIN.png?theme=light&packageManager=composer+require&packageName=it-healer%2Flaravel-bitcoin&pattern=architect&style=style_1&description=Bitcoin+Wallet+Library+for+Laravel&md=1&showWatermark=0&fontSize=100px&images=https%3A%2F%2Flaravel.com%2Fimg%2Flogomark.min.svg)

<a href="https://packagist.org/packages/it-healer/laravel-bitcoin" target="_blank">
    <img style="display: inline-block; margin-top: 0.5em; margin-bottom: 0.5em" src="https://img.shields.io/packagist/v/it-healer/laravel-bitcoin.svg?style=flat&cacheSeconds=3600" alt="Latest Version on Packagist">
</a>

<a href="https://packagist.org/packages/it-healer/laravel-bitcoin" target="_blank">
    <img style="display: inline-block; margin-top: 0.5em; margin-bottom: 0.5em" src="https://img.shields.io/packagist/dt/it-healer/laravel-bitcoin.svg?style=flat&cacheSeconds=3600" alt="Total Downloads">
</a>

---

**Laravel Bitcoin** is a Laravel package for work with cryptocurrency Bitcoin. You can create descriptor wallets, generate addresses, track current balances, collect transaction history, organize payment acceptance on your website, and automate outgoing transfers.

## Examples

Create Descriptor Wallet:
```php
$name = 'my-wallet';
$password = 'password for encrypt wallet files';
$title = 'My First Wallet';

$node = Bitcoin::createNode('localhost', 'LocalHost', '127.0.0.1');
$wallet = Bitcoin::createWallet($node, $name, $password, $title);
```

Import Descriptor Wallet using descriptors:
```php
$name = 'my-wallet';
$password = 'password for encrypt wallet files';
$descriptions = json_decode('DESCRIPTORS JSON', true);
$title = 'My First Wallet';

$node = Bitcoin::createNode('localhost', 'LocalHost', '127.0.0.1');
$wallet = Bitcoin::importWallet($node, $name, $descriptions, $password, $title);
```

Create address:
```php
$wallet = BitcoinWallet::firstOrFail();
$title = 'My address title';

$address = Bitcoin::createAddress($wallet, AddressType::BECH32, $title);
```

Validate address:
```php
$address = '....';

$node = BitcoinNode::firstOrFail();
$addressType = Bitcoin::validateAddress($node, $address);
if( $addressType === null ) {
    die('Address is not valid!');
} 

var_dump($addressType); // Enum value of AddressType
```

Send all BTC from wallet:
```php
$wallet = BitcoinWallet::firstOrFail();
$address = 'to_address';

$txid = Bitcoin::sendAll($wallet, $address);

echo 'TXID: '.$txid;
```

Send BTC from wallet:
```php
$wallet = BitcoinWallet::firstOrFail();
$address = 'to_address';
$amount = 0.001;

$txid = Bitcoin::send($wallet, $address, $amount);

echo 'TXID: '.$txid;
```


### Installation
You can install the package via composer:
```bash
composer require it-healer/laravel-bitcoin
```

After you can run installer using command:
```bash
php artisan bitcoin:install
```

And run migrations:
```bash
php artisan migrate
```

Register Service Provider and Facade in app, edit `config/app.php`:
```php
'providers' => ServiceProvider::defaultProviders()->merge([
    ...,
    \ItHealer\LaravelBitcoin\BitcoinServiceProvider::class,
])->toArray(),

'aliases' => Facade::defaultAliases()->merge([
    ...,
    'Bitcoin' => \ItHealer\LaravelBitcoin\Facades\Bitcoin::class,
])->toArray(),
```

In file `app/Console/Kernel` in method `schedule(Schedule $schedule)` add
```
$schedule->command('bitcoin:sync')
    ->everyMinute()
    ->runInBackground();
```

## Commands

Scan transactions and update balances:

```bash
> php artisan bitcoin:sync
```

Scan transactions and update balances for wallet:

```bash
> php artisan bitcoin:sync-wallet {wallet_id}
```

## WebHook

You can set up a WebHook that will be called when a new incoming BTC deposit is detected.

In file config/bitcoin.php you can set param:

```php
'webhook_handler' => \ItHealer\LaravelBitcoin\WebhookHandlers\EmptyWebhookHandler::class,
```

Example WebHook handler:

```php
class EmptyWebhookHandler implements WebhookHandlerInterface
{
    public function handle(BitcoinWallet $wallet, BitcoinAddress $address, BitcoinDeposit $transaction): void
    {
        Log::error('Bitcoin Wallet '.$wallet->name.' new transaction '.$transaction->txid.' for address '.$address->address);
    }
}
```

## Requirements

The following versions of PHP are supported by this version.

* PHP 8.2 and older
* PHP Extensions: Decimal.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [IT-HEALER](https://github.com/it-healer)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

