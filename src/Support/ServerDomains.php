<?php

namespace RustixDev\Guides\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Facades\Extensions;
use Pterodactyl\Models\Server;
use Throwable;

/**
 * Reads the server's domain from the Domains extension when it is installed. Nothing
 * here depends on that extension's code: only its table is read, and when it is missing
 * or the extension is off the server simply has no domain.
 */
final class ServerDomains
{
    public const string TABLE = 'ext_domains_domains';

    /** DNS states in which the domain points at the server. */
    public const array LIVE = ['active', 'verified'];

    /**
     * The working domain for the server's primary allocation, if any.
     *
     * @return array{name: string, srv: bool}|null
     */
    public static function primary(Server $server): ?array
    {
        try {
            if (! Extensions::isAvailable('domains') || ! Schema::hasTable(self::TABLE)) {
                return null;
            }

            $rows = DB::table(self::TABLE)
                ->where('server_id', $server->id)
                ->whereIn('dns_status', self::LIVE)
                ->orderBy('id')
                ->get(['kind', 'name', 'allocation_id', 'port', 'srv_service', 'dns_records', 'dns_status', 'dns_error'])
                ->map(fn (object $row) => (array) $row)
                ->all();
        } catch (Throwable) {
            return null;
        }

        return self::pick($rows, $server->allocation_id);
    }

    /**
     * Picks the primary allocation's domain: one with a working SRV record first, then
     * the oldest. A domain without an allocation belongs to the primary one.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{name: string, srv: bool}|null
     */
    public static function pick(array $rows, ?int $primaryAllocationId): ?array
    {
        $best = null;
        foreach ($rows as $row) {
            if (! in_array($row['dns_status'] ?? null, self::LIVE, true) || ! is_string($row['name'] ?? null) || $row['name'] === '') {
                continue;
            }
            $allocation = isset($row['allocation_id']) ? (int) $row['allocation_id'] : $primaryAllocationId;
            if ($allocation === null || $allocation !== $primaryAllocationId) {
                continue;
            }

            $domain = ['name' => $row['name'], 'srv' => self::hasSrv($row)];
            if ($best === null || ($domain['srv'] && ! $best['srv'])) {
                $best = $domain;
            }
        }

        return $best;
    }

    /** @param array<string, mixed> $row */
    private static function hasSrv(array $row): bool
    {
        if (empty($row['srv_service']) || ! isset($row['port'])) {
            return false;
        }
        if (($row['kind'] ?? 'subdomain') === 'own') {
            return $row['dns_status'] === 'verified' && ! str_contains((string) ($row['dns_error'] ?? ''), 'SRV record');
        }
        $records = is_string($row['dns_records'] ?? null) ? json_decode($row['dns_records'], true) : ($row['dns_records'] ?? null);

        return is_array($records) && isset($records['srv']);
    }
}
