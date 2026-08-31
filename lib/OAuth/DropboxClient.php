<?php

namespace FriendsOfRedaxo\CloudConnect\OAuth;

/**
 * Duenner Dropbox-API-v2-Client (nur die fuer StorageProviderInterface
 * benoetigten Operationen: Auflisten, Suchen, Thumbnail, Download). Reines
 * cURL + json_encode/json_decode, wie schon der WebDAV-Client -- keine
 * weitere HTTP-Abstraktion noetig, league/oauth2-client bringt Guzzle nur
 * fuer den Token-Austausch selbst mit (siehe AbstractOAuthClient).
 *
 * API-Referenz: https://www.dropbox.com/developers/documentation/http/documentation
 */
class DropboxClient extends AbstractOAuthClient
{
    private const API_BASE = 'https://api.dropboxapi.com/2';
    private const CONTENT_BASE = 'https://content.dropboxapi.com/2';
    private const AUTHORIZE_URL = 'https://www.dropbox.com/oauth2/authorize';
    private const TOKEN_URL = 'https://api.dropboxapi.com/oauth2/token';

    protected function providerType(): string
    {
        return 'dropbox';
    }

    protected function authorizeUrl(): string
    {
        return self::AUTHORIZE_URL;
    }

    protected function tokenUrl(): string
    {
        return self::TOKEN_URL;
    }

    protected function scopes(): array
    {
        // Scoped-App-Berechtigungen muessen zusaetzlich in der Dropbox-App-
        // Konsole aktiviert werden (files.metadata.read/files.content.read/
        // account_info.read) -- der Scope-Parameter hier allein reicht bei
        // Scoped Apps nicht, siehe README dieses Addons. account_info.read
        // wird fuer fetchAccountInfo() gebraucht (/users/get_current_account,
        // liefert den Anzeigenamen fuer die "Verbunden als ..."-Anzeige auf
        // der Settings-Seite) -- ohne diesen Scope schlaegt der Aufruf mit
        // HTTP 401 missing_scope fehl, obwohl der Token-Austausch selbst
        // bereits erfolgreich war.
        return ['files.metadata.read', 'files.content.read', 'account_info.read'];
    }

    protected function extraAuthorizationParams(): array
    {
        // Ohne token_access_type=offline liefert Dropbox beim Consent KEIN
        // refresh_token, das Access-Token liefe nach ca. 4h aus und der User
        // muesste sich staendig neu verbinden.
        return ['token_access_type' => 'offline'];
    }

    /**
     * @return array{name: string, account_id: string}
     */
    public function fetchAccountInfo(): array
    {
        $result = $this->apiCall('/users/get_current_account', []);
        return [
            'name' => (string) ($result['name']['display_name'] ?? ''),
            'account_id' => (string) ($result['account_id'] ?? ''),
        ];
    }

    /**
     * @return array{entries: list<array<string, mixed>>, has_more: bool, cursor: string|null}
     */
    public function listFolder(string $path): array
    {
        // Dropbox nutzt "" (leerer String) fuer die Wurzel, nicht "/".
        $dropboxPath = '/' === $path || '' === $path ? '' : $path;
        $result = $this->apiCall('/files/list_folder', ['path' => $dropboxPath]);

        return [
            'entries' => is_array($result['entries'] ?? null) ? $result['entries'] : [],
            'has_more' => (bool) ($result['has_more'] ?? false),
            'cursor' => isset($result['cursor']) ? (string) $result['cursor'] : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $path, string $query): array
    {
        $dropboxPath = '/' === $path || '' === $path ? '' : $path;
        $result = $this->apiCall('/files/search_v2', [
            'query' => $query,
            'options' => ['path' => $dropboxPath, 'filename_only' => false],
        ]);

        $matches = is_array($result['matches'] ?? null) ? $result['matches'] : [];
        $entries = [];
        foreach ($matches as $match) {
            $metadata = $match['metadata']['metadata'] ?? null;
            if (is_array($metadata)) {
                $entries[] = $metadata;
            }
        }

        return $entries;
    }

    /**
     * @return array{content: string, contentType: string}|null
     */
    public function getThumbnail(string $path): ?array
    {
        try {
            [$body, $contentType] = $this->contentCall('/files/get_thumbnail_v2', [
                'resource' => ['.tag' => 'path', 'path' => $path],
                'format' => 'jpeg',
                'size' => 'w128h128',
                'mode' => 'strict',
            ]);
        } catch (\Throwable) {
            return null;
        }

        return ['content' => $body, 'contentType' => $contentType];
    }

    public function download(string $path): string
    {
        [$body] = $this->contentCall('/files/download', ['path' => $path]);
        return $body;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function apiCall(string $endpoint, array $payload): array
    {
        $token = $this->freshAccessToken();
        $ch = curl_init(self::API_BASE . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true,
            // json_encode([]) liefert "[]" (JSON-Array) -- Endpunkte ohne
            // Parameter (z.B. /users/get_current_account) erwarten aber
            // "null" oder ein JSON-Objekt, kein Array. Mit einem leeren Array
            // schlaegt Dropbox mit HTTP 400 "expected string or object, got
            // array" fehl, obwohl Auth/Scope laengst korrekt sind.
            CURLOPT_POSTFIELDS => json_encode([] === $payload ? null : $payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
        ]);
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (0 !== $errno) {
            throw new \rex_exception('Dropbox request failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new \rex_exception('Dropbox API error (HTTP ' . $status . '): ' . substr((string) $response, 0, 300));
        }

        $decoded = json_decode((string) $response, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Content-API-Aufrufe (Download/Thumbnail): Antwort-Body ist die rohe
     * Datei, nicht JSON -- Parameter kommen ueber den Dropbox-API-Arg-Header
     * statt im POST-Body.
     *
     * @param array<string, mixed> $args
     * @return array{0: string, 1: string} [content, contentType]
     */
    private function contentCall(string $endpoint, array $args): array
    {
        $token = $this->freshAccessToken();
        $ch = curl_init(self::CONTENT_BASE . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Dropbox-API-Arg: ' . json_encode($args, JSON_THROW_ON_ERROR),
            ],
        ]);
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if (0 !== $errno) {
            throw new \rex_exception('Dropbox content request failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new \rex_exception('Dropbox content API error (HTTP ' . $status . '): ' . substr((string) $response, 0, 300));
        }

        return [(string) $response, '' !== $contentType ? $contentType : 'application/octet-stream'];
    }
}
