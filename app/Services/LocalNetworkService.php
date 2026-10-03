<?php

namespace App\Services;

use App\Models\{RestaurantSetting, TablePlayInstallation};
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LocalNetworkService
{
    public function connection(Request $request): array
    {
        $ip = $this->requestIp($request)
            ?? $this->installationIp()
            ?? $this->configuredIp()
            ?? $this->machineIp();

        if (! $ip) {
            throw ValidationException::withMessages([
                'network' => 'No tablet-reachable private IPv4 address was found. Connect this laptop to the restaurant Wi-Fi, open TablePlay Server Manager, and run Network Doctor.',
            ]);
        }

        $serverPort = $this->serverPort($request);
        $reverbPort = (int) config('reverb.servers.reverb.port', 8080);
        $installation = TablePlayInstallation::first();

        return [
            'server_id' => $installation?->installation_uuid,
            'server_name' => RestaurantSetting::value('restaurant_name') ?: 'TablePlay Restaurant',
            'local_ip' => $ip,
            'api_base_url' => "http://{$ip}:{$serverPort}/api/v1",
            'reverb_url' => "ws://{$ip}:{$reverbPort}",
            'admin_url' => "http://{$ip}:{$serverPort}/admin",
        ];
    }

    public function staffPayload(Request $request): array
    {
        $connection = $this->connection($request);
        $payload = [
            'type' => 'tableplay_staff_connection',
            'version' => 1,
            'server_id' => $connection['server_id'],
            'server_name' => $connection['server_name'],
            'api_base_url' => $connection['api_base_url'],
            'reverb_url' => $connection['reverb_url'],
            'generated_at' => now()->toIso8601String(),
        ];
        $payload['signature'] = hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES), (string) config('app.key'));

        return $payload;
    }

    private function requestIp(Request $request): ?string
    {
        return $this->privateIpv4($request->getHost()) ? $request->getHost() : null;
    }

    private function installationIp(): ?string
    {
        $root = config('tableplay.runtime_root');
        if (! is_string($root) || trim($root) === '') return null;
        $path = rtrim($root, '\\/').DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'installation.json';
        if (! is_file($path)) return null;
        $document = json_decode((string) file_get_contents($path), true);
        $value = is_array($document) ? ($document['LocalIp'] ?? $document['local_ip'] ?? null) : null;
        return $this->privateIpv4($value) ? $value : null;
    }

    private function configuredIp(): ?string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        return $this->privateIpv4($host) ? $host : null;
    }

    private function machineIp(): ?string
    {
        $addresses = gethostbynamel(gethostname()) ?: [];
        foreach ($addresses as $address) if ($this->privateIpv4($address)) return $address;
        return null;
    }

    private function serverPort(Request $request): int
    {
        if ($this->privateIpv4($request->getHost()) && $request->getPort()) return $request->getPort();
        $configured = parse_url((string) config('app.url'), PHP_URL_PORT);
        return $configured ? (int) $configured : 8000;
    }

    private function privateIpv4(mixed $value): bool
    {
        if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
        $long = ip2long($value);
        if ($long === false) return false;
        $unsigned = (int) sprintf('%u', $long);
        return ($unsigned >= (int) sprintf('%u', ip2long('10.0.0.0')) && $unsigned <= (int) sprintf('%u', ip2long('10.255.255.255')))
            || ($unsigned >= (int) sprintf('%u', ip2long('172.16.0.0')) && $unsigned <= (int) sprintf('%u', ip2long('172.31.255.255')))
            || ($unsigned >= (int) sprintf('%u', ip2long('192.168.0.0')) && $unsigned <= (int) sprintf('%u', ip2long('192.168.255.255')));
    }
}
