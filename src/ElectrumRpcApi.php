<?php

namespace ItHealer\LaravelBitcoin;

use GuzzleHttp\Client;
use Illuminate\Support\Str;
use ItHealer\LaravelBitcoin\Models\ElectrumNode;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Process\Process;

class ElectrumRpcApi
{
    protected readonly Client $client;
    protected ?ResponseInterface $response = null;

    public function __construct(
        protected readonly ElectrumNode $electrum,

    ) {
        $this->client = new Client([
            'base_uri' => 'http://'.$this->electrum->host.':'.$this->electrum->port,
            'auth' => [$this->electrum->username, $this->electrum->password],
            'timeout' => 30,
            'connect_timeout' => 10,
            'http_errors' => false,
        ]);
    }

    public function request(string $method, array $params = [], ?string $wallet = null): array
    {
        $requestId = Str::uuid()->toString();

        $this->response = $this->client->post($wallet ? '/wallet/'.$wallet : '', [
            'json' => [
                'jsonrpc' => '2.0',
                'id' => $requestId,
                'method' => $method,
                'params' => $params
            ],
        ]);

        $body = $this->response->getBody()->getContents();
        $data = json_decode($body, true);

        if ($data['error'] ?? false) {
            throw new \Exception('Electrum RPC '.$method.' '.$data['error']['code'].' - '.$data['error']['message']);
        }

        if ($this->response->getStatusCode() !== 200) {
            throw new \Exception(
                'Electrum RPC '.$method.' status code '.$this->response->getStatusCode(
                ).' - '.$this->response->getReasonPhrase().' - '.$body
            );
        }

        if (!isset($data['id']) || $data['id'] !== $requestId) {
            throw new \Exception('Request ID is not correct');
        }

        return isset($data['result']) && is_array($data['result']) ? $data['result'] : $data;
    }

    public function cli(array $params): array
    {
        $binaryPath = config('bitcoin.electrum.binary_path', 'python3');
        $executePath = config('bitcoin.electrum.execute_path', 'electrum');

        $directory = config('bitcoin.electrum.directory');
        $dataDir = $directory.'/node_'.$this->electrum->name;

        $network = strtolower($this->electrum->config['network'] ?? config('bitcoin.electrum.network', 'mainnet'));
        $args = [
            $binaryPath,
            $executePath,
            '-D',
            $dataDir,
            '--'.$network,
            '--rpcuser',
            $this->electrum->username,
            '--rpcpassword',
            $this->electrum->password,
            ...$params,
        ];
        $args = array_filter($args);

        echo "\n".implode(' ', $args)."\n";

        $process = new Process($args);
        $process->setTimeout(60);
        $process->run();

        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();
        $code = $process->getExitCode() ?? 0;

        if( !$process->isSuccessful() ) {
            throw new \Exception('Electrum CLI '.$code.' - '.$stderr);
        }

        return json_decode($stdout, true) ?? [$stdout];
    }
}
