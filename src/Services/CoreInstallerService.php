<?php

namespace ItHealer\LaravelBitcoin\Services;

use Illuminate\Support\Facades\File;

class CoreInstallerService extends BaseConsole
{
    protected ?string $version = null;

    protected string $storagePath;

    public function __construct()
    {
        $this->storagePath = storage_path('app/bitcoin');
    }

    public function run(): void
    {
        parent::run();
        $this->install();
    }

    protected function install(): bool
    {
        $os   = PHP_OS_FAMILY;
        $arch = strtolower(php_uname('m'));

        $this->log("ОС: $os, архитектура: $arch");

        $version = $this->resolveVersion($os, $arch);
        if (!$version) {
            $this->log('❌ Не удалось найти стабильную версию Bitcoin Core с готовыми бинарями под вашу платформу.', 'error');
            return false;
        }
        $this->log("🔎 Выбрана стабильная версия: {$version}");

        $platformFile = $this->getPlatformFilename($os, $arch, $version);
        if (!$platformFile) {
            $this->log('❌ Не удалось определить имя архива для вашей платформы.', 'error');
            return false;
        }

        $base = "https://bitcoincore.org/bin/bitcoin-core-{$version}";
        $url  = "{$base}/{$platformFile}";
        $this->log("📥 Скачивание: $url");

        $tempRoot  = '/tmp/bitcoin-temp-' . time();
        $archive   = $tempRoot . ($this->isWindows($os) ? '/bitcoin.zip' : '/bitcoin.tar.gz');
        $outputDir = "$tempRoot/extracted";
        File::makeDirectory($outputDir, 0755, true, true);

        $this->downloadWithProgress($url, $archive);
        $this->log('✅ Скачивание завершено', 'success');

        try {
            $shaOk = $this->verifySha256($base, $platformFile, $archive);
            $this->log($shaOk ? '🔐 SHA256 проверка пройдена' : '⚠️ Не удалось подтвердить SHA256 (пропускаем)', $shaOk ? 'success' : 'error');
        } catch (\Throwable $e) {
            $this->log("⚠️ Ошибка проверки SHA256: {$e->getMessage()}", 'error');
        }

        if ($this->isWindows($os)) {
            $zip = new \ZipArchive();
            if ($zip->open($archive) === true) {
                $zip->extractTo($outputDir);
                $zip->close();
            } else {
                $this->log('❌ Не удалось распаковать ZIP-архив.', 'error');
                return false;
            }
        } else {
            shell_exec("tar -xvzf " . escapeshellarg($archive) . " -C " . escapeshellarg($outputDir));
        }
        $this->log('✅ Распаковка завершена!', 'success');

        // Ищем бинарники
        $daemonName = $this->isWindows($os) ? 'bitcoind.exe' : 'bitcoind';
        $cliName    = $this->isWindows($os) ? 'bitcoin-cli.exe' : 'bitcoin-cli';

        $bitcoindPath   = $this->findBinary($outputDir, $daemonName);
        $bitcoinCliPath = $this->findBinary($outputDir, $cliName);

        if (!$bitcoindPath || !$bitcoinCliPath) {
            $this->log('❌ Не найден bitcoind/bitcoin-cli после распаковки.', 'error');
            return false;
        }

        // Кладем в корень проекта (можешь заменить на storage_path, если удобнее)
        $finalDaemon = base_path($daemonName);
        $finalCli    = base_path($cliName);

        foreach ([$finalDaemon, $finalCli] as $path) {
            if (File::exists($path)) File::delete($path);
        }

        File::move($bitcoindPath, $finalDaemon);
        File::move($bitcoinCliPath, $finalCli);

        if (!$this->isWindows($os)) {
            @chmod($finalDaemon, 0755);
            @chmod($finalCli, 0755);
        }

        $this->log("✅ bitcoind установлен: $finalDaemon", 'success');
        $this->log("✅ bitcoin-cli установлен: $finalCli", 'success');

        $this->log('🧹 Очистка временных файлов...');
        if (File::isDirectory($tempRoot)) {
            File::deleteDirectory($tempRoot);
            $this->log("🗑 Удалена временная папка $tempRoot");
        }

        $this->log('✅ Установка Bitcoin Core завершена!', 'success');
        return true;
    }

    protected function resolveVersion(string $os, string $arch): ?string
    {
        // Зафиксированная версия — проверим, есть ли под неё бинарь
        if (!empty($this->version)) {
            return $this->versionIfHasPlatformBinary($this->version, $os, $arch);
        }

        $indexUrl = 'https://bitcoincore.org/bin/';
        $html = $this->httpGet($indexUrl);
        if (!$html) return null;

        // Ищем директории вида: bitcoin-core-29.0.1/
        preg_match_all('#bitcoin-core-([\d\.]+)/#i', $html, $m);
        if (empty($m[1])) return null;

        $candidates = array_unique($m[1]);

        // Убираем prerelease-метки (на всякий случай)
        $candidates = array_filter($candidates, function ($v) {
            return !preg_match('/(?:rc|test)/i', $v);
        });

        // Сортируем по убыванию версии
        usort($candidates, fn($a, $b) => version_compare($b, $a));

        // Берем первую версию, у которой действительно есть наш файл в SHA256SUMS
        foreach ($candidates as $v) {
            if ($this->versionHasPlatformBinary($v, $os, $arch)) {
                return $v;
            }
        }

        return null;
    }

