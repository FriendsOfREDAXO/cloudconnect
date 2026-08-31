<?php

namespace FriendsOfRedaxo\CloudConnect\OAuth;

use FriendsOfRedaxo\CloudConnect\ConnectionStore;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;

/**
 * Gemeinsame OAuth2-Mechanik fuer OAuth-basierte Provider (Authorization-
 * Code-Flow per league/oauth2-client, aktuell nur Dropbox, siehe
 * DropboxClient) -- bewusst als generische Basisklasse gehalten, falls
 * spaeter ein weiterer OAuth2-Provider dazukommt. Token-Speicherung und
 * automatisches Erneuern ueber den Refresh-Token leben hier statt in der
 * konkreten Client-Klasse.
 *
 * Mehrere Verbindungen desselben Typs gleichzeitig moeglich (siehe DEV.md
 * "Mehrere Verbindungen pro Quelltyp"): `providerType()` bleibt eine feste,
 * typgebundene Konstante ("dropbox", steuert Endpunkte/Scopes + die -- pro
 * Typ fixe, nicht pro Verbindung variierende -- redirect_uri),
 * `connectionId` ist die eigentliche Storage-Kennung fuer Zugangsdaten/
 * Token (via ConnectionStore). Ohne explizite Angabe wird sie aus dem
 * laufenden Request aufgeloest -- noetig, weil MediaPlace Provider-Klassen
 * zero-arg instanziiert (StorageProviderRegistry::getInstance()).
 */
abstract class AbstractOAuthClient
{
    protected string $connectionId;

    public function __construct(?string $connectionId = null)
    {
        $this->connectionId = $connectionId ?? self::resolveConnectionIdFromRequest($this->providerType());
    }

    /** Fester Typ-Bezeichner ("dropbox") -- KEINE Storage-Kennung, siehe Klassenkopf. */
    abstract protected function providerType(): string;

    abstract protected function authorizeUrl(): string;

    abstract protected function tokenUrl(): string;

    /** @return list<string> */
    abstract protected function scopes(): array;

    /**
     * Menschenlesbarer Bezeichner des verbundenen Kontos (z.B. Anzeigename)
     * fuer die Settings-Seite -- vom OAuth-Callback direkt nach
     * completeAuthorization() aufgerufen, siehe Api\OAuthCallback.
     *
     * @return array{name: string}
     */
    abstract public function fetchAccountInfo(): array;

    /** Provider-spezifische Extra-Parameter fuer die Autorisierungs-URL (z.B. Dropbox' token_access_type=offline). */
    protected function extraAuthorizationParams(): array
    {
        return [];
    }

    private static function resolveConnectionIdFromRequest(string $providerType): string
    {
        $prefix = 'cloudconnect_' . $providerType . '_';
        $full = \rex_request('provider', 'string', '');
        return str_starts_with($full, $prefix) ? substr($full, strlen($prefix)) : '';
    }

