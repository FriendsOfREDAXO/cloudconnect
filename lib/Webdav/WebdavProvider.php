<?php

namespace FriendsOfRedaxo\CloudConnect\Webdav;

use FriendsOfRedaxo\Mediaplace\StorageProviderInterface;

/**
 * Duenner Adapter, der WebdavClient hinter MediaPlace's
 * StorageProviderInterface anbietet. Generisches WebDAV kennt weder eine
 * Server-Suche noch eine Standard-Thumbnail-API -- hasSearch() ist deshalb
 * bewusst false und getThumbnail() liefert immer null (Client faellt aufs
 * etablierte Datei-Icon-Fallback zurueck, siehe StorageProviderInterface-
 * Docblock).
 */
class WebdavProvider implements StorageProviderInterface
{
    private WebdavClient $client;

    public function __construct()
    {
        $this->client = new WebdavClient();
    }

    public function listEntries(string $path, ?string $search = null): array
    {
        // Kein Standard-WebDAV-Suchmechanismus (das SEARCH-Verb ist ein
        // proprietaeres Microsoft-Exchange-Relikt, kein verbreiteter
        // Standard) -- hasSearch()=false, MediaPlace ruft $search hier
        // deshalb nie mit einem Wert auf. $search dennoch im Vertrag
        // belassen (Interface-Konformitaet), einfach ignoriert.
        return array_map(static function (array $entry): array {
            return [
                'path' => $entry['path'],
                'name' => $entry['name'],
                'type' => $entry['type'],
                'filesize' => $entry['filesize'],
                'filetype' => $entry['filetype'],
                'modified' => $entry['modified'],
                'hasThumbnail' => false,
            ];
        }, $this->client->listFiles($path));
    }

    public function hasSearch(): bool
    {
        return false;
    }

    public function getThumbnail(string $path): ?array
    {
        return null;
    }

    public function importToMediaPool(string $path, int $categoryId): string
    {
        $content = $this->client->getContent($path);
        $filename = basename($path);

        $tmpFile = \rex_path::cache('cloudconnect_webdav_' . \rex_string::normalize($filename));
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
