<?php

namespace ItHealer\LaravelBitcoin\Concerns;

use Illuminate\Support\Str;
use ItHealer\LaravelBitcoin\Facades\Bitcoin;
use ItHealer\LaravelBitcoin\Models\ElectrumNode;
use ItHealer\LaravelBitcoin\Services\Electrum\ElectrumSupervisorService;

trait Electrums
{
    public function createElectrum(string $name, array $config = [], ?string $title = null): ElectrumNode
    {
        /** @var class-string<ElectrumNode> $model */
        $model = Bitcoin::getModelElectrum();

        $exists = $model::where('name', $name)->exists();
        if( $exists ) {
            throw new \Exception('Electrum name is already exists.');
        }

        $minPort = (int)config('bitcoin.electrum.ports.min', 10000);
        $maxPort = (int)config('bitcoin.electrum.ports.max', 10999);
        for ($i = 0; $i < 50; $i++) {
            $port = mt_rand($minPort, $maxPort);
            $connection = @fsockopen('127.0.0.1', $port);
            if ($connection) {
                fclose($connection);
                $port = null;
                continue;
            }
            break;
        }
        if (!$port) {
            throw new \Exception('Not found free port.');
        }

        $electrum = new $model([
            'name' => $name,
            'title' => $title,
            'host' => '127.0.0.1',
            'port' => $port,
            'username' => Str::random(),
            'password' => Str::random(),
            'config' => $config,
        ]);
        $process = ElectrumSupervisorService::startProcess($electrum);
        $electrum->pid = $process->getPid();
        try {
            $electrum->api()->request('version_info');
        }
        catch (\Exception $e) {
            $process->stop(3);
            throw $e;
        }
        $electrum->save();

        return $electrum;
    }
}