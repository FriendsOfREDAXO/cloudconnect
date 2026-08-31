<?php

namespace FriendsOfRedaxo\CloudConnect\Nextcloud;

use FriendsOfRedaxo\CloudConnect\ConnectionStore;

/**
 * Nextcloud-spezifischer WebDAV-Client -- anders als der generische
 * WebdavClient (siehe lib/Webdav/WebdavClient.php) fest auf Nextclouds
 * Datei-Endpunkt ('/remote.php/dav/files/<username>/...') verdrahtet und
 * nutzt zwei Nextcloud-eigene Erweiterungen, die generisches WebDAV nicht
 * kennt: das oc:fileid-Property (fuer die Vorschaubild-API) und das
 * SEARCH-Verb (serverseitige Volltextsuche ueber alle Unterordner) sowie
 * Nextclouds eigene Preview-API fuer Thumbnails. Konfiguration: Basis-URL
 * (Nextcloud-Wurzel, OHNE /remote.php/dav/...), Benutzername, App-Passwort
 * (siehe README fuer die Erzeugung), optionaler Root-Ordner.
 *
 * Urspruenglich portiert aus dem eigenstaendigen nextcloud-Addon
 * (lib/nextcloud.php), inzwischen umgekehrt: das nextcloud-Addon ist jetzt
 * selbst ein Client DIESER Klasse (kein eigener WebDAV/OCS-Unterbau mehr).
 * Enthaelt neben dem fuer MediaPlace-Browsing/Import noetigen Ausschnitt
 * (listFiles/searchFilesRecursive/getContent/getFileId/getPreviewContent)
 * deshalb auch generische Transport-Bausteine, die nur vom nextcloud-Addon
 * gebraucht werden (putContent/deleteFile/getFileTags/createShareLink) --
 * MediaPlace selbst nutzt diese nicht, sie kosten aber nichts, wenn
 * ungenutzt. Die eigentliche FEATURE-Logik (ZIP-Bau, Share-Link-Caching,
 * Tag-zu-rex_media-Mapping, Backup-Orchestrierung) bleibt bewusst im
 * nextcloud-Addon selbst -- diese Klasse liefert nur die authentifizierte
 * HTTP-Transportarbeit fuer eine einzelne Verbindung.
 *
 * Mehrere Nextcloud-Verbindungen gleichzeitig moeglich (siehe DEV.md) --
 * Konstruktor nimmt optional eine Connection-ID, sonst Auto-Resolve aus dem
 * laufenden Request (analog WebdavClient).
 */
class NextcloudClient
{
    private const PROVIDER_PREFIX = 'cloudconnect_nextcloud_';

    private string $connectionId;
    private string $baseUrl;
    private string $username;
    private string $password;
    private string $rootFolder;
    private bool $sslVerify;

    public function __construct(?string $connectionId = null)
    {
        $this->connectionId = $connectionId ?? self::resolveConnectionIdFromRequest();
        $connection = ConnectionStore::get($this->connectionId);
        if (null === $connection || !ConnectionStore::isComplete($connection)) {
            throw new \rex_exception('Nextcloud connection is not configured.');
        }

        $config = $connection['config'];
        $this->baseUrl = rtrim((string) ($config['url'] ?? ''), '/');
        $this->username = (string) ($config['username'] ?? '');
        $this->password = (string) ($config['password'] ?? '');
        $this->rootFolder = '/' . trim((string) ($config['root'] ?? ''), '/');
        if ('/' === $this->rootFolder) {
            $this->rootFolder = '';
        }
        $this->sslVerify = (bool) ($config['ssl_verify'] ?? true);
    }

    private static function resolveConnectionIdFromRequest(): string
    {
        $full = \rex_request('provider', 'string', '');
        return str_starts_with($full, self::PROVIDER_PREFIX) ? substr($full, strlen(self::PROVIDER_PREFIX)) : '';
    }

