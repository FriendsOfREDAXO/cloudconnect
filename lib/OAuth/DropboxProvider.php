<?php

namespace FriendsOfRedaxo\CloudConnect\OAuth;

use FriendsOfRedaxo\Mediaplace\StorageProviderInterface;

/**
 * Duenner Adapter, der DropboxClient hinter MediaPlace's
 * StorageProviderInterface anbietet -- rein lesendes Browsen/Suchen +
 * Import in den lokalen Medienpool, kein Sync. Gleiches Muster wie
 * nextcloud/lib/MediaplaceProvider.php.
 */
class DropboxProvider implements StorageProviderInterface
{
    private DropboxClient $client;

    public function __construct()
    {
        $this->client = new DropboxClient();
    }

    public function listEntries(string $path, ?string $search = null): array
    {
        $result = null !== $search && '' !== trim($search)
            ? ['entries' => $this->client->search($path, $search)]
            : $this->client->listFolder($path);

        return array_map(static function (array $entry): array {
            $isFolder = 'folder' === ($entry['.tag'] ?? '');
            $name = (string) ($entry['name'] ?? '');

            return [
                'path' => (string) ($entry['path_display'] ?? $entry['path_lower'] ?? ''),
                'name' => $name,
                'type' => $isFolder ? 'folder' : 'file',
                'filesize' => $isFolder ? null : (int) ($entry['size'] ?? 0),
                'filetype' => $isFolder ? null : strtolower((string) pathinfo($name, PATHINFO_EXTENSION)),
                'modified' => '' !== (string) ($entry['server_modified'] ?? '') ? (string) $entry['server_modified'] : null,
                // Dropbox liefert Vorschaubilder fuer so gut wie jeden gaengigen
                // Bild-/Dokumenttyp -- anders als bei generischem WebDAV lohnt
                // sich hier keine eigene MIME-Whitelist, ein fehlgeschlagener
                // getThumbnail()-Aufruf faengt einen Nicht-Treffer ohnehin ab
                // (liefert null, Client faellt aufs Datei-Icon zurueck).
                'hasThumbnail' => !$isFolder,
            ];
        }, $result['entries']);
    }

    public function hasSearch(): bool
    {
        return true;
    }

    public function getThumbnail(string $path): ?array
    {
        return $this->client->getThumbnail($path);
    }

    public function importToMediaPool(string $path, int $categoryId): string
    {
        $content = $this->client->download($path);
        $filename = basename($path);

        $tmpFile = \rex_path::cache('cloudconnect_dropbox_' . \rex_string::normalize($filename));
        \rex_file::put($tmpFile, $content);

        try {
            $data = [
                'title' => $filename,
                'category_id' => $categoryId,
                'file' => [
                    'name' => $filename,
                    'path' => $tmpFile,
                ],
            ];
            $result = \rex_media_service::addMedia($data, true);
            if (empty($result['ok']) || empty($result['filename'])) {
                throw new \rex_exception((string) ($result['msg'] ?? 'Import fehlgeschlagen'));
            }

            return (string) $result['filename'];
        } finally {
            \rex_file::delete($tmpFile);
        }
    }
}
