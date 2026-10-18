# Changelog

All notable changes to `laravel-bitcoin` will be documented in this file.

## v2.2.0 - 2026-10-18

### Added

- Light mode: a wallet without a node. `Bitcoin::createLightWallet($name, $mnemonic, $passphrase)`
  stores only the account xpub (BIP84, `m/84'/0'/0'`), `createLightWalletFromXpub()` takes an
  xpub/zpub. Addresses are derived in PHP (BIP32 public derivation, bech32), balances and history
  come from public Esplora APIs (`bitcoin_providers`: mempool.space, blockstream.info) with
  failover, per-provider request counting and a pause between requests.
- `Bitcoin::createAddress()` on a light wallet hands out the next receive address; 20 unused
  addresses per chain are kept ahead (hidden until used).
- `Bitcoin::syncLightWallet()` and `bitcoin:light-sync` (every minute; touched wallets every
  `active_interval`, others every `idle_interval`): an address costs one request, its history is
  read only when its stats changed; deposits, transfers, confirmations from the tip height,
  dropped mempool transactions, deposit webhooks as before.
- Tables `bitcoin_providers`, `bitcoin_transactions`; light-mode columns on `bitcoin_wallets` and
  `bitcoin_addresses` (migrations — publish and run them).

### Changed

- `bitcoin_wallets.node_id` and `descriptors` are optional, and deleting a node no longer deletes
  its wallets (`nullOnDelete` instead of `cascadeOnDelete`).
- The unique indexes of `bitcoin_deposits`/`bitcoin_transfers` have table-specific names (both were
  `unique_index`, which SQLite rejects). Existing databases are not affected.

## v2.0.4 - 2026-06-05

### Changed

- Allow Laravel 13 (`illuminate/contracts: ^13.0`).

