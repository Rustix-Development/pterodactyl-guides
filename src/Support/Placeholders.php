<?php

namespace RustixDev\Guides\Support;

use Pterodactyl\Models\Server;

/** The values guides can use as {{server.*}} placeholders. */
final class Placeholders
{
    public const array KEYS = ['server.name', 'server.address', 'server.ip', 'server.port', 'server.domain'];

    /** @return array<string, string> */
    public static function forServer(Server $server): array
    {
        $server->loadMissing('allocation', 'node');
        $allocation = $server->allocation;

        $ip = $allocation ? ($allocation->ip_alias ?: $allocation->ip) : '';
        if ($ip === '' || $ip === '0.0.0.0' || $ip === '::') {
            $ip = (string) ($server->node->fqdn ?? $ip);
        }
        $port = $allocation ? (string) $allocation->port : '';

        return self::build($server->name, $ip, $port, ServerDomains::primary($server));
    }

    /**
     * @param  array{name: string, srv: bool}|null  $domain
     * @return array<string, string>
     */
    public static function build(string $name, string $ip, string $port, ?array $domain): array
    {
        $host = str_contains($ip, ':') && ! str_starts_with($ip, '[') ? '['.$ip.']' : $ip;
        $ipAddress = $port === '' ? $host : $host.':'.$port;
        $address = $domain === null ? $ipAddress : ($domain['srv'] || $port === '' ? $domain['name'] : $domain['name'].':'.$port);

        return [
            'server.name' => $name,
            'server.address' => $address,
            'server.ip' => $ip,
            'server.port' => $port,
            'server.domain' => $domain['name'] ?? $address,
        ];
    }

    /** Replace known placeholders; unknown ones stay as written. */
    public static function fill(string $text, array $values): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*(server\.[a-z]+)\s*\}\}/',
            fn (array $m) => $values[$m[1]] ?? $m[0],
            $text,
        );
    }
}