    protected function versionHasPlatformBinary(string $version, string $os, string $arch): bool
    {
        $base = "https://bitcoincore.org/bin/bitcoin-core-{$version}";
        $file = $this->getPlatformFilename($os, $arch, $version);
        if (!$file) return false;

        $sums = $this->httpGet("{$base}/SHA256SUMS");
        if (!$sums) return false;

        // Ищем точное имя файла в списке
        return (bool)preg_match("#\\b{$this->pregQuote($file)}$#m", $sums);
    }

    protected function versionIfHasPlatformBinary(string $version, string $os, string $arch): ?string
    {
        return $this->versionHasPlatformBinary($version, $os, $arch) ? $version : null;
    }

    protected function getPlatformFilename(string $os, string $arch, string $version): ?string
    {
        $isArm = str_contains($arch, 'aarch64') || str_contains($arch, 'arm64');

        return match ($os) {
            'Linux'  => $isArm
                ? "bitcoin-{$version}-aarch64-linux-gnu.tar.gz"
                : "bitcoin-{$version}-x86_64-linux-gnu.tar.gz",
            'Darwin' => $isArm
                ? "bitcoin-{$version}-arm64-apple-darwin.tar.gz"
                : "bitcoin-{$version}-x86_64-apple-darwin.tar.gz",
            'Windows' => "bitcoin-{$version}-win64.zip",
            default => null,
        };
    }

    protected function verifySha256(string $baseUrl, string $fileName, string $localArchive): bool
    {
        $sums = $this->httpGet("{$baseUrl}/SHA256SUMS");
        if (!$sums) return false;

        // Строки формата: "<hash>  <filename>" (иногда со звёздочкой перед именем)
        if (!preg_match("#^([a-f0-9]{64})\\s+\\*?{$this->pregQuote($fileName)}$#m", $sums, $m)) {
            return false;
        }

        $expected = strtolower($m[1]);
        $actual   = strtolower(hash_file('sha256', $localArchive));

        if ($expected !== $actual) {
            throw new \RuntimeException("Несовпадение SHA256: ожидалось {$expected}, получено {$actual}");
        }
        return true;
    }

    protected function httpGet(string $url, int $timeout = 20): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => 'ItHealerBitcoinCoreInstaller/1.0',
        ]);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($data === false || $code < 200 || $code >= 300) {
            $err = curl_error($ch);
            curl_close($ch);
            $this->log("⚠️ HTTP GET fail: $url (code: $code; $err)", 'error');
            return null;
        }
        curl_close($ch);
        return $data;
    }

    protected function downloadWithProgress(string $url, string $destination): void
    {
        $fp = fopen($destination, 'w+');
        if (!$fp) {
            throw new \RuntimeException("Не удалось открыть файл для записи: $destination");
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE             => $fp,
            CURLOPT_FOLLOWLOCATION   => true,
            CURLOPT_NOPROGRESS       => false,
            // В PHP 8: callback(resource $ch, float $dlTotal, float $dlNow, float $ulTotal, float $ulNow)
            CURLOPT_PROGRESSFUNCTION => function ($resource, float $downloadSize, float $downloaded, float $uploadSize, float $uploaded) {
                if ($downloadSize > 0) {
                    $percent = round($downloaded * 100 / $downloadSize, 1);
                    echo "\r📦 Прогресс загрузки: {$percent}%";
                }
            },
            CURLOPT_USERAGENT        => 'ItHealerBitcoinCoreInstaller/1.0',
        ]);

        $result = curl_exec($ch);
        if ($result === false) {
            $err = curl_error($ch);
            curl_close($ch);
            fclose($fp);
            throw new \RuntimeException("Ошибка при скачивании: $err");
        }

        curl_close($ch);
        fclose($fp);
        echo "\n";
    }

    /* ===================== Поиск бинарников ===================== */

    protected function findBinary(string $dir, string $binaryName): ?string
    {
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $full = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($full)) {
                $found = $this->findBinary($full, $binaryName);
                if ($found) return $found;
            } elseif (is_file($full) && basename($full) === $binaryName) {
                return $full;
            }
        }
        return null;
    }

    /* ===================== Утилиты ===================== */

    protected function isWindows(string $osFamily): bool
    {
        return str_starts_with($osFamily, 'Windows');
    }

    protected function pregQuote(string $str): string
    {
        return preg_quote($str, '#');
    }

    // Геттеры итоговых путей
    public function getDaemonPath(): string
    {
        return base_path($this->isWindows(PHP_OS_FAMILY) ? 'bitcoind.exe' : 'bitcoind');
    }

    public function getCliPath(): string
    {
        return base_path($this->isWindows(PHP_OS_FAMILY) ? 'bitcoin-cli.exe' : 'bitcoin-cli');
    }
}