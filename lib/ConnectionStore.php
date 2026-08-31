<?php

namespace FriendsOfRedaxo\CloudConnect;

/**
 * Zentrale CRUD-Stelle fuer alle konfigurierten Verbindungen (mehrere pro
 * Quelltyp moeglich, siehe DEV.md "Mehrere Verbindungen pro Quelltyp"),
 * ersetzt das fruehere OAuthTokenStore + die verstreuten
 * rex_config::get('cloudconnect','webdav_url',...)-Aufrufe in den Clients.
 *
 * Ein einziger rex_config-Schluessel `connections`, Wert ist ein
 * assoziatives Array `id => ['type'=>..., 'label'=>..., 'active'=>bool,
 * 'config'=>[...typspezifische Felder...]]`. rex_config serialisiert Arrays
 * selbst (JSON in DB + Datei-Cache), kein manuelles json_encode/decode noetig.
 */
class ConnectionStore
{
    private const NAMESPACE = 'cloudconnect';
    private const CONFIG_KEY = 'connections';

    public const TYPES = ['webdav', 'nextcloud', 'dropbox'];

    /**
     * @return array<string, array{type: string, label: string, active: bool, config: array<string, mixed>}>
     */
    public static function getAll(): array
    {
        $raw = \rex_config::get(self::NAMESPACE, self::CONFIG_KEY, []);
        return is_array($raw) ? $raw : [];
    }

    /**
     * @return array<string, array{type: string, label: string, active: bool, config: array<string, mixed>}>
     */
    public static function getByType(string $type): array
    {
        return array_filter(self::getAll(), static fn (array $c): bool => $type === ($c['type'] ?? ''));
    }

    /**
     * @return array<string, array{type: string, label: string, active: bool, config: array<string, mixed>}>
     */
    public static function getActiveByType(string $type): array
    {
        return array_filter(self::getByType($type), static fn (array $c): bool => (bool) ($c['active'] ?? false));
    }

    /**
     * @return array{type: string, label: string, active: bool, config: array<string, mixed>}|null
     */
    public static function get(string $id): ?array
    {
        $all = self::getAll();
        return $all[$id] ?? null;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function create(string $type, string $label, bool $active, array $config): string
    {
        $id = bin2hex(random_bytes(4));
        $all = self::getAll();
        $all[$id] = [
            'type' => $type,
            'label' => $label,
            'active' => $active,
            'config' => $config,
        ];
        \rex_config::set(self::NAMESPACE, self::CONFIG_KEY, $all);

        return $id;
    }

    /**
     * @param array<string, mixed> $fields z.B. ['label'=>..., 'active'=>...]
     */
    public static function update(string $id, array $fields): void
    {
        $all = self::getAll();
        if (!isset($all[$id])) {
            return;
        }
        $all[$id] = array_merge($all[$id], $fields);
        \rex_config::set(self::NAMESPACE, self::CONFIG_KEY, $all);
    }

    /**
     * Merged nur die uebergebenen Config-Felder rein (z.B. Token-Refresh:
     * nur access_token/expires_at, ohne den Rest der Config anzufassen).
     *
     * @param array<string, mixed> $configFields
     */
    public static function updateConfig(string $id, array $configFields): void
    {
        $all = self::getAll();
        if (!isset($all[$id])) {
            return;
        }
        $all[$id]['config'] = array_merge($all[$id]['config'] ?? [], $configFields);
        \rex_config::set(self::NAMESPACE, self::CONFIG_KEY, $all);
    }

    public static function setActive(string $id, bool $active): void
    {
        self::update($id, ['active' => $active]);
    }

    public static function delete(string $id): void
    {
        $all = self::getAll();
        unset($all[$id]);
        \rex_config::set(self::NAMESPACE, self::CONFIG_KEY, $all);
    }

    /**
     * Typspezifische Pflichtfeld-Pruefung -- ersetzt die frueheren
     * WebdavClient::isConfigured()/NextcloudClient::isConfigured()-Statics.
     *
     * @param array{type: string, config: array<string, mixed>} $connection
     */
    public static function isComplete(array $connection): bool
    {
        $config = $connection['config'] ?? [];
        return match ($connection['type'] ?? '') {
            'webdav', 'nextcloud' => '' !== (string) ($config['url'] ?? '')
                && '' !== (string) ($config['username'] ?? '')
                && '' !== (string) ($config['password'] ?? ''),
            'dropbox' => '' !== (string) ($config['client_id'] ?? '')
                && '' !== (string) ($config['client_secret'] ?? ''),
            default => false,
        };
    }

    /**
     * Nur fuer OAuth-Typen relevant: liegt bereits ein Refresh-Token vor?
     *
     * @param array{config: array<string, mixed>} $connection
     */
    public static function isConnected(array $connection): bool
    {
        return '' !== (string) ($connection['config']['refresh_token'] ?? '');
    }
}
