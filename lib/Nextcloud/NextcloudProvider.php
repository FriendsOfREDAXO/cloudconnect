<?php

namespace FriendsOfRedaxo\CloudConnect\Nextcloud;

use FriendsOfRedaxo\Mediaplace\StorageProviderInterface;

/**
 * Duenner Adapter, der NextcloudClient hinter MediaPlace's
 * StorageProviderInterface anbietet. Anders als generisches WebDAV kennt
 * Nextcloud eine Server-Suche (SEARCH-Verb) und eine eigene Vorschaubild-API
 * -- beides wird hier genutzt (Vorbild: nextcloud/lib/MediaplaceProvider.php
 * im eigenstaendigen nextcloud-Addon).
 */
class NextcloudProvider implements StorageProviderInterface
{
    private NextcloudClient $client;

    public function __construct()
    {
        $this->client = new NextcloudClient();
    }

    public function listEntries(string $path, ?string $search = null): array
    {
        $path = '' !== $path ? $path : '/';
        $entries = null !== $search && '' !== trim($search)
            ? $this->client->searchFilesRecursive($path, $search)
            : $this->client->listFiles($path);

        return array_map(function (array $entry): array {
            $isFolder = 'folder' === $entry['type'];

            return [
                'path' => $entry['path'],
                'name' => $entry['name'],
                'type' => $entry['type'],
                'filesize' => $entry['filesize'],
                'filetype' => $entry['filetype'],
                'modified' => $entry['modified'],
                'hasThumbnail' => !$isFolder && null !== $entry['fileid'] && $this->isPreviewableMimeType($entry['mimetype'] ?? ''),
            ];
        }, $entries);
    }

    public function hasSearch(): bool
    {
        return true;
    }

    public function getThumbnail(string $path): ?array
    {
        $fileId = $this->client->getFileId($path);
        if (null === $fileId || '' === $fileId) {
            return null;
        }

        return $this->client->getPreviewContent($fileId, 300, 300);
    }

    public function importToMediaPool(string $path, int $categoryId): string
    {
        $content = $this->client->getContent($path);
        $filename = basename($path);

        $tmpFile = \rex_path::cache('cloudconnect_nextcloud_' . \rex_string::normalize($filename));
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

    /**
     * Nextclouds Preview-API erzeugt zuverlaessig nur fuer Bild-MIME-Typen
     * eine Vorschau -- bewusst konservativ, damit hasThumbnail nicht
     * faelschlich "ja" fuer serverseitig unzuverlaessige Typen meldet (siehe
     * gleiche Begruendung im Original-Adapter des nextcloud-Addons).
     */
    private function isPreviewableMimeType(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/');
    }
}
