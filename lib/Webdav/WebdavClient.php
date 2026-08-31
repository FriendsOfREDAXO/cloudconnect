<?php

namespace FriendsOfRedaxo\CloudConnect\Webdav;

use FriendsOfRedaxo\CloudConnect\ConnectionStore;

/**
 * Schlanker WebDAV-Client fuer einen beliebigen (nicht Nextcloud-
 * spezifischen) WebDAV-Server -- HTTP-Basic-Auth, PROPFIND fuer Listing,
 * GET fuer Download. Vorbild: nextcloud/lib/nextcloud.php (curl + PROPFIND +
 * SimpleXMLElement-Parsing), bewusst OHNE die dortigen ownCloud/Nextcloud-
 * spezifischen Erweiterungen (oc:fileid/oc:tags, Vorschaubild-API, App-
 * Passwort-Sonderbehandlung) -- generisches WebDAV kennt nur den DAV:-
 * Namespace.
 *
 * Anders als im Vorbild: SSL_VERIFYPEER/-HOST sind hier DURCHGAENGIG
 * konfigurierbar (dort war das an einer Stelle inkonsistent hart auf false
 * gesetzt).
 *
 * Mehrere WebDAV-Verbindungen gleichzeitig moeglich (siehe DEV.md) -- der
 * Konstruktor nimmt optional eine Connection-ID; ohne Angabe wird sie aus
 * dem laufenden Request aufgeloest (Aufruf durch MediaPlace selbst, siehe
 * resolveConnectionIdFromRequest()).
 */
class WebdavClient
{
    private const PROVIDER_PREFIX = 'cloudconnect_webdav_';

    private string $connectionId;
    private string $baseUrl;
    private string $username;
    private string $password;
    private bool $sslVerify;

    public function __construct(?string $connectionId = null)
    {
        $this->connectionId = $connectionId ?? self::resolveConnectionIdFromRequest();
        $connection = ConnectionStore::get($this->connectionId);
        if (null === $connection || !ConnectionStore::isComplete($connection)) {
            throw new \rex_exception('WebDAV connection is not configured.');
        }

        $config = $connection['config'];
        $serverUrl = rtrim((string) ($config['url'] ?? ''), '/');
        $rootPath = trim((string) ($config['root'] ?? ''), '/');
        $this->baseUrl = $serverUrl . ('' !== $rootPath ? '/' . $rootPath : '');
        $this->username = (string) ($config['username'] ?? '');
        $this->password = (string) ($config['password'] ?? '');
        $this->sslVerify = (bool) ($config['ssl_verify'] ?? true);
    }

    /**
     * Liest dieselbe Provider-ID, mit der StorageProviderRegistry::getInstance()
     * im selben Request bereits aufgerufen wurde (siehe DEV.md) -- MediaPlace
     * instanziiert Provider-Klassen zero-arg, ein Konstruktor-Parameter ist
     * dafuer nicht moeglich.
     */
    private static function resolveConnectionIdFromRequest(): string
    {
        $full = \rex_request('provider', 'string', '');
        return str_starts_with($full, self::PROVIDER_PREFIX) ? substr($full, strlen(self::PROVIDER_PREFIX)) : '';
    }

    /**
     * @return list<array{path: string, name: string, type: 'folder'|'file', filesize: int|null, filetype: string|null, modified: string|null}>
     */
    public function listFiles(string $path): array
    {
        $body = <<<'XML'
<?xml version="1.0" encoding="utf-8" ?>
<d:propfind xmlns:d="DAV:">
    <d:prop>
        <d:displayname/>
        <d:getcontentlength/>
        <d:getlastmodified/>
        <d:resourcetype/>
        <d:getcontenttype/>
    </d:prop>
</d:propfind>
XML;

        $response = $this->request($this->buildUrl($path), 'PROPFIND', $body, ['Depth: 1', 'Content-Type: application/xml']);
        return $this->parsePropfind($response, $path);
    }

    public function getContent(string $path): string
    {
        return $this->request($this->buildUrl($path), 'GET');
    }

    /**
     * @param list<array{path: string, name: string, type: 'folder'|'file', filesize: int|null, filetype: string|null, modified: string|null}> $entries
     */
    private function parsePropfind(string $xmlBody, string $requestedPath): array
    {
        // Steuerzeichen, die manche Server in Dateinamen/XML einstreuen,
        // wuerden das Parsen sonst hart zum Scheitern bringen.
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $xmlBody);

        libxml_use_internal_errors(true);
        try {
            $xml = new \SimpleXMLElement((string) $clean);
        } catch (\Throwable $e) {
            throw new \rex_exception('Could not parse WebDAV PROPFIND response: ' . $e->getMessage());
        }
        $xml->registerXPathNamespace('d', 'DAV:');

        $requestedPathNormalized = '/' . trim($requestedPath, '/');
        $entries = [];

        foreach ($xml->xpath('//d:response') as $responseNode) {
            $href = (string) $responseNode->xpath('d:href')[0];
            $decodedHref = rawurldecode($href);
            $entryPath = '/' . trim((string) preg_replace('#^.*?' . preg_quote($this->rootUriPath(), '#') . '#', '', $decodedHref), '/');

            // Der erste Eintrag eines Depth:1-PROPFINDs ist der angefragte
            // Ordner selbst -- ueberspringen, sonst erschiene er als eigener
            // (leerer) Ordnereintrag innerhalb seiner selbst.
            if (rtrim($entryPath, '/') === rtrim($requestedPathNormalized, '/')) {
                continue;
            }

            $propNodes = $responseNode->xpath('d:propstat[d:status[contains(text(),"200")]]/d:prop');
            $prop = $propNodes[0] ?? null;
            if (null === $prop) {
                continue;
            }

            $isFolder = 0 < count($prop->xpath('d:resourcetype/d:collection'));
            $name = trim((string) $prop->displayname);
            if ('' === $name) {
                $segments = explode('/', rtrim($entryPath, '/'));
                $name = end($segments);
            }

            $entries[] = [
                'path' => $entryPath,
                'name' => $name,
                'type' => $isFolder ? 'folder' : 'file',
                'filesize' => $isFolder ? null : (int) (string) $prop->getcontentlength,
                'filetype' => $isFolder ? null : strtolower((string) pathinfo($name, PATHINFO_EXTENSION)),
                'modified' => '' !== (string) $prop->getlastmodified ? (string) $prop->getlastmodified : null,
            ];
        }

        return $entries;
    }

    /** Pfad-Anteil der Basis-URL (ohne Schema/Host), fuer den Href-Abgleich oben. */
    private function rootUriPath(): string
    {
        $parts = parse_url($this->baseUrl);
        return (string) ($parts['path'] ?? '');
    }

    private function buildUrl(string $path): string
    {
        $path = '/' === $path ? '' : trim($path, '/');
        $encodedSegments = array_map('rawurlencode', explode('/', $path));
        return $this->baseUrl . ('' !== $path ? '/' . implode('/', $encodedSegments) : '');
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
            throw new \rex_exception('WebDAV request failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new \rex_exception('WebDAV server returned HTTP ' . $status . ' for ' . $method . ' ' . $url);
        }

        return (string) $response;
    }
}
