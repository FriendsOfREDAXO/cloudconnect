<?php

namespace FriendsOfRedaxo\CloudConnect\Api;

use FriendsOfRedaxo\CloudConnect\ConnectionStore;
use FriendsOfRedaxo\CloudConnect\OAuth\AbstractOAuthClient;
use FriendsOfRedaxo\CloudConnect\OAuth\DropboxClient;
use rex_api_function;
use rex_api_result;

/**
 * OAuth2-Redirect-Endpunkt fuer Dropbox (?provider=dropbox) -- Dropbox
 * leitet den Browser nach dem Nutzer-Consent hierher zurueck (siehe
 * AbstractOAuthClient::redirectUri(), exakt dieselbe URL wird auch in der
 * Settings-Seite als "in der Developer-Konsole einzutragende Redirect-URI"
 * angezeigt).
 *
 * $published=true, weil dies ein echter Browser-Redirect ist (kein
 * fetch()-Aufruf aus dem laufenden Backend heraus): die Redirect-URI zeigt
 * bewusst auf den FRONTEND-Einstiegspunkt (/index.php, siehe
 * AbstractOAuthClient::redirectUri()), weil rex_url keine Moeglichkeit
 * bietet, den Backend-Ordnernamen kontextunabhaengig aufzuloesen. In diesem
 * Kontext liefert rex::getUser() IMMER null -- rex_backend_login (und damit
 * die Befuellung von rex::getUser()) laeuft ausschliesslich in backend.php,
 * niemals in frontend.php. Ein direkter Login-Check hier waere deshalb
 * dauerhaft falsch-negativ (jede Verbindung wuerde mit 403 scheitern, bevor
 * code/state ueberhaupt geprueft werden). Die eigentliche Absicherung
 * passiert stattdessen ueber den state-Parameter (siehe
 * AbstractOAuthClient::completeAuthorization()/rememberState(),
 * sessiongebunden, Standard-OAuth2-CSRF-Schutz): nur ein Browser mit der
 * exakt gleichen Session, in der zuvor ein eingeloggter Admin
 * buildAuthorizationUrl() aufgerufen hat (nur von der admin-only
 * Settings-Seite erreichbar), kennt den passenden state-Wert. Die
 * Session-Cookie-Konfiguration dieses Projekts (session.cookie_path=/,
 * Standard-Session-Name) teilt sich das PHPSESSID-Cookie ohnehin ueber
 * Frontend- und Backend-Einstiegspunkt hinweg.
 */
class OAuthCallback extends rex_api_function
{
    protected $published = true;

    public function execute(): rex_api_result
    {
        $providerKey = \rex_request('provider', 'string', '');
        $code = \rex_request('code', 'string', '');
        $state = \rex_request('state', 'string', '');
        $oauthError = \rex_request('error_description', 'string', \rex_request('error', 'string', ''));

        // escape=false: die URL wird unten noch um weitere Query-Parameter
        // ergaenzt und dann als echter HTTP-Redirect verschickt, nicht in
        // HTML eingebettet -- HTML-Entity-Escaping (& -> &amp;) wuerde die
        // Location-Header-URL sonst kaputt machen.
        $settingsUrl = \rex_url::backendPage('cloudconnect/settings', [], false);

        if ('' !== $oauthError) {
            $this->redirectWithStatus($settingsUrl, 'error', $oauthError);
        }

        // Die Connection-ID transportiert die Session (siehe
        // AbstractOAuthClient::rememberPendingConnection()) -- der
        // Redirect-URI selbst ist pro Typ fix und kann sie nicht enthalten
        // (muss 1:1 bei Dropbox hinterlegt sein).
        $connectionId = AbstractOAuthClient::consumePendingConnectionId($providerKey);

        $client = $this->resolveClient($providerKey, $connectionId);
        if (null === $client || '' === $connectionId || '' === $code || '' === $state) {
            $this->redirectWithStatus($settingsUrl, 'error', 'Invalid OAuth callback request.');
        }

        try {
            $client->completeAuthorization($code, $state);
            $account = $client->fetchAccountInfo();
            ConnectionStore::updateConfig($connectionId, ['account_label' => (string) ($account['name'] ?? '')]);
        } catch (\Throwable $e) {
            $this->redirectWithStatus($settingsUrl, 'error', $e->getMessage());
        }

        $this->redirectWithStatus($settingsUrl, 'connected', $providerKey);
    }

    private function resolveClient(string $providerKey, string $connectionId): ?AbstractOAuthClient
    {
        return match ($providerKey) {
            'dropbox' => new DropboxClient($connectionId),
            default => null,
        };
    }

    private function redirectWithStatus(string $baseUrl, string $status, string $message): never
    {
        $separator = str_contains($baseUrl, '?') ? '&' : '?';
        \rex_response::sendRedirect($baseUrl . $separator . 'cloudconnect_status=' . rawurlencode($status) . '&cloudconnect_message=' . rawurlencode($message));
        exit;
    }
}
