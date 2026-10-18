/**
 * PSBT signer of laravel-bitcoin light wallets.
 *
 * stdin (JSON): { psbt: base64, seed: hex BIP39 seed, inputs: [{ index, path }] }
 * stdout (JSON): { ok: true, hex, txid, vsize } or { ok: false, error }
 *
 * Signs every input with the key at its path and refuses the whole transaction when any
 * input is not a P2WPKH output of that key: the server cannot get a foreign input signed.
 * The seed comes only through stdin and is never printed.
 */
import { HDKey } from '@scure/bip32';
import { Transaction, p2wpkh, NETWORK, SigHash } from '@scure/btc-signer';
import { base64, hex } from '@scure/base';

const read = async () => {
    const chunks = [];
    for await (const chunk of process.stdin) chunks.push(chunk);

    return JSON.parse(Buffer.concat(chunks).toString('utf8'));
};

const equalBytes = (a, b) => a.length === b.length && a.every((value, i) => value === b[i]);

const sign = ({ psbt, seed, inputs }) => {
    if (typeof psbt !== 'string' || typeof seed !== 'string' || !/^[0-9a-f]{32,128}$/i.test(seed) || !Array.isArray(inputs)) {
        throw new Error('Expected psbt (base64), seed (hex) and inputs.');
    }

    const tx = Transaction.fromPSBT(base64.decode(psbt));
    const root = HDKey.fromMasterSeed(hex.decode(seed));
    const paths = new Map(inputs.map((input) => [Number(input.index), String(input.path)]));

    if (tx.inputsLength === 0 || paths.size !== tx.inputsLength) {
        throw new Error('Every input needs exactly one derivation path.');
    }

    for (let index = 0; index < tx.inputsLength; index++) {
        const path = paths.get(index);

        if (!path || !/^m(\/\d+'?)+$/.test(path)) {
            throw new Error(`Input ${index}: invalid derivation path.`);
        }

        const key = root.derive(path);
        const script = tx.getInput(index).witnessUtxo?.script;

        if (!script || !equalBytes(script, p2wpkh(key.publicKey, NETWORK).script)) {
            throw new Error(`Input ${index} does not belong to this wallet.`);
        }

        tx.signIdx(key.privateKey, index, [SigHash.ALL]);
        key.wipePrivateData();
    }

    tx.finalize();

    return { ok: true, hex: hex.encode(tx.extract()), txid: tx.id, vsize: tx.vsize };
};

try {
    process.stdout.write(JSON.stringify(sign(await read())));
} catch (error) {
    process.stdout.write(JSON.stringify({ ok: false, error: String(error?.message ?? error) }));
    process.exitCode = 1;
}
