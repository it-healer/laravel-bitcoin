<?php

namespace ItHealer\LaravelBitcoin\Services\Electrum;

use Illuminate\Support\Facades\File;
use ItHealer\LaravelBitcoin\Services\BaseConsole;

class ElectrumInstallerService extends BaseConsole
{
    protected ?string $version = null;

    public function run(): void
    {
        parent::run();
        $this->install();
    }

    protected function install(): bool
    {
        $os = PHP_OS_FAMILY;
        $arch = strtolower(php_uname('m'));
        $this->log("ОС: $os, архитектура: $arch");

        $version = $this->resolveVersion();
        if (!$version) {
            $this->log('❌ Не удалось определить стабильную версию Electrum.', 'error');
            return false;
        }
        $this->log("🔎 Выбрана стабильная версия Electrum: {$version}");

        $platformFile = $this->getPlatformFilename($version); // Electrum-<ver>.tar.gz
        if (!$platformFile) {
            $this->log('❌ Не удалось определить имя архива Electrum.', 'error');
            return false;
        }

        $base = "https://download.electrum.org/{$version}";
        $url = "{$base}/{$platformFile}";
        $this->log("📥 Скачивание: {$url}");

        // temp для загрузки
        $tempRoot = '/tmp/electrum-src-'.time();
        if (!is_dir($tempRoot)) {
            File::makeDirectory($tempRoot, 0755, true, true);
        }
        $archivePath = $tempRoot.DIRECTORY_SEPARATOR.$platformFile;

        // целевая папка: storage/app/electrum
        $targetDir = storage_path('app/electrum');
        if (!is_dir($targetDir)) {
            File::makeDirectory($targetDir, 0755, true, true);
        }

        // скачиваем
        $this->downloadWithProgress($url, $archivePath);
        $this->log("✅ Скачивание завершено: {$archivePath}", 'success');

        // распаковка БЕЗ вложенной директории версии
        $this->log("📂 Распаковка Electrum в {$targetDir} (без вложенной папки версии)...");
        // --strip-components=1 удалит верхний уровень 'Electrum-<ver>/'
        $cmd = "tar -xvf ".escapeshellarg($archivePath)." --strip-components=1 -C ".escapeshellarg($targetDir);
        $output = shell_exec($cmd." 2>&1");
        if ($output !== null) {
            $this->log($output);
        }
        $this->log("✅ Распаковка завершена", 'success');

        // удаляем архив и временную папку
        if (is_file($archivePath)) {
            @unlink($archivePath);
        }
        if (File::isDirectory($tempRoot)) {
            File::deleteDirectory($tempRoot);
        }
        $this->log("🗑 Архив и временные файлы удалены", 'success');

        $this->log('🧩 Установка (подсказка):', 'success');
        $this->log("— Для текущего пользователя:  python3 -m pip install --user ".escapeshellarg($targetDir));
        $this->log(
            "— Через venv:  python3 -m venv /opt/electrum && /opt/electrum/bin/pip install ".escapeshellarg($targetDir)
        );

        $this->log('✅ Electrum загружен и распакован в storage/app/electrum!', 'success');
        return true;
    }

    /** Определяем последнюю стабильную версию Electrum */
    protected function resolveVersion(): ?string
    {
        if (!empty($this->version)) {
            return ltrim($this->version, 'v');
        }

        $indexUrl = 'https://download.electrum.org/';
        $html = $this->httpGet($indexUrl);
        if (!$html) {
            return null;
        }

        // ссылки вида: href="4.6.1/"
        preg_match_all('#href="(\d+\.\d+(?:\.\d+)?)/"#', $html, $m);
        if (empty($m[1])) {
            return null;
        }

        $candidates = array_unique($m[1]);
        $candidates = array_filter($candidates, fn($v) => !preg_match('/rc|dev|beta/i', $v));

        usort($candidates, 'version_compare');
        return end($candidates) ?: null;
    }

    protected function getPlatformFilename(string $version): ?string
    {
        return "Electrum-{$version}.tar.gz";
    }

    /* ===================== HTTP/загрузка ===================== */

    protected function httpGet(string $url, int $timeout = 20): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'ItHealerElectrumInstaller/1.0',
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
        $dir = dirname($destination);
        if (!is_dir($dir)) {
            File::makeDirectory($dir, 0755, true, true);
        }

        $fp = fopen($destination, 'w+');
        if (!$fp) {
            throw new \RuntimeException("Не удалось открыть файл для записи: $destination");
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function ($resource, float $dlTotal, float $dlNow) {
                if ($dlTotal > 0) {
                    $percent = round($dlNow * 100 / $dlTotal, 1);
                    echo "\r📦 Прогресс загрузки: {$percent}%";
                }
            },
            CURLOPT_USERAGENT => 'ItHealerElectrumInstaller/1.0',
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
}