    /**
     * @return list<array{path: string, name: string, type: 'folder'|'file', filesize: int|null, filetype: string|null, modified: string|null, fileid: string|null, mimetype: string|null}>
     */
    public function listFiles(string $path): array
    {
        $body = <<<'XML'
<?xml version="1.0" encoding="utf-8" ?>
<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
    <d:prop>
        <d:displayname/>
        <d:getcontentlength/>
        <d:getlastmodified/>
        <d:resourcetype/>
        <d:getcontenttype/>
        <oc:fileid/>
    </d:prop>
</d:propfind>
XML;

        $response = $this->requestPropfindCustom($this->buildWebDavUrl($path), $body, 1);
        return $this->parseMultistatusResponse($response, $path);
    }

    /**
     * @return list<array{path: string, name: string, type: 'folder'|'file', filesize: int|null, filetype: string|null, modified: string|null, fileid: string|null, mimetype: string|null}>
     */
    public function searchFilesRecursive(string $basePath, string $query): array
    {
        $normalizedQuery = trim($query);
        if ('' === $normalizedQuery) {
            return $this->listFiles($basePath);
        }

        $scopePath = '/files/' . $this->username . $this->rootFolder . ('/' === $basePath ? '' : $basePath);
        $escapeXml = static fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $body = '<?xml version="1.0" encoding="UTF-8"?>
<d:searchrequest xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
    <d:basicsearch>
        <d:select>
            <d:prop>
                <d:displayname/>
                <d:getcontentlength/>
                <d:getcontenttype/>
                <d:getlastmodified/>
                <d:resourcetype/>
                <oc:fileid/>
            </d:prop>
        </d:select>
        <d:from>
            <d:scope>
                <d:href>' . $escapeXml($scopePath) . '</d:href>
                <d:depth>infinity</d:depth>
            </d:scope>
        </d:from>
        <d:where>
            <d:like>
                <d:prop><d:displayname/></d:prop>
                <d:literal>%' . $escapeXml($normalizedQuery) . '%</d:literal>
            </d:like>
        </d:where>
        <d:limit><d:nresults>500</d:nresults></d:limit>
    </d:basicsearch>
</d:searchrequest>';

        $response = $this->request($this->baseUrl . '/remote.php/dav/', 'SEARCH', $body, ['Content-Type: text/xml; charset=utf-8']);
        return $this->parseMultistatusResponse($response, null);
    }

    public function getContent(string $path): string
    {
        return $this->request($this->buildWebDavUrl($path), 'GET');
    }

    /**
     * WebDAV PUT -- schreibt/ueberschreibt eine Datei unter $path. Kein
     * automatisches Anlegen fehlender Elternordner (MKCOL): der Aufrufer
     * (nextcloud-Addon) zielt immer auf einen bereits existierenden,
     * gerade durchsuchten Ordner.
     */
    public function putContent(string $path, string $content): void
    {
        $this->request($this->buildWebDavUrl($path), 'PUT', $content);
    }

    /**
     * WebDAV DELETE einer einzelnen Datei/eines Ordners unter $path.
     */
    public function deleteFile(string $path): void
    {
        $this->request($this->buildWebDavUrl($path), 'DELETE');
    }

    /**
     * Gezielter (Depth 0) Lookup der Nextcloud-internen fileid fuer genau
     * einen Pfad -- gebraucht von NextcloudProvider::getThumbnail(), da
     * MediaPlace dort nur den Pfad uebergibt, nicht die schon beim Browsen
     * geladene fileid.
     */
    public function getFileId(string $path): ?string
    {
        $body = <<<'XML'
<?xml version="1.0" encoding="utf-8" ?>
<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
    <d:prop>
        <oc:fileid/>
    </d:prop>
</d:propfind>
XML;

        try {
            $response = $this->requestPropfindCustom($this->buildWebDavUrl($path), $body, 0);
        } catch (\Throwable) {
            return null;
        }

        $entries = $this->parseMultistatusResponse($response, null);
        return $entries[0]['fileid'] ?? null;
    }