    /** Oeffentlich, damit die Settings-Seite sie unverfaelscht anzeigen kann (zum Copy-Paste in die Provider-Konsole). */
    public function redirectUri(): string
    {
        // rex_url::backendController() liefert bewusst nur eine relative URL
        // (siehe Docblock von rex_url: "Utility class to generate relative
        // URLs") -- fuer redirect_uri wird aber eine vollstaendige, absolute
        // URL gebraucht: sie muss 1:1 in der Dropbox App Console hinterlegt
        // werden UND wird von deren Server als echtes HTTP-Redirect-Ziel
        // benutzt (kein Browser-Kontext, der eine relative URL aufloesen
        // koennte). league/oauth2-client validiert das beim Bauen der
        // Token-Request-URI und wirft sonst "You must provide a proper
        // URI with an authority or path component".
        //
        // Bewusst PRO TYP fix, nicht pro Verbindung: die URL muss 1:1 bei
        // Dropbox hinterlegt sein, kann also nicht dynamisch eine
        // Connection-ID enthalten -- welche Verbindung gerade autorisiert
        // wird, transportiert stattdessen die Session (siehe
        // rememberPendingConnection()/consumePendingConnectionId()).
        //
        // Bewusst der FRONTEND-Einstiegspunkt (/index.php), nicht
        // /redaxo/index.php -- rex_url bietet keine Moeglichkeit, den
        // Backend-Ordnernamen unabhaengig vom aktuellen Request-Kontext
        // aufzuloesen. Gleiches Muster wie z.B. issue_tracker's
        // NotificationService (rex::getServer() . 'index.php?rex-api-call=...'
        // fuer Links in E-Mails). WICHTIG: rex::getUser() ist in diesem
        // Kontext IMMER null (nur backend.php befuellt ihn, siehe
        // Api\OAuthCallback::execute() -- Absicherung laeuft daher
        // ausschliesslich ueber den state-Parameter/die Session, nicht ueber
        // einen Login-Check an dieser Stelle). Der Separator muss explizit
        // '&' sein: http_build_query() faellt sonst auf die PHP-ini-
        // Einstellung "arg_separator.output" zurueck, die in dieser Umgebung
        // tatsaechlich auf "&amp;" steht (Legacy-XHTML-Konvention) -- ohne
        // den dritten Parameter waere die redirect_uri kaputt (enthielte
        // woertlich "&amp;" statt "&").
        $query = http_build_query(['rex-api-call' => 'cloudconnect_oauth_callback', 'provider' => $this->providerType()], '', '&');
        return rtrim(\rex::getServer(), '/') . '/index.php?' . $query;
    }

    private function connection(): ?array
    {
        return ConnectionStore::get($this->connectionId);
    }

    protected function makeProvider(): GenericProvider
    {
        $config = $this->connection()['config'] ?? [];

        return new GenericProvider([
            'clientId' => (string) ($config['client_id'] ?? ''),
            'clientSecret' => (string) ($config['client_secret'] ?? ''),
            'redirectUri' => $this->redirectUri(),
            'urlAuthorize' => $this->authorizeUrl(),
            'urlAccessToken' => $this->tokenUrl(),
            'urlResourceOwnerDetails' => '',
            'scopes' => $this->scopes(),
            // RFC-6749-Standard ist ein Leerzeichen; league/oauth2-client's
            // GenericProvider faellt ohne diese explizite Angabe auf Komma
            // zurueck -- Dropbox erwartet Leerzeichen-getrennte Scopes.
            'scopeSeparator' => ' ',
        ]);
    }

    public function isConfigured(): bool
    {
        $connection = $this->connection();
        return null !== $connection && ConnectionStore::isComplete($connection);
    }

    public function isConnected(): bool
    {
        $connection = $this->connection();
        return null !== $connection && ConnectionStore::isConnected($connection);
    }

    /**
     * Startet den Verbindungsaufbau: State in der Session merken (CSRF-Schutz,
     * gleiches Prinzip wie ycom_auth_oauth2.php) und zusaetzlich die eigene
     * Connection-ID (der Redirect-URI selbst ist pro Typ fix, siehe
     * redirectUri()) -- der Callback (anderer Request, ohne Konstruktor-
     * Kontext) liest sie ueber consumePendingConnectionId() wieder aus.
     */
    public function buildAuthorizationUrl(): string
    {
        $provider = $this->makeProvider();
        $url = $provider->getAuthorizationUrl($this->extraAuthorizationParams());
        $this->rememberState($provider->getState());
        $this->rememberPendingConnection();
        return $url;
    }

    /**
     * Tauscht den von der OAuth-Callback-URL erhaltenen Code gegen ein
     * Access-/Refresh-Token, prueft vorher den State gegen den in
     * buildAuthorizationUrl() gemerkten Wert (CSRF-Schutz). Wirft
     * rex_exception bei ungueltigem State oder Fehlschlag des Tausches.
     */
    public function completeAuthorization(string $code, string $state): void
    {
        $expectedState = $this->consumeState();
        if ('' === $expectedState || !hash_equals($expectedState, $state)) {
            throw new \rex_exception('Invalid OAuth state (mögliche CSRF-Manipulation oder abgelaufene Sitzung).');
        }

        $provider = $this->makeProvider();
        $token = $provider->getAccessToken('authorization_code', ['code' => $code]);
        $this->persistToken($token);
    }

