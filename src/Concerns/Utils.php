<?php

namespace ItHealer\LaravelBitcoin\Concerns;

use FurqanSiddiqui\BIP39\BIP39;
use FurqanSiddiqui\BIP39\Language\English;
use Illuminate\Support\Facades\Process;

trait Utils
{
    public function bip39ToDescriptors(string|array $mnemonic, ?string $passphrase = null): array
    {
        $command = config('bitcoin.core.bip39_command');
        if (empty($command)) {
            throw new \Exception('Bitcoin BIP39 command not defined');
        }
        $command = explode(' ', $command);

        if (is_array($mnemonic)) {
            $mnemonic = implode(' ', $mnemonic);
        }

        $process = Process::run([
            ...$command,
            $mnemonic,
            $passphrase ?? '',
            '--network=mainnet',
            '--watchonly=false'
        ]);
        $output = $process->failed() ? $process->errorOutput() : $process->output();
        $json = @json_decode($output, true);

        if ((!$json['success'] ?? false)) {
            throw new \Exception($json['error'] ?? $output);
        }

        return $json['descriptors'];
    }

    public function bip39MnemonicGenerate(int $wordCount = 18): array
    {
        $mnemonic = BIP39::fromRandom(
            wordList: English::getInstance(),
            wordCount: $wordCount
        );

        return $mnemonic->words;
    }

    public function bip39MnemonicValidate(string|array $mnemonic): bool
    {
        if (!is_array($mnemonic)) {
            $mnemonic = explode(' ', $mnemonic);
        }

        try {
            BIP39::fromWords(
                words: $mnemonic,
                wordList: English::getInstance()
            );
        } catch (\Exception) {
            return false;
        }

        return true;
    }

    public function bip39MnemonicSeed(string|array $mnemonic, string $passphrase = null): string
    {
        if (!is_array($mnemonic)) {
            $mnemonic = explode(' ', $mnemonic);
        }

        $mnemonic = BIP39::fromWords(
            words: $mnemonic,
            wordList: English::getInstance()
        );

        return bin2hex($mnemonic->generateSeed((string)$passphrase));
    }
}