    /**
     * Nextclouds eigene Vorschau-/Thumbnail-API (nicht das volle Original) --
     * braucht die Nextcloud-interne fileid, nicht den WebDAV-Pfad. Liefert
     * null statt einer Exception, wenn keine Vorschau erzeugt werden kann
     * (Aufrufer faellt dann auf das Datei-Icon zurueck).
     *
     * @return array{content: string, contentType: string}|null
     */
    public function getPreviewContent(string $fileId, int $width = 300, int $height = 300): ?array
    {
        if ('' === $fileId) {
            return null;
        }

        $url = $this->baseUrl . '/index.php/core/preview?fileId=' . rawurlencode($fileId)
            . '&x=' . max(1, $width) . '&y=' . max(1, $height) . '&a=1&mode=cover';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->username . ':' . $this->password,
            CURLOPT_SSL_VERIFYPEER => $this->sslVerify,
            CURLOPT_SSL_VERIFYHOST => $this->sslVerify ? 2 : 0,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 15,
        ]);

        $content = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $curlError = curl_errno($ch);
        curl_close($ch);

        if (0 !== $curlError || $httpCode < 200 || $httpCode >= 300 || !is_string($content) || '' === $content) {
            return null;
        }

        return [
            'content' => $content,
            'contentType' => is_string($contentType) && '' !== $contentType ? $contentType : 'image/png',
        ];
    }

    /**
     * Nextcloud-Tags + interne fileid fuer einen Pfad (oc:tags/oc:fileid via
     * PROPFIND) -- fuer das nextcloud-Addons Tag-zu-rex_media-Mapping-Feature.
     * Liefert leere Werte statt einer Exception bei Fehlern (Aufrufer soll
     * einen fehlenden/kaputten Tag-Abruf nicht als harten Fehler werten).
     *
     * @return array{fileid: string|null, tags: list<string>}
     */
    public function getFileTags(string $path): array
    {
        $body = <<<'XML'
<?xml version="1.0" encoding="utf-8" ?>
<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
    <d:prop>
        <oc:fileid/>
        <oc:tags/>
    </d:prop>
</d:propfind>
XML;

        try {
            $response = $this->requestPropfindCustom($this->buildWebDavUrl($path), $body, 0);
        } catch (\Throwable) {
            return ['fileid' => null, 'tags' => []];
        }

        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $response);
        libxml_use_internal_errors(true);
        try {
            $xml = new \SimpleXMLElement((string) $clean);
        } catch (\Throwable) {
            return ['fileid' => null, 'tags' => []];
        }
        $xml->registerXPathNamespace('d', 'DAV:');
        $xml->registerXPathNamespace('oc', 'http://owncloud.org/ns');

        $fileIdNodes = $xml->xpath('//oc:fileid');
        $fileId = [] !== $fileIdNodes ? (string) $fileIdNodes[0] : null;

        // Nextcloud liefert Tags entweder als <oc:tag>-Kindelemente oder als
        // kommaseparierten Text (je nach Server-Version) -- beide Formen
        // abdecken.
        $tags = [];
        $tagChildren = $xml->xpath('//oc:tags/oc:tag');
        if ([] !== $tagChildren) {
            foreach ($tagChildren as $tag) {
                $value = trim((string) $tag);
                if ('' !== $value) {
                    $tags[] = $value;
                }
            }
        } else {
            $tagNodes = $xml->xpath('//oc:tags');
            if ([] !== $tagNodes) {
                $text = trim((string) $tagNodes[0]);
                if ('' !== $text) {
                    $tags = array_values(array_filter(array_map('trim', explode(',', $text))));
                }
            }
        }

        return ['fileid' => $fileId, 'tags' => $tags];
    }

    /**
     * Erstellt einen oeffentlichen Share-Link ueber Nextclouds OCS Share API
     * -- fuer das nextcloud-Addons Share-Link-Feature (Caching/UI bleibt dort).
     *
     * @return array{url: string, token: string, id: int, expiration: string|null}
     */
    public function createShareLink(string $displayPath, ?string $expireDate = null, int $permissions = 1): array
    {
        $params = [
            'path' => $this->buildSharePath($displayPath),
            'shareType' => 3, // 3 = oeffentlicher Link
            'permissions' => $permissions,
        ];

        if (null !== $expireDate && '' !== $expireDate) {
            $timestamp = strtotime($expireDate);
            if (false !== $timestamp) {
                $params['expireDate'] = date('Y-m-d', $timestamp);
            }
        }

        $result = $this->requestOcs('/apps/files_sharing/api/v1/shares', 'POST', $params);

        $meta = $result['ocs']['meta'] ?? [];
        if ('ok' !== ($meta['status'] ?? '')) {
            $code = $meta['statuscode'] ?? 'unknown';
            $message = $meta['message'] ?? 'Unknown error';
            throw new \rex_exception('Share link could not be created (' . $code . '): ' . $message);
        }

        $data = $result['ocs']['data'] ?? [];

        return [
            'url' => (string) ($data['url'] ?? ''),
            'token' => (string) ($data['token'] ?? ''),
            'id' => (int) ($data['id'] ?? 0),
            'expiration' => $data['expiration'] ?? null,
        ];
    }

    private function buildSharePath(string $displayPath): string
    {
        $decoded = '/' . ltrim(rawurldecode($displayPath), '/');
        if ('' === $this->rootFolder) {
            return $decoded;
        }

        return rtrim($this->rootFolder, '/') . $decoded;
    }

    /**
     * @param array<string, mixed> $params POST-Parameter
     *
     * @return array<mixed>
     */
    private function requestOcs(string $endpoint, string $method = 'GET', array $params = []): array
    {
        $url = $this->baseUrl . '/ocs/v2.php' . $endpoint . '?format=json';

        $headers = ['OCS-APIRequest: true', 'Accept: application/json'];
        $postBody = '';
        if ('POST' === $method) {
            $stringParams = [];
            foreach ($params as $key => $value) {
                $stringParams[(string) $key] = (string) $value;
            }
            $postBody = http_build_query($stringParams, '', '&');
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        $ch = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->username . ':' . $this->password,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $this->sslVerify,
            CURLOPT_SSL_VERIFYHOST => $this->sslVerify ? 2 : 0,
            CURLOPT_HEADER => false,
            // FOLLOWLOCATION bei POST bewusst aus: cURL wuerde POST->GET
            // konvertieren und den Body (inkl. shareType) beim Redirect verlieren.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ];
        if ('POST' === $method) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $postBody;
        } else {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
        }
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if (0 !== $errno) {
            throw new \rex_exception('OCS API request failed: ' . $error);
        }
        if (in_array($httpCode, [301, 302, 307, 308], true)) {
            throw new \rex_exception('OCS API: Nextcloud redirected (HTTP ' . $httpCode . '). Check the configured base URL (use HTTPS, remove any trailing slash).');
        }
        if ($httpCode < 200 || $httpCode >= 400) {
            $detail = '';
            if (is_string($response) && '' !== $response) {
                $decoded = json_decode($response, true);
                $detail = ' — ' . (is_array($decoded) ? (string) ($decoded['ocs']['meta']['message'] ?? substr($response, 0, 300)) : substr($response, 0, 300));
            }
            throw new \rex_exception('OCS API request failed (HTTP ' . $httpCode . ')' . $detail);
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            throw new \rex_exception('OCS API: invalid JSON response');
        }

        return $data;
    }

    private function buildWebDavUrl(string $path): string
    {
        $normalizedPath = '/' === $path ? '' : '/' . trim($path, '/');
        $fullPath = $this->rootFolder . $normalizedPath;
        $segments = array_filter(explode('/', $fullPath), static fn (string $segment): bool => '' !== $segment);
        $encodedPath = implode('/', array_map('rawurlencode', $segments));

        return $this->baseUrl . '/remote.php/dav/files/' . rawurlencode($this->username)
            . ('' !== $encodedPath ? '/' . $encodedPath : '');
    }

    /**
     * @param list<string> $extraHeaders
     */
    private function request(string $url, string $method, ?string $body = null, array $extraHeaders = []): string
    {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERPWD => $this->username . ':' . $this->password,
            CURLOPT_SSL_VERIFYPEER => $this->sslVerify,
            CURLOPT_SSL_VERIFYHOST => $this->sslVerify ? 2 : 0,
            CURLOPT_HTTPHEADER => $extraHeaders,
            CURLOPT_FOLLOWLOCATION => true,
        ];
        if (null !== $body) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (0 !== $errno) {
            throw new \rex_exception('Nextcloud request failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new \rex_exception('Nextcloud server returned HTTP ' . $status . ' for ' . $method . ' ' . $url);
        }

        return (string) $response;
    }

    private function requestPropfindCustom(string $url, string $body, int $depth): string
    {
        return $this->request($url, 'PROPFIND', $body, [
            'Content-Type: application/xml; charset=utf-8',
            'Depth: ' . $depth,
        ]);
    }

    /**
     * Gemeinsames Parsing fuer PROPFIND- (listFiles) und SEARCH-Antworten
     * (searchFilesRecursive) -- beide liefern ein WebDAV-Multistatus-Dokument
     * mit identischer d:response/d:href/d:propstat/d:prop-Struktur.
     *
     * @return list<array{path: string, name: string, type: 'folder'|'file', filesize: int|null, filetype: string|null, modified: string|null, fileid: string|null, mimetype: string|null}>
     */
    private function parseMultistatusResponse(string $xmlBody, ?string $skipPath): array
    {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $xmlBody);

        libxml_use_internal_errors(true);
        try {
            $xml = new \SimpleXMLElement((string) $clean);
        } catch (\Throwable $e) {
            throw new \rex_exception('Could not parse Nextcloud WebDAV response: ' . $e->getMessage());
        }
        $xml->registerXPathNamespace('d', 'DAV:');
        $xml->registerXPathNamespace('oc', 'http://owncloud.org/ns');

        $davPrefixPattern = '#^/remote\.php/dav/files/' . preg_quote($this->username, '#') . '#';
        $skipPathNormalized = null !== $skipPath ? '/' . trim($skipPath, '/') : null;

        $entries = [];
        foreach ($xml->xpath('//d:response') as $responseNode) {
            $hrefNodes = $responseNode->xpath('d:href');
            $propNodes = $responseNode->xpath('d:propstat[d:status[contains(text(),"200")]]/d:prop');
            if ([] === $hrefNodes || [] === $propNodes) {
                continue;
            }

            $href = rawurldecode((string) $hrefNodes[0]);
            $relativePath = (string) preg_replace($davPrefixPattern, '', $href);
            $entryPath = $relativePath;
            if ('' !== $this->rootFolder) {
                $entryPath = (string) preg_replace('#^' . preg_quote($this->rootFolder, '#') . '#', '', $relativePath);
            }
            $entryPath = '/' . trim((string) preg_replace('#/+#', '/', $entryPath), '/');

            if (null !== $skipPathNormalized && rtrim($entryPath, '/') === rtrim($skipPathNormalized, '/')) {
                continue;
            }

            $prop = $propNodes[0];
            $isFolder = [] !== $prop->xpath('d:resourcetype/d:collection');
            $name = trim((string) $prop->displayname);
            if ('' === $name) {
                $segments = explode('/', rtrim($entryPath, '/'));
                $name = (string) end($segments);
            }
            if ('' === $name) {
                continue;
            }

            $fileIdNodes = $prop->xpath('oc:fileid');
            $fileId = [] !== $fileIdNodes ? (string) $fileIdNodes[0] : null;
            $mimeType = '' !== (string) $prop->getcontenttype ? (string) $prop->getcontenttype : null;

            $entries[] = [
                'path' => $entryPath,
                'name' => $name,
                'type' => $isFolder ? 'folder' : 'file',
                'filesize' => $isFolder ? null : (int) (string) $prop->getcontentlength,
                'filetype' => $isFolder ? null : strtolower((string) pathinfo($name, PATHINFO_EXTENSION)),
                'modified' => '' !== (string) $prop->getlastmodified ? (string) $prop->getlastmodified : null,
                'fileid' => $fileId,
                'mimetype' => $mimeType,
            ];
        }

        return $entries;
    }
}
