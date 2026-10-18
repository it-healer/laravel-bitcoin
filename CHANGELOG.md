# Changelog

All notable changes to `laravel-bitcoin` will be documented in this file.

## v2.3.0 - 2026-10-18

### Added

- Sending from light wallets: `previewPSBT`/`sendPSBT`/`previewMassPSBT`/`sendMassPSBT` work in the
  light mode with the same semantics (coins of one address, change back to it, OP_RETURN after the
  change, «send all»), new named arguments `seedHex:` and `feeRate:` (sat/vB, default — the
  provider's `half_hour` recommendation, `max_fee_rate` cap). Coins: largest first, change below
  dust goes to the fee, RBF on every input; confirmed coins and own unconfirmed change only.
- `signer/`: a Node signer on `@scure/btc-signer` bundled into one file (`signer/dist/sign.mjs`,
  no `npm install` on the server). It gets the PSBT and the BIP39 seed through stdin, signs only
  P2WPKH inputs of that seed and refuses the rest. PHP then checks the result independently: same
  txid, inputs and outputs as planned, and a valid BIP143 signature of the owning key on every
  input — only then it is broadcast to every provider.
- Coins are reserved before the broadcast; when the broadcast fails the txid is looked up, a known
  transaction is recorded as sent, otherwise the coins stay reserved for `failed_reserve_minutes`.
- `bumpLightFee()`: speed up an unconfirmed send (RBF) from its change, built only from raw
  transactions whose txid is checked and whose recipients match the recorded transfers.
- `lightFeeRates()`, `lightTransactionStatus()`, `xpubFromSeed()`, `convertToLight()` (move a node
  wallet to the light mode by its xpub, keeping addresses, deposits and transfers).
- Addresses of a wallet that has not had its first full sync are checked one by one before being
  handed out, so an address with history is never issued again.

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
- `Bitcoin::createLightWalletFromSeed($name, $seedHex)` — the same from a BIP39 seed.
- Birth height: a light wallet stores the chain height it was added at (current tip, or
  `birthHeight:`), and is not created while no provider answers. Deposits mined at least
  `history_margin_blocks` (6) before it are history found by the first sync of a restored wallet:
  recorded without the webhook.
- Before a deposit webhook the height of every transaction still below the confirmation target is
  checked again with a provider (reorgs); deposits are claimed for the webhook atomically; one
  sync of a wallet at a time (lock inside `syncLightWallet`).
- `Bitcoin::validateAddress()` works without the node (bech32/bech32m, Base58Check, mainnet), the
  node argument is ignored.
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