    public function disconnect(): void
    {
        ConnectionStore::updateConfig($this->connectionId, [
            'access_token' => '',
            'refresh_token' => '',
            'expires_at' => 0,
            'account_label' => '',
        ]);
    }

    /**
     * Liefert ein garantiert gueltiges Access-Token -- erneuert automatisch
     * ueber den Refresh-Token, wenn das gespeicherte abgelaufen ist (mit
     * 60s Puffer gegen Race Conditions waehrend eines laufenden Requests).
     * Wirft rex_exception, wenn (noch) keine Verbindung besteht.
     */
    protected function freshAccessToken(): string
    {
        $config = $this->connection()['config'] ?? null;
        $accessToken = (string) ($config['access_token'] ?? '');
        if (null === $config || '' === $accessToken) {
            throw new \rex_exception('Not connected to ' . $this->providerType() . ' yet.');
        }

        $expiresAt = (int) ($config['expires_at'] ?? 0);
        if ($expiresAt > time() + 60) {
            return $accessToken;
        }

        $refreshToken = (string) ($config['refresh_token'] ?? '');
        if ('' === $refreshToken) {
            throw new \rex_exception('Access token expired and no refresh token available -- reconnect required.');
        }

        $provider = $this->makeProvider();
        $newToken = $provider->getAccessToken('refresh_token', ['refresh_token' => $refreshToken]);
        $this->persistToken($newToken);

        return $newToken->getToken();
    }

    private function persistToken(AccessToken $token): void
    {
        $expiresAt = $token->getExpires();
        $fields = [
            'access_token' => (string) $token->getToken(),
            'expires_at' => null !== $expiresAt ? (int) $expiresAt : time() + 3600,
        ];
        // Manche Provider liefern bei einem Token-Refresh keinen neuen
        // refresh_token mehr mit (Dropbox z.B. nur beim allerersten Consent,
        // solange man denselben nicht widerruft) -- ein leerer Wert wuerde
        // dann den bisher gueltigen ueberschreiben. Nur setzen, wenn wirklich
        // ein neuer da ist.
        $newRefreshToken = (string) ($token->getRefreshToken() ?? '');
        if ('' !== $newRefreshToken) {
            $fields['refresh_token'] = $newRefreshToken;
        }
        ConnectionStore::updateConfig($this->connectionId, $fields);
    }

    private function sessionStateKey(): string
    {
        return 'cloudconnect_oauth_state_' . $this->providerType();
    }

    private function sessionPendingConnectionKey(): string
    {
        return 'cloudconnect_oauth_pending_connection_' . $this->providerType();
    }

    private function rememberState(string $state): void
    {
        self::ensureSession();
        $_SESSION[$this->sessionStateKey()] = $state;
    }

    private function consumeState(): string
    {
        self::ensureSession();
        $state = (string) ($_SESSION[$this->sessionStateKey()] ?? '');
        unset($_SESSION[$this->sessionStateKey()]);
        return $state;
    }

    private function rememberPendingConnection(): void
    {
        self::ensureSession();
        $_SESSION[$this->sessionPendingConnectionKey()] = $this->connectionId;
    }

    /**
     * Statisch aufrufbar, weil Api\OAuthCallback die Connection-ID braucht,
     * BEVOR ueberhaupt eine Client-Instanz existiert (die ID entscheidet ja
     * erst, mit welcher Connection-ID der Client konstruiert wird).
     */
    public static function consumePendingConnectionId(string $providerType): string
    {
        self::ensureSession();
        $key = 'cloudconnect_oauth_pending_connection_' . $providerType;
        $id = (string) ($_SESSION[$key] ?? '');
        unset($_SESSION[$key]);
        return $id;
    }

    private static function ensureSession(): void
    {
        if (PHP_SESSION_NONE === session_status()) {
            session_start();
        }
    }
}
